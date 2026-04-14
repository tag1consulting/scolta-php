<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Multilingual concordance: PHP indexer vs frozen Pagefind reference (19 languages).
 *
 * Each language gets 5 corpus pages. Thresholds:
 *  - Latin-script languages (da, nl, en, fi, fr, de, hu, it, no, pt, ro, es, sv, tr): Jaccard ≥ 0.70
 *  - CJK / Arabic (ar, zh, ja, ko): Jaccard ≥ 0.50
 *
 * @since 0.3.0
 * @stability experimental
 */
class MultilingualReferenceComparisonTest extends TestCase
{
    private string $referenceMlDir;
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
        $this->referenceMlDir = __DIR__ . '/../fixtures/concordance/reference-ml';

        if (!file_exists($this->referenceMlDir . '/pagefind-entry.json')) {
            $this->markTestSkipped(
                'ML reference fixtures not generated. Run: ./scripts/generate-concordance-fixtures-ml.sh'
            );
        }

        $this->stateDir = sys_get_temp_dir() . '/scolta-ml-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-ml-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

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
        fwrite(STDERR, sprintf("[ml:%s] Content overlap: %.3f (threshold: %.2f)\n", $lang, $avg, $threshold));

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

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** Returns Jaccard threshold for the given language. */
    private function getJaccardThreshold(string $lang): float
    {
        // CJK and Arabic use character-level or compound splitting that diverges
        // more from Pagefind's tokenization.
        $nonLatinScripts = ['ar', 'zh', 'ja', 'ko'];

        return in_array($lang, $nonLatinScripts, true) ? 0.50 : 0.70;
    }

    private function buildWithPhpIndexer(string $lang): string
    {
        $items = $this->loadContentItemsForLanguage($lang);
        // Language is passed to PhpIndexer (controls the stemmer), not to ContentItem.
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir, null, $lang);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success, "[{$lang}] PHP index build must succeed: " . ($result->error ?? ''));

        return $this->outputDir;
    }

    /** @return ContentItem[] */
    private function loadContentItemsForLanguage(string $lang): array
    {
        $corpusDir = __DIR__ . '/../fixtures/concordance/corpus-ml';
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
                date: '2026-04-01',
            );
        }

        return $items;
    }

    /**
     * Load all fragments from the ML reference dir that belong to the given language.
     * Reference fragments are in reference-ml/fragment/{lang}_*.pf_fragment.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadLanguageFragments(string $lang): array
    {
        $fragments = [];
        $refDir = $this->referenceMlDir;

        // Try lang-prefixed files in fragment/ subdir first (Pagefind 1.5+ layout).
        $files = glob("{$refDir}/fragment/{$lang}_*.pf_fragment") ?: [];

        // Fallback: all files in root (older layout).
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

        // If no language-specific files found, fall back to filtering all fragments by URL prefix.
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
                    // Match by URL prefix /{lang}-
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
