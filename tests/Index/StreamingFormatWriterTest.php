<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\ChunkWriter;
use Tag1\Scolta\Index\IndexMerger;
use Tag1\Scolta\Index\InvertedIndexBuilder;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\StreamingFormatWriter;
use Tag1\Scolta\Index\Tokenizer;
use Tag1\Scolta\Tests\Support\CborDecoder;

class StreamingFormatWriterTest extends TestCase
{
    private string $tmpDir;
    private string $chunkDir;
    private StreamingFormatWriter $writer;

    protected function setUp(): void
    {
        $this->tmpDir   = sys_get_temp_dir() . '/scolta-sfwt-' . uniqid();
        $this->chunkDir = sys_get_temp_dir() . '/scolta-sfwt-chunks-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        mkdir($this->chunkDir, 0755, true);
        $this->writer = new StreamingFormatWriter(new CborEncoder());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        $this->removeDir($this->chunkDir);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function writeSingleChunkAndRun(array $pages, array $index = []): void
    {
        $path = $this->chunkDir . '/chunk-000.dat';
        (new ChunkWriter())->write($path, ['pages' => $pages, 'index' => $index]);

        $this->writer->beginWrite($this->tmpDir);
        (new IndexMerger())->mergeStreaming([$path], $this->writer);
        $this->writer->endWrite();
    }

    private function decodeMeta(): array
    {
        $metaFiles = glob($this->tmpDir . '/.scolta-building/pagefind.*.pf_meta');
        $this->assertCount(1, $metaFiles, 'Expected exactly one pf_meta file');

        return CborDecoder::decodePfFile($metaFiles[0]);
    }

    private function makePage(string $url, string $title, array $sortable = [], array $extraMeta = []): array
    {
        $meta = array_merge(['title' => $title], $sortable, $extraMeta);

        return [
            'url'       => $url,
            'content'   => "Content for {$title}",
            'wordCount' => 10,
            'filters'   => [],
            'meta'      => $meta,
            'sortable'  => $sortable,
        ];
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    public function testWritePageAccumulatesSortableData(): void
    {
        $pages = [
            0 => $this->makePage('/a', 'A', ['price' => '10.00']),
            1 => $this->makePage('/b', 'B', ['price' => '5.00']),
        ];

        $this->writeSingleChunkAndRun($pages);

        $decoded = $this->decodeMeta();
        $sorts   = $decoded[4];

        $this->assertCount(1, $sorts, 'Sort data must be accumulated from writePage()');
        $this->assertSame('price', $sorts[0][0]);
    }

    public function testSortsArraySingleFieldAllPagesHaveValues(): void
    {
        // price: p0=42.99, p1=12.50, p2=99.00 → ascending: p1, p0, p2
        $pages = [
            0 => $this->makePage('/a', 'A', ['price' => '42.99']),
            1 => $this->makePage('/b', 'B', ['price' => '12.50']),
            2 => $this->makePage('/c', 'C', ['price' => '99.00']),
        ];

        $this->writeSingleChunkAndRun($pages);
        $decoded = $this->decodeMeta();

        $sorts = $decoded[4];
        $this->assertCount(1, $sorts);
        $this->assertSame('price', $sorts[0][0]);
        $this->assertSame([1, 0, 2], $sorts[0][1]);
    }

    public function testSortsArrayMixedPresenceSomePagesLackSortField(): void
    {
        // p0 has price, p1 does not, p2 has price → ascending: p2 (12.50), p0 (42.99)
        $pages = [
            0 => $this->makePage('/a', 'A', ['price' => '42.99']),
            1 => $this->makePage('/b', 'B', []),
            2 => $this->makePage('/c', 'C', ['price' => '12.50']),
        ];

        $this->writeSingleChunkAndRun($pages);
        $decoded = $this->decodeMeta();

        $sorts = $decoded[4];
        $this->assertCount(1, $sorts);
        $this->assertSame('price', $sorts[0][0]);
        $this->assertSame([2, 0], $sorts[0][1]);
    }

    public function testSortsArrayMultipleSortFields(): void
    {
        $pages = [
            0 => $this->makePage('/a', 'A', ['price' => '10.00', 'rating' => '4.5']),
            1 => $this->makePage('/b', 'B', ['price' => '20.00', 'rating' => '3.0']),
        ];

        $this->writeSingleChunkAndRun($pages);
        $decoded = $this->decodeMeta();

        $sorts = $decoded[4];
        $this->assertCount(2, $sorts);

        $byField = [];
        foreach ($sorts as $entry) {
            $byField[$entry[0]] = $entry[1];
        }

        $this->assertArrayHasKey('price', $byField);
        $this->assertArrayHasKey('rating', $byField);
        $this->assertSame([0, 1], $byField['price']);   // 10.00 < 20.00
        $this->assertSame([1, 0], $byField['rating']);  // 3.0 < 4.5
    }

    public function testNumericSortIsNotLexicographic(): void
    {
        // Lexicographic: '100' < '20' < '9'. Numeric: 9 < 20 < 100.
        $pages = [
            0 => $this->makePage('/a', 'A', ['price' => '9']),
            1 => $this->makePage('/b', 'B', ['price' => '100']),
            2 => $this->makePage('/c', 'C', ['price' => '20']),
        ];

        $this->writeSingleChunkAndRun($pages);
        $decoded = $this->decodeMeta();

        $sorts = $decoded[4];
        $this->assertSame('price', $sorts[0][0]);
        $this->assertSame([0, 2, 1], $sorts[0][1]); // 9, 20, 100
    }

    public function testStringSortIsLexicographic(): void
    {
        $pages = [
            0 => $this->makePage('/a', 'A', ['category' => 'Rings']),
            1 => $this->makePage('/b', 'B', ['category' => 'Bracelets']),
            2 => $this->makePage('/c', 'C', ['category' => 'Necklaces']),
        ];

        $this->writeSingleChunkAndRun($pages);
        $decoded = $this->decodeMeta();

        $sorts = $decoded[4];
        $this->assertSame('category', $sorts[0][0]);
        $this->assertSame([1, 2, 0], $sorts[0][1]); // Bracelets, Necklaces, Rings
    }

    public function testNoSortFieldsProducesEmptySortsArray(): void
    {
        $pages = [
            0 => $this->makePage('/a', 'A'),
            1 => $this->makePage('/b', 'B'),
        ];

        $this->writeSingleChunkAndRun($pages);
        $decoded = $this->decodeMeta();

        $this->assertSame([], $decoded[4], 'No sortable fields must produce empty sorts array');
    }

    public function testFragmentMetaIncludesSortFieldValues(): void
    {
        $pages = [
            0 => $this->makePage('/ring', 'Amethyst Ring', ['price' => '42.99']),
        ];

        $this->writeSingleChunkAndRun($pages);

        $fragmentFiles = glob($this->tmpDir . '/.scolta-building/fragment/*.pf_fragment');
        $this->assertCount(1, $fragmentFiles);

        $json = preg_replace('/^pagefind_dcd/', '', gzdecode(file_get_contents($fragmentFiles[0])));
        $data = json_decode($json, true);

        $this->assertSame('/ring', $data['url']);
        $this->assertSame('42.99', $data['meta']['price']);
        $this->assertSame('Amethyst Ring', $data['meta']['title']);
    }

    public function testMetaFieldsIncludesSortFieldNames(): void
    {
        $pages = [
            0 => $this->makePage('/a', 'A', ['price' => '42.99']),
        ];

        $this->writeSingleChunkAndRun($pages);
        $decoded = $this->decodeMeta();

        $metaFields = $decoded[5];
        $this->assertContains('title', $metaFields);
        $this->assertContains('price', $metaFields);
        $this->assertNotContains('url', $metaFields, 'url must not appear in meta_fields');
    }

    public function testEndToEndSortableFieldsFlowFromContentItemToIndex(): void
    {
        $builder = new InvertedIndexBuilder(new Tokenizer(), new Stemmer('en'));

        $items = [
            new ContentItem(
                id: 'p1',
                title: 'Amethyst Ring',
                bodyHtml: '<p>Beautiful purple gemstone ring, elegant and affordable jewelry item.</p>',
                url: 'https://example.com/amethyst',
                date: '2026-01-01',
                sortable: ['price' => '42.99'],
            ),
            new ContentItem(
                id: 'p2',
                title: 'Sapphire Pendant',
                bodyHtml: '<p>Deep blue sapphire pendant, a stunning necklace for special occasions.</p>',
                url: 'https://example.com/sapphire',
                date: '2026-01-02',
                sortable: ['price' => '12.50'],
            ),
            new ContentItem(
                id: 'p3',
                title: 'Ruby Bracelet',
                bodyHtml: '<p>Vibrant red ruby bracelet, perfect for anniversaries and celebrations.</p>',
                url: 'https://example.com/ruby',
                date: '2026-01-03',
                sortable: ['price' => '99.00'],
            ),
        ];

        $partial  = $builder->buildFromTokenData(
            array_map(fn (ContentItem $item) => [
                'item'      => (object) [
                    'id'       => $item->id,
                    'url'      => $item->url,
                    'date'     => $item->date,
                    'siteName' => null,
                    'language' => 'en',
                    'filters'  => [],
                    'sortable' => $item->sortable,
                ],
                'tokenData' => $builder->tokenizeItem($item),
            ], $items),
            0,
        );

        $chunkPath = $this->chunkDir . '/chunk-000.dat';
        (new ChunkWriter())->write($chunkPath, $partial);

        $this->writer->beginWrite($this->tmpDir);
        (new IndexMerger())->mergeStreaming([$chunkPath], $this->writer);
        $this->writer->endWrite();

        // pf_meta[4] sorts: ascending price → sapphire=12.50 (p1), amethyst=42.99 (p0), ruby=99.00 (p2)
        $decoded = $this->decodeMeta();
        $sorts   = $decoded[4];
        $this->assertCount(1, $sorts);
        $this->assertSame('price', $sorts[0][0]);
        $this->assertSame([1, 0, 2], $sorts[0][1]);

        // pf_meta[5] meta_fields must contain price.
        $this->assertContains('price', $decoded[5]);

        // Every fragment must have price in its meta.
        $fragmentFiles = glob($this->tmpDir . '/.scolta-building/fragment/*.pf_fragment');
        $this->assertCount(3, $fragmentFiles);

        $metaByUrl = [];
        foreach ($fragmentFiles as $file) {
            $json = preg_replace('/^pagefind_dcd/', '', gzdecode(file_get_contents($file)));
            $data = json_decode($json, true);
            $metaByUrl[$data['url']] = $data['meta'];
        }

        $this->assertSame('42.99', $metaByUrl['/amethyst']['price']);
        $this->assertSame('12.50', $metaByUrl['/sapphire']['price']);
        $this->assertSame('99.00', $metaByUrl['/ruby']['price']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
