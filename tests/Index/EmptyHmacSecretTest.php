<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\ChunkReader;
use Tag1\Scolta\Index\ChunkWriter;
use Tag1\Scolta\Index\HmacSecret;

/**
 * An empty or whitespace-only HMAC secret means "unset", not "crash".
 *
 * `hash_init('sha256', HASH_HMAC, '')` throws a ValueError, so before this the
 * `!== null` guards in ChunkWriter, ChunkReader and BuildState turned an
 * unconfigured framework secret into a stack trace mid-build. The guards also
 * had to move together: fixing only the writer would leave the reader
 * demanding a tag from a chunk written without one, which fails the build a
 * second way rather than the first.
 */
class EmptyHmacSecretTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-empty-hmac-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        rmdir($this->tmpDir);
    }

    private function makePartial(): array
    {
        return [
            'pages' => [
                0 => ['url' => '/a', 'wordCount' => 10, 'content' => 'hello world', 'meta' => ['title' => 'A'], 'filters' => []],
                1 => ['url' => '/b', 'wordCount' => 5, 'content' => 'foo bar', 'meta' => ['title' => 'B'], 'filters' => []],
            ],
            'index' => [
                'hello' => [0 => ['positions' => [25 => [1]], 'meta_positions' => []]],
                'world' => [0 => ['positions' => [25 => [2]], 'meta_positions' => []]],
                'foo'   => [1 => ['positions' => [25 => [1]], 'meta_positions' => [0]]],
            ],
        ];
    }

    /** @return array<string, array{0: string|null}> */
    public static function unsetSecretProvider(): array
    {
        return [
            'null'             => [null],
            'empty string'     => [''],
            'single space'     => [' '],
            'whitespace only'  => ["  \t\n  "],
        ];
    }

    /**
     * The reported crash: write() with `''` threw
     * ValueError: hash_init(): Argument #3 ($key) must not be empty when HMAC
     * is requested. Nothing may throw for any spelling of "unset".
     *
     * @dataProvider unsetSecretProvider
     */
    public function testWriteWithUnsetSecretDoesNotThrow(?string $secret): void
    {
        $path = $this->tmpDir . '/chunk-000.dat';

        (new ChunkWriter())->write($path, $this->makePartial(), $secret);

        $this->assertFileExists($path);
    }

    /**
     * Explicit regression guard on the exact exception class, kept separate
     * from the round-trip assertions so a failure names the crash directly.
     */
    public function testEmptySecretRaisesNoValueError(): void
    {
        $path = $this->tmpDir . '/chunk-000.dat';

        try {
            (new ChunkWriter())->write($path, $this->makePartial(), '');
        } catch (\ValueError $e) {
            $this->fail('write() with an empty secret threw ValueError: ' . $e->getMessage());
        }

        $this->assertFileExists($path);
    }

    /**
     * Round trip: every unset spelling produces a chunk whose pages and terms
     * read back byte-for-byte, verifiable with the same unset secret.
     *
     * @dataProvider unsetSecretProvider
     */
    public function testUnsetSecretRoundTrips(?string $secret): void
    {
        $partial = $this->makePartial();
        $path    = $this->tmpDir . '/chunk-000.dat';

        (new ChunkWriter())->write($path, $partial, $secret);

        $pages = iterator_to_array((new ChunkReader($path))->openPages());
        $terms = [];
        foreach ((new ChunkReader($path))->openIndex() as [$term, $data]) {
            $terms[$term] = $data;
        }

        $this->assertEquals($partial['pages'], $pages);
        // write() ksorts terms for the streaming merge, so compare sorted.
        $expectedTerms = array_keys($partial['index']);
        sort($expectedTerms);
        $this->assertEquals($expectedTerms, array_keys($terms));
        $this->assertEquals($partial['index']['hello'], $terms['hello']);

        $digests = (new ChunkReader($path))->verifyFooterDigests($secret);
        $this->assertNull($digests['hmac'], 'An unset secret makes the HMAC verdict not-applicable, not a failure');
        $this->assertTrue($digests['crc32'], 'CRC32 is computed regardless of the HMAC secret');
    }

    /**
     * A real secret must still tag and still verify — the normalisation must
     * not have widened into "never tag anything".
     */
    public function testRealSecretRoundTripsAndStillTags(): void
    {
        $partial = $this->makePartial();
        $path    = $this->tmpDir . '/chunk-000.dat';

        (new ChunkWriter())->write($path, $partial, 'a-real-secret');

        $digests = (new ChunkReader($path))->verifyFooterDigests('a-real-secret');
        $this->assertTrue($digests['hmac']);
        $this->assertTrue($digests['crc32']);
        $this->assertTrue((new ChunkReader($path))->verifyHmac('a-real-secret'));
        $this->assertFalse((new ChunkReader($path))->verifyHmac('a-different-secret'));
    }

    /**
     * A secret with real content beside whitespace is a real secret. Only
     * fully-blank values normalise away, and the value is used as given rather
     * than trimmed, so an existing tagged chunk keeps verifying.
     */
    public function testSecretWithSurroundingWhitespaceIsNotUnset(): void
    {
        $partial = $this->makePartial();
        $path    = $this->tmpDir . '/chunk-000.dat';

        (new ChunkWriter())->write($path, $partial, ' padded-secret ');

        $this->assertTrue((new ChunkReader($path))->verifyFooterDigests(' padded-secret ')['hmac']);
        $this->assertFalse(
            (new ChunkReader($path))->verifyFooterDigests('padded-secret')['hmac'],
            'The secret is used verbatim, not trimmed, so the untrimmed form is a different key',
        );
    }

    /**
     * The writer wrote no tag, so a caller who does have a secret gets a
     * mismatch rather than a false pass. Absence of a tag is not integrity.
     *
     * @dataProvider unsetSecretProvider
     */
    public function testChunkWrittenWithUnsetSecretCarriesNoTag(?string $secret): void
    {
        $path = $this->tmpDir . '/chunk-000.dat';

        (new ChunkWriter())->write($path, $this->makePartial(), $secret);

        $this->assertFalse(
            (new ChunkReader($path))->verifyFooterDigests('some-real-secret')['hmac'],
            'A chunk written without a tag must not verify against any secret',
        );
    }

    /**
     * verifyHmac() keeps its non-nullable string signature (it is
     * @stability stable), so an unset secret participates by returning false:
     * there is nothing to verify, and "not applicable" must never collapse
     * into "verified" for a caller using this as a gate.
     */
    public function testVerifyHmacReturnsFalseForUnsetSecretWithoutThrowing(): void
    {
        $path = $this->tmpDir . '/chunk-000.dat';
        (new ChunkWriter())->write($path, $this->makePartial(), '');

        $this->assertFalse((new ChunkReader($path))->verifyHmac(''));
        $this->assertFalse((new ChunkReader($path))->verifyHmac('   '));

        // Also false on a chunk that does carry a tag: the question "does this
        // verify against no secret" has no affirmative answer.
        $tagged = $this->tmpDir . '/chunk-001.dat';
        (new ChunkWriter())->write($tagged, $this->makePartial(), 'a-real-secret');
        $this->assertFalse((new ChunkReader($tagged))->verifyHmac(''));
    }

    /**
     * The second failure mode from the issue: with the writer fixed alone,
     * BuildState::readChunk() would still evaluate `'' !== null` as true,
     * demand `hmac === true` from a chunk carrying no tag, and throw
     * "HMAC verification failed" on its own freshly written chunk.
     *
     * @dataProvider unsetSecretProvider
     */
    public function testBuildStateDoesNotDemandTagForUnsetSecret(?string $secret): void
    {
        $state = new BuildState($this->tmpDir, $secret);
        $state->initiateBuild(['total_pages' => 2]);

        $data = [
            'pages' => [0 => ['url' => '/a', 'wordCount' => 3, 'content' => '', 'meta' => [], 'filters' => []]],
            'index' => ['hello' => [0 => ['positions' => [25 => [1]], 'meta_positions' => []]]],
        ];
        $state->recordChunk(0, $data);

        $read = $state->readChunk(0);

        $this->assertEquals($data['pages'], $read['pages']);
        $this->assertEquals($data['index'], $read['index']);
    }

    /**
     * A configured secret must still be enforced through BuildState, including
     * against a chunk written by a build that had none.
     */
    public function testBuildStateStillRejectsMissingTagWhenSecretIsConfigured(): void
    {
        $unset = new BuildState($this->tmpDir, '');
        $unset->initiateBuild(['total_pages' => 1]);
        $unset->recordChunk(0, ['pages' => [], 'index' => ['word' => []]]);

        $configured = new BuildState($this->tmpDir, 'a-real-secret');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HMAC verification failed');
        $configured->readChunk(0);
    }

    /** @dataProvider unsetSecretProvider */
    public function testNormalizeReducesUnsetSpellingsToNull(?string $secret): void
    {
        $this->assertNull(HmacSecret::normalize($secret));
    }

    public function testNormalizeReturnsRealSecretsVerbatim(): void
    {
        $this->assertSame('a-real-secret', HmacSecret::normalize('a-real-secret'));
        $this->assertSame(' padded ', HmacSecret::normalize(' padded '));
        $this->assertSame('0', HmacSecret::normalize('0'), '"0" is falsy in PHP but is real key material');
    }

    public function testNormalizeIsIdempotent(): void
    {
        $this->assertNull(HmacSecret::normalize(HmacSecret::normalize('')));
        $this->assertSame('s', HmacSecret::normalize(HmacSecret::normalize('s')));
    }
}
