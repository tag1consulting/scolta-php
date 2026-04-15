<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Concordance test against extended Wikipedia corpus (19 languages × 5 pages).
 *
 * Uses different topics (literature, philosophy, music, sport, science)
 * from the base WikipediaConcordanceTest (which uses science/geography).
 * Thresholds are the same: Latin-script ≥ 0.65, CJK+Arabic ≥ 0.45.
 *
 * Run generate-concordance-fixtures-wiki-extended.sh to regenerate reference.
 *
 * @since 0.3.0
 * @stability experimental
 */
class ExtendedWikipediaConcordanceTest extends TestCase
{
    private string $referenceExtDir;
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
        $this->referenceExtDir = __DIR__ . '/../fixtures/concordance/reference-wiki-extended';

        if (!file_exists($this->referenceExtDir . '/pagefind-entry.json')) {
            $this->markTestSkipped(
                'Extended Wikipedia reference fixtures not generated. Run: ./scripts/generate-concordance-fixtures-wiki-extended.sh'
            );
        }

        $this->stateDir = sys_get_temp_dir() . '/scolta-wiki-ext-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-wiki-ext-output-' . uniqid();
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
                '[ext:%s] PHP indexed %d pages, Pagefind indexed %d. Allowed delta: 1.',
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
            $this->markTestSkipped("[ext:{$lang}] No overlapping fragments to compare.");
        }

        $avg = array_sum($similarities) / count($similarities);
        fwrite(STDERR, sprintf("[wiki-ext:%s] Content overlap: %.3f (threshold: %.2f)\n", $lang, $avg, $threshold));

        $this->assertGreaterThanOrEqual(
            $threshold,
            $avg,
            sprintf(
                '[ext:%s] Average Jaccard similarity %.3f < threshold %.2f (n=%d fragments)',
                $lang,
                $avg,
                $threshold,
                count($similarities)
            )
        );
    }

    /**
     * Record extended baseline measurements.
     *
     * @group baseline
     */
    public function testRecordExtendedWikipediaConcordanceBaseline(): void
    {
        $languages = array_values(self::languageProvider());
        $results = [];

        foreach ($languages as [$lang]) {
            $stateDir = sys_get_temp_dir() . '/scolta-wiki-ext-bl-state-' . uniqid();
            $outputDir = sys_get_temp_dir() . '/scolta-wiki-ext-bl-output-' . uniqid();
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

        $extendedFile = __DIR__ . '/../fixtures/concordance/wiki-concordance-extended.json';
        if (!file_exists($extendedFile)) {
            file_put_contents(
                $extendedFile,
                json_encode([
                    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'corpus' => 'corpus-wiki-extended',
                    'results' => $results,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
            );
        }

        $this->assertTrue(true, 'Extended baseline recorded to: ' . $extendedFile);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Returns Jaccard threshold for the given language.
     *
     * Same tightened thresholds as WikipediaConcordanceTest after
     * cross-corpus threshold revisit.
     */
    private function getJaccardThreshold(string $lang): float
    {
        return match ($lang) {
            'ar' => 0.95,
            'zh' => 0.90,
            'ja' => 0.97,
            'ko' => 0.97,
            'ro' => 0.95,
            'ru' => 0.92,
            default => 0.97,
        };
    }

    private function buildWithPhpIndexer(string $lang): string
    {
        $items = $this->loadContentItemsForLanguage($lang);
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir, null, $lang);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success, "[ext:{$lang}] PHP index build must succeed: " . ($result->error ?? ''));

        return $this->outputDir;
    }

    /** @return ContentItem[] */
    private function loadContentItemsForLanguage(string $lang): array
    {
        $corpusDir = __DIR__ . '/../fixtures/concordance/corpus-wiki-extended';
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
     * Load language fragments from the extended reference directory.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadLanguageFragments(string $lang): array
    {
        $fragments = [];
        $refDir = $this->referenceExtDir;

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
