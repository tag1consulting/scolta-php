<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\CborDecoder;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\IndexMerger;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\StreamingFormatWriter;

/**
 * Terms that start with a digit, through the real build path.
 *
 * Pagefind picks the chunk for a query term by comparing it against each
 * chunk's `from` and `to` as strings, and expects each term once. A term like
 * "1812" becomes an int array key in PHP, so any step that orders terms with
 * PHP's standard comparison mixes numeric and string comparison, which is not
 * a total order ("9" < "10" < "10th" < "9"). This builds a multi-chunk index
 * whose digit-leading vocabulary spans several `pf_index` chunks and checks
 * the decoded output against what Pagefind needs.
 */
#[CoversClass(IndexMerger::class)]
final class NumericTermOrderTest extends TestCase
{
    /** Terms whose PHP comparison disagrees with byte order, or with itself. */
    private const NAMED = ['9', '10', '10th', '01', '0001', '1', '1812', '1800s', '2nd', '11mm', '2021'];

    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/scolta-numeric-order-' . uniqid();
        mkdir($this->base . '/state', 0755, true);
        mkdir($this->base . '/out', 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->base);
    }

    /**
     * Twelve pages. Each named term sits on pages in more than one partial
     * chunk (chunk size 2), and every page carries enough digit-leading and
     * alphabetic filler that both regions of the vocabulary span several
     * 40 KB `pf_index` chunks.
     *
     * @return array{items: list<ContentItem>, pagesFor: array<string, list<string>>}
     */
    public static function corpus(): array
    {
        $letters  = 'abcdefghijkl';
        $items    = [];
        $pagesFor = [];

        for ($p = 0; $p < 12; $p++) {
            $id    = 'page-' . $letters[$p];
            $words = ['war', 'of', 'history', 'lesson'];

            foreach (self::NAMED as $n => $term) {
                // Page p carries named term n when (p + n) is 0 mod 3 or p is
                // n mod 12: at least four pages each, over several chunks.
                if (($p + $n) % 3 === 0 || $p === $n % 12) {
                    $words[]            = $term;
                    $pagesFor[$term][] = $id;
                }
            }

            // Pages p and p+6 share a filler block, so each filler term is also
            // merged from two partial chunks.
            $block = $p % 6;
            for ($k = 0; $k < 700; $k++) {
                $num     = $block * 700 + $k + 3000;
                $words[] = (string) $num;
                if ($k % 5 === 0) {
                    $words[] = $num . 'th';
                }
                if ($k % 7 === 0) {
                    $words[] = '0' . $num;
                }
                $words[] = 'w' . self::alpha($block * 700 + $k);
            }

            $items[] = new ContentItem(
                id: $id,
                title: 'Lesson ' . strtoupper($letters[$p]),
                bodyHtml: '<p>' . implode(' ', $words) . '</p>',
                url: '/lesson-' . $letters[$p],
                date: '2026-01-01',
            );
        }

        return ['items' => $items, 'pagesFor' => $pagesFor];
    }

    /** Base-26 letters for $n, so filler words stay alphabetic. */
    private static function alpha(int $n): string
    {
        $s = '';
        do {
            $s = chr(97 + $n % 26) . $s;
            $n = intdiv($n, 26);
        } while ($n > 0);

        return $s;
    }

    public function testDigitLeadingTermsAreOrderedTheWayPagefindReadsThem(): void
    {
        ['items' => $items, 'pagesFor' => $pagesFor] = self::corpus();

        $orchestrator = new IndexBuildOrchestrator($this->base . '/state', $this->base . '/out');
        $result       = $orchestrator->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()->withChunkSize(2)),
            $items,
        );
        $this->assertTrue($result->success, 'Build failed: ' . ($result->error ?? ''));

        $index = self::decodeIndex($this->base . '/out/pagefind');
        $this->assertGreaterThan(3, count($index['chunks']), 'The corpus must span several index chunks.');

        $seen = [];
        foreach ($index['chunks'] as $c => $chunk) {
            $words = $chunk['words'];
            $this->assertSame($chunk['from'], $words[0], "Chunk {$c}: from must be its first term.");
            $this->assertSame($chunk['to'], $words[count($words) - 1], "Chunk {$c}: to must be its last term.");

            for ($i = 1; $i < count($words); $i++) {
                $this->assertLessThan(
                    0,
                    strcmp($words[$i - 1], $words[$i]),
                    sprintf('Chunk %d: "%s" must sort before "%s" by byte order.', $c, $words[$i - 1], $words[$i]),
                );
            }

            if ($c > 0) {
                $prev = $index['chunks'][$c - 1];
                $this->assertLessThan(
                    0,
                    strcmp($prev['to'], $chunk['from']),
                    sprintf(
                        'Chunks %d ["%s","%s"] and %d ["%s","%s"] must be ascending and non-overlapping.',
                        $c - 1,
                        $prev['from'],
                        $prev['to'],
                        $c,
                        $chunk['from'],
                        $chunk['to'],
                    ),
                );
            }

            foreach ($words as $w) {
                $seen[$w][] = $c;
            }
        }

        $repeated = array_filter($seen, static fn(array $chunks): bool => count($chunks) > 1);
        $this->assertSame([], $repeated, 'Every term must appear exactly once across all pf_index chunks.');

        foreach (self::NAMED as $term) {
            $this->assertArrayHasKey($term, $index['pages'], "Term {$term} must be in the index.");
            $expected = $pagesFor[$term];
            $actual   = $index['pages'][$term];
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "Term {$term} must list every page that contains it.");
        }
    }

    public function testTheWriterRefusesTermsOutOfByteOrder(): void
    {
        $writer = new StreamingFormatWriter(new CborEncoder());
        $writer->beginWrite($this->base . '/out');
        $entry = [0 => ['positions' => [25 => [0]], 'meta_positions' => []]];

        $writer->writeTerm('10', $entry);
        $writer->writeTerm('9', $entry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"10th" after "9"');
        $writer->writeTerm('10th', $entry);
    }

    /**
     * Decode a built index into its chunk list and each term's pages.
     *
     * Reads the raw CBOR rather than PfIndexCodec::decodeChunk(), which keys
     * by word and would hide a term repeated inside one chunk.
     *
     * @return array{
     *   chunks: list<array{from: string, to: string, words: list<string>}>,
     *   pages: array<string, list<string>>
     * }
     */
    public static function decodeIndex(string $indexDir): array
    {
        $metaPaths = glob($indexDir . '/pagefind.*.pf_meta') ?: [];
        if (count($metaPaths) !== 1) {
            throw new \RuntimeException('Expected one pf_meta in ' . $indexDir);
        }
        $meta = CborDecoder::decodeArtifact($metaPaths[0]);

        $pageIds = [];
        foreach ($meta[1] as $ordinal => $row) {
            $raw      = (string) gzdecode((string) file_get_contents($indexDir . '/fragment/' . $row[0] . '.pf_fragment'));
            $fragment = json_decode(substr($raw, strlen('pagefind_dcd')), true);
            $pageIds[$ordinal] = ltrim(str_replace('/lesson-', 'page-', (string) $fragment['url']), '/');
        }

        $chunks = [];
        $pages  = [];
        foreach ($meta[2] as $row) {
            $body  = CborDecoder::decodeArtifact($indexDir . '/index/' . $row[2] . '.pf_index');
            $words = [];
            foreach ($body[0] as $entry) {
                $word    = (string) $entry[0];
                $words[] = $word;
                $ordinal = 0;
                foreach ($entry[1] as $pageItem) {
                    $ordinal += (int) $pageItem[0];
                    $pages[$word][] = $pageIds[$ordinal];
                }
            }
            $chunks[] = ['from' => (string) $row[0], 'to' => (string) $row[1], 'words' => $words];
        }

        return ['chunks' => $chunks, 'pages' => $pages];
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
}
