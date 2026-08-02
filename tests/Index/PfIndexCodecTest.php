<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\CborDecoder;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\PfIndexCodec;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * The round trip that the whole incremental design rests on.
 *
 * Updating one page in place means reading a written `pf_index` chunk, changing
 * the postings for one ordinal, and writing it back. That is only sound if
 * decode followed by re-encode is the identity on bytes — otherwise every
 * touched chunk drifts a little on each update and the index slowly stops
 * matching what a full rebuild would produce.
 */
#[CoversClass(PfIndexCodec::class)]
#[CoversClass(CborDecoder::class)]
final class PfIndexCodecTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir  = sys_get_temp_dir() . '/scolta-codec-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-codec-out-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->stateDir);
        self::removeDir($this->outputDir);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @return string[] Paths of the built pf_index chunks.
     */
    private function buildIndex(int $pages): array
    {
        $items   = SyntheticCorpus::generate($pages, seed: 11);
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        foreach (array_chunk($items, 50, true) as $i => $chunk) {
            $indexer->processChunk($chunk, $i, count($items));
        }
        $result = $indexer->finalize();
        $this->assertTrue($result->success, 'Fixture build failed: ' . ($result->error ?? ''));

        $chunks = glob($this->outputDir . '/pagefind/index/*.pf_index') ?: [];
        $this->assertNotEmpty($chunks, 'Fixture build produced no index chunks.');

        return $chunks;
    }

    private static function chunkBody(string $path): string
    {
        $raw = gzdecode((string) file_get_contents($path));
        self::assertIsString($raw);

        return str_starts_with($raw, 'pagefind_dcd') ? substr($raw, strlen('pagefind_dcd')) : $raw;
    }

    public function testDecodeThenEncodeIsByteIdenticalForEveryChunk(): void
    {
        $cbor   = new CborEncoder();
        $chunks = $this->buildIndex(300);

        $terms = 0;
        foreach ($chunks as $path) {
            $original = self::chunkBody($path);
            $decoded  = PfIndexCodec::decodeChunk($original);
            $terms   += count($decoded);

            $this->assertSame(
                bin2hex($original),
                bin2hex(PfIndexCodec::encodeChunk($cbor, $decoded)),
                'Round trip changed the bytes of ' . basename($path),
            );
        }

        $this->assertGreaterThan(100, $terms, 'Fixture too small to be meaningful.');
    }

    public function testChunkHashMatchesTheFilenameTheWriterChose(): void
    {
        foreach ($this->buildIndex(300) as $path) {
            $decoded = PfIndexCodec::decodeChunk(self::chunkBody($path));

            $this->assertSame(
                basename($path, '.pf_index'),
                PfIndexCodec::chunkHash($decoded),
                'Codec and writer disagree on chunk identity.',
            );
        }
    }

    public function testDecodedTermOrderIsFileOrderNotSortedOrder(): void
    {
        // The filename hashes the joined word list, so any reordering on the
        // way in would rename the chunk on the way out even with no content
        // change. Pin that decode preserves order rather than re-sorting.
        $chunks  = $this->buildIndex(300);
        $decoded = PfIndexCodec::decodeChunk(self::chunkBody($chunks[0]));
        $words   = PfIndexCodec::wordList($decoded);

        $this->assertNotSame([], $words);
        $this->assertSame(array_values(array_unique($words)), $words, 'Duplicate terms in one chunk.');
    }

    public function testPositionsAndVariantsSurviveTheRoundTrip(): void
    {
        $cbor = new CborEncoder();
        $terms = [
            'alpha' => [
                3  => ['positions' => [25 => [0, 4, 9]], 'meta_positions' => [1]],
                17 => ['positions' => [25 => [2]], 'meta_positions' => []],
            ],
            'beta' => [
                3          => ['positions' => [], 'meta_positions' => [0, 5]],
                '_variants' => ['bêta' => [3, 17]],
            ],
        ];

        $decoded = PfIndexCodec::decodeChunk(PfIndexCodec::encodeChunk($cbor, $terms));

        $this->assertSame([0, 4, 9], $decoded['alpha'][3]['positions'][25]);
        $this->assertSame([1], $decoded['alpha'][3]['meta_positions']);
        $this->assertSame([], $decoded['beta'][3]['positions'], 'No body positions must decode to an empty bucket list.');
        $this->assertSame([0, 5], $decoded['beta'][3]['meta_positions']);
        $this->assertSame(['bêta' => [3, 17]], $decoded['beta']['_variants']);
    }

    public function testDecoderRejectsTruncatedData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Truncated CBOR data');

        CborDecoder::decode("\x83\x63abc");
    }

    public function testDecoderRejectsTrailingData(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trailing CBOR data');

        CborDecoder::decode("\x01\x01");
    }
}
