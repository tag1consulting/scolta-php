<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Benchmark;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Performance benchmarks for the PHP indexer.
 *
 * Generates synthetic corpora at various scales and measures build time,
 * peak memory usage, and output index size. Run with:
 *
 *     ./vendor/bin/phpunit --group benchmark tests/Benchmark/
 *
 * These tests are excluded from the normal CI suite because they are slow.
 * They are run manually, or in a dedicated weekly benchmark workflow.
 *
 * Acceptance criteria (2-core VPS, 2GB RAM):
 *   - 1K  pages:   < 5s,  < 128MB
 *   - 10K pages:   < 60s, < 512MB
 *   - 50K pages:   < 300s (5 min), no OOM on 2GB
 *   - 100K pages:  completes without OOM on 4GB
 */
#[Group('benchmark')]
class IndexerBenchmarkTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-bench-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-bench-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // -----------------------------------------------------------------------
    // Scale checkpoints
    // -----------------------------------------------------------------------

    public function testBenchmark100Pages(): void
    {
        $this->runBenchmark(100, maxSeconds: 5.0, maxMemoryMb: 32);
    }

    public function testBenchmark1kPages(): void
    {
        // ~8–10ms/page on typical hardware. Limit is generous to accommodate
        // slow CI runners — the actual target is < 10s on a 2-core VPS.
        $this->runBenchmark(1_000, maxSeconds: 30.0, maxMemoryMb: 256);
    }

    public function testBenchmark10kPages(): void
    {
        $this->runBenchmark(10_000, maxSeconds: 300.0, maxMemoryMb: 1024);
    }

    /**
     * 50K test: verify completion without OOM. Time limit is generous
     * because this is expected to exceed the 10K-60s target by ~5×.
     * Only enforces no crash / no OOM. Run on machines with ≥2GB RAM.
     */
    public function testBenchmark50kPages(): void
    {
        if ((int) ini_get('memory_limit') !== -1) {
            $limitMb = $this->parseMemoryLimitMb(ini_get('memory_limit'));
            if ($limitMb < 1024) {
                $this->markTestSkipped("50K benchmark requires ≥1GB memory_limit (current: {$limitMb}MB)");
            }
        }

        $this->runBenchmark(50_000, maxSeconds: 600.0, maxMemoryMb: 2048);
    }

    // -----------------------------------------------------------------------
    // Incremental rebuild benchmark: second run on identical content
    // -----------------------------------------------------------------------

    public function testIncrementalRebuildSkipsUnchanged(): void
    {
        $items = $this->generateCorpus(1_000);

        // First build.
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        foreach (array_chunk($items, 100) as $i => $chunk) {
            $indexer->processChunk($chunk, $i, count($items));
        }
        $first = $indexer->finalize();
        $this->assertTrue($first->success, 'First build failed: ' . ($first->error ?? ''));

        // Persist the fingerprint (caller responsibility — same as production code).
        $fingerprint = PhpIndexer::computeFingerprint($items);
        file_put_contents($this->outputDir . '/.scolta-state', $fingerprint);

        // Second run: same content → shouldBuild() should return null.
        $indexer2 = new PhpIndexer($this->stateDir, $this->outputDir);
        $this->assertNull($indexer2->shouldBuild($items), 'Fingerprint should be null (unchanged content) on second build');
    }

    // -----------------------------------------------------------------------
    // Output size scales linearly
    // -----------------------------------------------------------------------

    public function testOutputSizeScalesLinearly(): void
    {
        [$small, $smallSize] = $this->measureOutputSize(100);
        [$large, $largeSize] = $this->measureOutputSize(1_000);

        $ratio = $largeSize / max($smallSize, 1);
        // 10× more pages yield roughly 3–10× more output.
        // Pagefind's compressed format reuses vocabulary across pages,
        // so the index grows sub-linearly (more sharing = better compression).
        $this->assertGreaterThan(2.0, $ratio, "Output size ratio {$ratio}× is too small (expected 3–10×)");
        $this->assertLessThan(12.0, $ratio, "Output size ratio {$ratio}× is too large (expected 3–10×)");
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Run the full indexer pipeline for $count pages and assert perf criteria.
     */
    private function runBenchmark(int $count, float $maxSeconds, float $maxMemoryMb): void
    {
        $items = $this->generateCorpus($count);

        $memBefore = memory_get_usage(true);
        $start = microtime(true);

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        // preserve_keys=true so each item keeps its unique integer key,
        // avoiding page-number collisions when IndexMerger merges chunks.
        foreach (array_chunk($items, 100, true) as $i => $chunk) {
            $indexer->processChunk($chunk, $i, count($items));
        }
        $result = $indexer->finalize();

        $elapsed = microtime(true) - $start;
        $peakMb = (memory_get_peak_usage(true) - $memBefore) / 1024 / 1024;
        $outputMb = $this->dirSize($this->outputDir) / 1024 / 1024;
        $perPage = $count > 0 ? ($elapsed / $count * 1000) : 0;

        // Print benchmark table row for human review.
        $this->printRow($count, $elapsed, $peakMb, $outputMb, $perPage);

        $this->assertTrue($result->success, "Build failed for {$count} pages: " . ($result->error ?? ''));
        $this->assertEquals($count, $result->pageCount, "Expected {$count} pages in result");

        $this->assertLessThan(
            $maxSeconds,
            $elapsed,
            sprintf('Build of %d pages took %.1fs (limit %.1fs)', $count, $elapsed, $maxSeconds)
        );

        $this->assertLessThan(
            $maxMemoryMb,
            $peakMb,
            sprintf('Build of %d pages used %.1fMB peak memory (limit %.1fMB)', $count, $peakMb, $maxMemoryMb)
        );
    }

    /**
     * Build $count pages and return [$result, $outputBytes].
     *
     * @return array{0: bool, 1: int}
     */
    private function measureOutputSize(int $count): array
    {
        $stateDir = sys_get_temp_dir() . '/scolta-bench-state-size-' . uniqid();
        $outputDir = sys_get_temp_dir() . '/scolta-bench-output-size-' . uniqid();
        mkdir($stateDir, 0755, true);
        mkdir($outputDir, 0755, true);

        try {
            $items = $this->generateCorpus($count);
            $indexer = new PhpIndexer($stateDir, $outputDir);
            foreach (array_chunk($items, 100) as $i => $chunk) {
                $indexer->processChunk($chunk, $i, count($items));
            }
            $result = $indexer->finalize();
            $size = $this->dirSize($outputDir);
            return [$result->success, $size];
        } finally {
            $this->removeDir($stateDir);
            $this->removeDir($outputDir);
        }
    }

    /**
     * Generate a synthetic corpus of realistic content items.
     *
     * Mix of:
     *   - 60% article-style posts with ~500 words
     *   - 25% short pages with ~100 words
     *   - 15% long-form guides with ~1,500 words
     */
    private function generateCorpus(int $count): array
    {
        $items = [];
        $topics = [
            'PHP', 'Laravel', 'WordPress', 'Drupal', 'Search', 'AI', 'Machine Learning',
            'Web Development', 'API Design', 'Performance', 'Security', 'Database', 'Caching',
            'DevOps', 'Testing', 'Accessibility', 'SEO', 'Open Source', 'Cloud Computing',
            'Microservices',
        ];
        $verbs = ['Optimizing', 'Understanding', 'Building', 'Deploying', 'Testing', 'Scaling', 'Securing'];

        for ($i = 1; $i <= $count; $i++) {
            $topic = $topics[($i - 1) % count($topics)];
            $verb = $verbs[($i - 1) % count($verbs)];
            $type = ($i % 100 < 60) ? 'article' : (($i % 100 < 85) ? 'short' : 'guide');

            $title = "{$verb} {$topic}: Part {$i}";
            $date = date('Y-m-d', mktime(0, 0, 0, 1 + ($i % 12), 1 + ($i % 28), 2024 + ($i % 2)));
            $url = '/content/' . strtolower(str_replace(' ', '-', "{$verb}-{$topic}")) . '-' . $i;

            $wordCount = match ($type) {
                'short' => 100,
                'guide' => 1500,
                default => 500,
            };

            $items[] = new ContentItem(
                id: "item-{$i}",
                title: $title,
                bodyHtml: $this->generateHtmlContent($topic, $wordCount, $i),
                url: $url,
                date: $date,
                siteName: 'Benchmark Site',
            );
        }

        return $items;
    }

    /**
     * Generate realistic HTML content with the given approximate word count.
     */
    private function generateHtmlContent(string $topic, int $targetWords, int $seed): string
    {
        // Seeded word pool so output is deterministic.
        $wordPool = [
            'the', 'a', 'an', 'in', 'on', 'at', 'for', 'with', 'by', 'from',
            'application', 'system', 'performance', 'configuration', 'implementation',
            'framework', 'library', 'module', 'component', 'service', 'feature',
            'database', 'query', 'index', 'cache', 'memory', 'storage', 'file',
            'user', 'request', 'response', 'endpoint', 'authentication', 'authorization',
            'search', 'filter', 'sort', 'paginate', 'render', 'transform', 'validate',
            'optimize', 'monitor', 'deploy', 'scale', 'test', 'debug', 'refactor',
            'efficient', 'reliable', 'scalable', 'maintainable', 'extensible', 'secure',
            strtolower($topic), strtolower($topic) . 's', strtolower($topic) . 'ing',
        ];

        $html = "<h1>Introduction to {$topic}</h1>\n";
        $words = 0;
        $poolSize = count($wordPool);

        while ($words < $targetWords) {
            $paraWords = min(rand(20, 60), $targetWords - $words);
            $sentences = [];
            $sentWords = 0;

            while ($sentWords < $paraWords) {
                $sentLen = rand(8, 18);
                $sent = [];
                for ($w = 0; $w < $sentLen; $w++) {
                    $sent[] = $wordPool[($seed + $words + $sentWords + $w) % $poolSize];
                }
                $sent[0] = ucfirst($sent[0]);
                $sentences[] = implode(' ', $sent) . '.';
                $sentWords += $sentLen;
                $words += $sentLen;
            }

            $html .= '<p>' . implode(' ', $sentences) . "</p>\n";
        }

        return $html;
    }

    /**
     * Print a benchmark result row to stdout.
     */
    private function printRow(int $pages, float $elapsed, float $peakMb, float $outputMb, float $msPerPage): void
    {
        // PHPUnit captures output unless run with --no-output-capture or similar.
        // We use fwrite(STDERR) so it always shows.
        fwrite(STDERR, sprintf(
            "[benchmark] %6d pages | %6.2fs | %6.1fMB peak | %5.1fMB output | %5.2fms/page\n",
            $pages,
            $elapsed,
            $peakMb,
            $outputMb,
            $msPerPage
        ));
    }

    /**
     * Calculate total directory size in bytes.
     */
    private function dirSize(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function parseMemoryLimitMb(string $limit): int
    {
        $limit = strtolower(trim($limit));
        if (str_ends_with($limit, 'g')) {
            return (int) $limit * 1024;
        }
        if (str_ends_with($limit, 'm')) {
            return (int) $limit;
        }
        return (int) ($limit / 1024 / 1024);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
