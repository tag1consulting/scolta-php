<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Concordance test against Wikipedia corpus (19 languages × 5 pages).
 *
 * Compares PHP indexer output against a frozen Pagefind reference generated
 * from Wikipedia article summaries. Thresholds:
 *  - Latin-script languages: Jaccard ≥ 0.65
 *  - CJK / Arabic (ar, zh, ja, ko): Jaccard ≥ 0.45
 *
 * Run generate-concordance-fixtures-wiki.sh to regenerate reference fixtures.
 *
 * @since 0.3.0
 * @stability experimental
 */
class WikipediaConcordanceTest extends TestCase
{
    private string $referenceWikiDir;
    private string $stateDir;
    private string $outputDir;

    /** @return array<string, array{string}> */
    public static function languageProvider(): array
    {
        return [
            'Arabic'              => ['ar'],
            'Chinese Simplified'  => ['zh'],
            'Danish'              => ['da'],
            'Dutch'               => ['nl'],
            'English'             => ['en'],
            'Finnish'             => ['fi'],
            'French'              => ['fr'],
            'German'              => ['de'],
            'Hungarian'           => ['hu'],
            'Italian'             => ['it'],
            'Japanese'            => ['ja'],
            'Korean'              => ['ko'],
            'Norwegian'           => ['no'],
            'Portuguese'          => ['pt'],
            'Romanian'            => ['ro'],
            'Russian'             => ['ru'],
            'Spanish'             => ['es'],
            'Swedish'             => ['sv'],
            'Turkish'             => ['tr'],
        ];
    }

    protected function setUp(): void
    {
        $this->referenceWikiDir = __DIR__ . '/../fixtures/concordance/reference-wiki';

        if (!file_exists($this->referenceWikiDir . '/pagefind-entry.json')) {
            $this->markTestSkipped(
                'Wikipedia reference fixtures not generated. Run: ./scripts/generate-concordance-fixtures-wiki.sh'
            );
        }

        $this->stateDir = sys_get_temp_dir() . '/scolta-wiki-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-wiki-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // ---------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------

    /**
     * Fragment count is within ±1 of Pagefind reference for each language.
     *
     * @dataProvider languageProvider
     */
    public function testFragmentCountWithinDelta(string $lang): void
    {
        $phpDir = $this->buildWithPhpIndexer($lang);
        $phpFragments = $this->loadAllFragments($phpDir . '/pagefind');
        $refFragments = $this->loadLanguageFragments($lang);

        $this->assertEqualsWithDelta(
            count($refFragments),
            count($phpFragments),
            1,
            sprintf(
                '[%s] PHP indexed %d pages, Pagefind indexed %d. Allowed delta: 1.',
                $lang,
                count($phpFragments),
                count($refFragments)
            )
        );
    }

    /**
     * Average content Jaccard overlap meets language-specific threshold.
     *
     * @dataProvider languageProvider
     */
    public function testContentOverlapMeetsThreshold(string $lang): void
    {
        $phpDir = $this->buildWithPhpIndexer($lang);
        $phpFragments = $this->loadAllFragments($phpDir . '/pagefind');
        $refFragments = $this->loadLanguageFragments($lang);

        $threshold = $this->getJaccardThreshold($lang);

        $similarities = [];
        foreach ($refFragments as $url => $refFrag) {
            $phpFrag = $this->findMatchingFragment($phpFragments, $url, $refFrag);
            if ($phpFrag === null) {
                continue;
            }

            $refWords = $this->extractSignificantWords($refFrag['content'] ?? '');
            $phpWords = $this->extractSignificantWords($phpFrag['content'] ?? '');

            if (count($refWords) === 0) {
                continue;
            }

            $intersection = count(array_intersect($refWords, $phpWords));
            $union = count(array_unique(array_merge($refWords, $phpWords)));
            $similarities[] = $union > 0 ? $intersection / $union : 0.0;
        }

        if (count($similarities) === 0) {
            $this->markTestSkipped("[{$lang}] No overlapping fragments to compare.");
        }

        $avg = array_sum($similarities) / count($similarities);
        fwrite(STDERR, sprintf("[wiki:%s] Content overlap: %.3f (threshold: %.2f)\n", $lang, $avg, $threshold));

        $this->assertGreaterThanOrEqual(
            $threshold,
            $avg,
            sprintf(
                '[%s] Average Jaccard similarity %.3f < threshold %.2f (n=%d fragments)',
                $lang,
                $avg,
                $threshold,
                count($similarities)
            )
        );
    }

    /**
     * Record baseline concordance measurements to JSON file.
     *
     * This test never fails — it is a measurement pass. Run with
     * --group baseline to collect measurements for threshold review.
     *
     * @group baseline
     */
    public function testRecordWikipediaConcordanceBaseline(): void
    {
        $languages = array_values(self::languageProvider());
        $results = [];

        foreach ($languages as [$lang]) {
            $stateDir = sys_get_temp_dir() . '/scolta-wiki-bl-state-' . uniqid();
            $outputDir = sys_get_temp_dir() . '/scolta-wiki-bl-output-' . uniqid();
            mkdir($stateDir, 0755, true);
            mkdir($outputDir, 0755, true);

            try {
                $items = $this->loadContentItemsForLanguage($lang);
                $indexer = new PhpIndexer($stateDir, $outputDir, null, $lang);
                $indexer->processChunk($items, 0);
                $result = $indexer->finalize();

                if (!$result->success) {
                    $results[$lang] = ['error' => $result->error ?? 'build failed'];
                    continue;
                }

                $phpFragments = $this->loadAllFragments($outputDir . '/pagefind');
                $refFragments = $this->loadLanguageFragments($lang);

                $similarities = [];
                foreach ($refFragments as $url => $refFrag) {
                    $phpFrag = $this->findMatchingFragment($phpFragments, $url, $refFrag);
                    if ($phpFrag === null) {
                        continue;
                    }
                    $refWords = $this->extractSignificantWords($refFrag['content'] ?? '');
                    $phpWords = $this->extractSignificantWords($phpFrag['content'] ?? '');
                    if (count($refWords) === 0) {
                        continue;
                    }
                    $intersection = count(array_intersect($refWords, $phpWords));
                    $union = count(array_unique(array_merge($refWords, $phpWords)));
                    $similarities[] = $union > 0 ? $intersection / $union : 0.0;
                }

                $avg = count($similarities) > 0 ? array_sum($similarities) / count($similarities) : 0.0;
                $results[$lang] = [
                    'jaccard' => round($avg, 4),
                    'fragments_compared' => count($similarities),
                    'threshold' => $this->getJaccardThreshold($lang),
                    'passes' => $avg >= $this->getJaccardThreshold($lang),
                ];
            } finally {
                $this->removeDir($stateDir);
                $this->removeDir($outputDir);
            }
        }

        $baselineFile = __DIR__ . '/../fixtures/concordance/wiki-concordance-baseline.json';
        if (!file_exists($baselineFile)) {
            file_put_contents(
                $baselineFile,
                json_encode([
                    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'corpus' => 'corpus-wiki',
                    'results' => $results,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
            );
        }

        // Always passes — this is a measurement, not an assertion.
        $this->assertTrue(true, 'Baseline recorded to: ' . $baselineFile);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Returns Jaccard threshold for the given language.
     *
     * Thresholds were tightened after measuring both Wikipedia corpora
     * (science/geography + literature/arts topics). Adjustment logic:
     *  - Latin: both corpora > 0.80 → max(0.75, min(both) − 0.03)
     *  - CJK+Arabic: both corpora > 0.55 → max(0.50, min(both) − 0.03)
     *  - If variance between corpora > 0.05: leave unchanged.
     */
    private function getJaccardThreshold(string $lang): float
    {
        return match ($lang) {
            // CJK + Arabic — tightened from 0.45
            'ar' => 0.95,   // both ~0.98, min−0.03=0.95
            'zh' => 0.90,   // both ~0.93, min−0.03=0.90
            'ja' => 0.97,   // both 1.000, min−0.03=0.97
            'ko' => 0.97,   // both 1.000, min−0.03=0.97
            // Latin with minor variance (< 0.05) — tightened from 0.65
            'ro' => 0.95,   // baseline 0.980, extended 0.981
            'ru' => 0.92,   // baseline 0.983, extended 0.952
            // Most Latin languages: both = 1.000 — tightened from 0.65
            default => 0.97,
        };
    }

    private function buildWithPhpIndexer(string $lang): string
    {
        $items = $this->loadContentItemsForLanguage($lang);
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir, null, $lang);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success, "[{$lang}] PHP index build must succeed: " . ($result->error ?? ''));

        return $this->outputDir;
    }

    /** @return ContentItem[] */
    private function loadContentItemsForLanguage(string $lang): array
    {
        $corpusDir = __DIR__ . '/../fixtures/concordance/corpus-wiki';
        $items = [];

        foreach (glob("{$corpusDir}/{$lang}-*.html") as $file) {
            $html = file_get_contents($file);
            preg_match('/<title>(.*?)<\/title>/s', $html, $titleMatch);
            preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $bodyMatch);
            $slug = basename($file, '.html');

            $items[] = new ContentItem(
                id: $slug,
                title: html_entity_decode($titleMatch[1] ?? $slug, ENT_QUOTES, 'UTF-8'),
                bodyHtml: $bodyMatch[1] ?? '',
                url: "/{$slug}",
                date: '2026-04-14',
            );
        }

        return $items;
    }

    /**
     * Load all fragments from the Wikipedia reference dir for the given language.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadLanguageFragments(string $lang): array
    {
        $fragments = [];
        $refDir = $this->referenceWikiDir;

        // Try lang-prefixed files in fragment/ subdir (Pagefind 1.5+ layout).
        $files = glob("{$refDir}/fragment/{$lang}_*.pf_fragment") ?: [];

        if (empty($files)) {
            $files = glob("{$refDir}/{$lang}_*.pf_fragment") ?: [];
        }

        foreach ($files as $file) {
            $decompressed = gzdecode(file_get_contents($file));
            if ($decompressed === false) {
                continue;
            }
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }
            $json = json_decode($decompressed, true);
            if ($json !== null && isset($json['url'])) {
                $fragments[$json['url']] = $json;
            }
        }

        // Fallback: filter all fragments by URL prefix.
        if (empty($fragments)) {
            $allFiles = glob("{$refDir}/fragment/*.pf_fragment") ?: glob("{$refDir}/*.pf_fragment") ?: [];
            foreach ($allFiles as $file) {
                $decompressed = gzdecode(file_get_contents($file));
                if ($decompressed === false) {
                    continue;
                }
                if (str_starts_with($decompressed, 'pagefind_dcd')) {
                    $decompressed = substr($decompressed, 12);
                }
                $json = json_decode($decompressed, true);
                if ($json !== null && isset($json['url'])) {
                    if (str_starts_with($json['url'], "/{$lang}-")) {
                        $fragments[$json['url']] = $json;
                    }
                }
            }
        }

        return $fragments;
    }

    private function loadAllFragments(string $dir): array
    {
        $fragments = [];
        $files = glob($dir . '/fragment/*.pf_fragment') ?: glob($dir . '/*.pf_fragment');

        foreach ($files as $file) {
            $decompressed = gzdecode(file_get_contents($file));
            if ($decompressed === false) {
                continue;
            }
            if (str_starts_with($decompressed, 'pagefind_dcd')) {
                $decompressed = substr($decompressed, 12);
            }
            $json = json_decode($decompressed, true);
            if ($json !== null && isset($json['url'])) {
                $fragments[$json['url']] = $json;
            }
        }

        return $fragments;
    }

    private function findMatchingFragment(array $phpFragments, string $refUrl, array $refFrag): ?array
    {
        if (isset($phpFragments[$refUrl])) {
            return $phpFragments[$refUrl];
        }
        $refTitle = $refFrag['meta']['title'] ?? '';
        foreach ($phpFragments as $phpFrag) {
            if (($phpFrag['meta']['title'] ?? '') === $refTitle) {
                return $phpFrag;
            }
        }

        return null;
    }

    /** @return string[] */
    private function extractSignificantWords(string $text): array
    {
        $words = preg_split('/[\s\p{P}]+/u', mb_strtolower($text));

        return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) >= 2)));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
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
