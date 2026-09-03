<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\FacetIndexWriter;

/**
 * The Scolta facet index: the artifact that lets the browser answer facet
 * questions without loading a single Pagefind filter chunk.
 *
 * The committed fixture these tests assert against is the same file
 * tests/js/facet-index-parse.test.js parses, so the PHP encoder and the
 * JavaScript decoder are pinned to one another: change the format on one side
 * and one of the two suites fails immediately.
 */
class FacetIndexWriterTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../js/fixtures/facet-index.fixture';

    private FacetIndexWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new FacetIndexWriter();
    }

    /**
     * 200 pages, three dimensions, deliberately covering both posting encodings:
     * a three-page value is smaller as varint deltas, a 197-page one is smaller
     * as a bitmap.
     *
     * @return array{0: array<string, array<string, int[]>>, 1: array<int, string>}
     */
    public static function fixtureData(): array
    {
        $pageHashes = [];
        for ($i = 0; $i < 200; $i++) {
            $pageHashes[$i] = 'en_' . substr(hash('sha256', "page-{$i}"), 0, 10);
        }

        $veg   = range(3, 199);
        $even  = [];
        $odd   = [];
        for ($i = 0; $i < 200; $i++) {
            if ($i % 2 === 0) {
                $even[] = $i;
            } else {
                $odd[] = $i;
            }
        }

        $filterData = [
            'topic' => ['Fruit' => [0, 1, 2], 'Veg' => $veg],
            'level' => ['Beginner' => $even, 'Advanced' => $odd],
            // One distinct value across every page. Kept in the artifact, because
            // Scolta can still be asked to apply it, and dropped only from
            // Pagefind's own chunks.
            'site'  => ['OneSite' => range(0, 199)],
        ];

        return [$filterData, $pageHashes];
    }

    private function header(string $artifact): array
    {
        $newline = strpos($artifact, "\n");
        $this->assertNotFalse($newline, 'artifact must open with a JSON header line');
        $header = json_decode(substr($artifact, 0, $newline), true);
        $this->assertIsArray($header);
        return $header;
    }

    public function testHeaderDeclaresFormatVersionAndCorpusSize(): void
    {
        [$filterData, $pageHashes] = self::fixtureData();
        $header = $this->header($this->writer->build($filterData, $pageHashes, 'en_abcdef1234'));

        $this->assertSame('scolta-facets', $header['format']);
        $this->assertSame(1, $header['version']);
        $this->assertSame('en_abcdef1234', $header['indexHash']);
        $this->assertSame(200, $header['pageCount']);
        $this->assertSame(['topic', 'level', 'site'], $header['dimensions']);
    }

    public function testValueTotalsAreThePostingListLengths(): void
    {
        // This is the promise that keeps facet counts from moving: Pagefind
        // reports a value's unfiltered total as its posting-list length, and the
        // header has to say exactly the same number.
        [$filterData, $pageHashes] = self::fixtureData();
        $header = $this->header($this->writer->build($filterData, $pageHashes));

        $this->assertSame([['Fruit', 3], ['Veg', 197]], $header['values']['topic']);
        $this->assertSame([['Beginner', 100], ['Advanced', 100]], $header['values']['level']);
        $this->assertSame([['OneSite', 200]], $header['values']['site']);
    }

    public function testSingleValueDimensionIsStillCarried(): void
    {
        // The guard belongs on Pagefind's chunks, not here: AUTO_LANGUAGE_FILTER
        // applies `language`, and applying a filter needs its posting list even
        // when the facet panel hides the dimension.
        [$filterData, $pageHashes] = self::fixtureData();
        $header = $this->header($this->writer->build($filterData, $pageHashes));

        $this->assertContains('site', $header['dimensions']);
        $this->assertSame([['OneSite', 200]], $header['values']['site']);
    }

    public function testIdTableIsOneFragmentHashPerPageInPageOrder(): void
    {
        [$filterData, $pageHashes] = self::fixtureData();
        $artifact = $this->writer->build($filterData, $pageHashes);

        $lines = explode("\n", $artifact);
        // Line 0 is the header; lines 1..200 are the id table.
        $this->assertSame($pageHashes[0], $lines[1]);
        $this->assertSame($pageHashes[199], $lines[200]);
    }

    public function testPostingsUseWhicheverEncodingIsSmaller(): void
    {
        [$filterData, $pageHashes] = self::fixtureData();
        $artifact = $this->writer->build($filterData, $pageHashes);

        // Skip the header and the id table to reach the first posting body.
        $offset = strpos($artifact, "\n") + 1;
        for ($i = 0; $i < 200; $i++) {
            $offset = strpos($artifact, "\n", $offset) + 1;
        }

        // topic/Fruit: 3 of 200 pages. Varints (5 bytes) beat a 25-byte bitmap.
        $this->assertSame(0, ord($artifact[$offset]), 'a sparse value must be varint encoded');
        $offset += 5;
        // topic/Veg: 197 of 200 pages. The bitmap wins.
        $this->assertSame(1, ord($artifact[$offset]), 'a dense value must be bitmap encoded');
    }

    public function testMissingPageInTheTableIsRefusedRatherThanMisnumbered(): void
    {
        // Posting lists are page indices into the id table. A hole would silently
        // shift every id after it onto the wrong page.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contiguous page table');
        $this->writer->build(['d' => ['v' => [0]]], [0 => 'en_aaaaaaaaaa', 2 => 'en_bbbbbbbbbb']);
    }

    public function testWriteProducesAGzippedArtifactInTheIndexDirectory(): void
    {
        [$filterData, $pageHashes] = self::fixtureData();
        $dir = sys_get_temp_dir() . '/scolta-facet-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $this->writer->write($dir, $filterData, $pageHashes, 'en_1234567890');
            $path = $dir . '/' . FacetIndexWriter::filename('en_1234567890');
            $this->assertFileExists($path);

            $raw = gzdecode((string) file_get_contents($path));
            $this->assertNotFalse($raw, 'the artifact must be gzipped');
            $this->assertStringStartsWith('{"format":"scolta-facets"', $raw);
        } finally {
            @unlink($dir . '/' . FacetIndexWriter::filename('en_1234567890'));
            @rmdir($dir);
        }
    }

    public function testEmptyCorpusProducesAReadableArtifact(): void
    {
        $header = $this->header($this->writer->build([], []));
        $this->assertSame(0, $header['pageCount']);
        $this->assertSame([], $header['dimensions']);
    }

    public function testFixtureMatchesTheCommittedBytes(): void
    {
        // Pins the encoder to the fixture the JavaScript decoder test reads. If
        // this fails, the format changed: regenerate the fixture and make sure
        // the decoder still agrees.
        //
        // Compared after decompression, deliberately. gzip records the producing
        // OS in byte 9 of its header (0x03 on Linux, 0x13 on macOS), so the
        // envelope is not byte-stable across platforms even when the deflate
        // payload is identical. The artifact format is the payload.
        [$filterData, $pageHashes] = self::fixtureData();
        $this->assertFileExists(self::FIXTURE, 'the shared facet index fixture is missing');
        $fixture = gzdecode((string) file_get_contents(self::FIXTURE));
        $this->assertNotFalse($fixture, 'the committed fixture must be gzipped');
        $this->assertSame(
            $fixture,
            $this->writer->build($filterData, $pageHashes, 'en_fixture01'),
            'the committed fixture no longer matches what FacetIndexWriter produces',
        );
    }
}
