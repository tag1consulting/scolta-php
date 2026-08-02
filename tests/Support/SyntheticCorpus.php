<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Support;

use Tag1\Scolta\Export\ContentItem;

/**
 * Deterministic synthetic corpus generator.
 *
 * The generator in tests/Benchmark/IndexerBenchmarkTest.php calls rand() for
 * paragraph and sentence lengths, so despite its "seeded word pool so output
 * is deterministic" comment the bodies differ between runs. That is harmless
 * for a timing benchmark and fatal for anything that compares two builds:
 * a differential test needs the same corpus twice, byte for byte.
 *
 * This generator draws every length from an explicit linear congruential
 * generator seeded per item, so generate(5000, seed: 7) returns identical
 * ContentItems on every call, in every process, on every platform.
 *
 * @since 1.2.0
 * @stability experimental
 */
final class SyntheticCorpus
{
    /** Numerical Recipes LCG constants — any full-period pair would do. */
    private const LCG_MULTIPLIER = 1_664_525;
    private const LCG_INCREMENT  = 1_013_904_223;
    private const LCG_MODULUS    = 4_294_967_296;

    private const TOPICS = [
        'PHP', 'Laravel', 'WordPress', 'Drupal', 'Search', 'AI', 'Machine Learning',
        'Web Development', 'API Design', 'Performance', 'Security', 'Database', 'Caching',
        'DevOps', 'Testing', 'Accessibility', 'SEO', 'Open Source', 'Cloud Computing',
        'Microservices',
    ];

    private const VERBS = ['Optimizing', 'Understanding', 'Building', 'Deploying', 'Testing', 'Scaling', 'Securing'];

    /**
     * Generate a deterministic corpus of $count items.
     *
     * @return ContentItem[] Indexed 0..$count-1, item N always identical for a given seed.
     */
    public static function generate(int $count, int $seed = 1): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = self::item($i, $seed);
        }

        return $items;
    }

    /**
     * Generate item number $i exactly as generate() would.
     *
     * $revision changes the body text while holding id, url and title fixed —
     * that is the "someone edited this page" case an incremental updater has
     * to handle. $titleRevision changes only the title, which is the case the
     * token cache key currently misses.
     */
    public static function item(int $i, int $seed = 1, int $revision = 0, int $titleRevision = 0): ContentItem
    {
        $topic = self::TOPICS[($i - 1) % count(self::TOPICS)];
        $verb  = self::VERBS[($i - 1) % count(self::VERBS)];

        $title = "{$verb} {$topic}: Part {$i}";
        if ($titleRevision > 0) {
            $title .= " (revised {$titleRevision})";
        }

        $type = ($i % 100 < 60) ? 'article' : (($i % 100 < 85) ? 'short' : 'guide');
        $targetWords = match ($type) {
            'short' => 100,
            'guide' => 1500,
            default => 500,
        };

        // Dates are computed from the index, not from the clock, so a corpus
        // generated today equals one generated next year.
        $date = sprintf('%04d-%02d-%02d', 2024 + ($i % 2), 1 + ($i % 12), 1 + ($i % 28));
        $url  = '/content/' . strtolower(str_replace(' ', '-', "{$verb}-{$topic}")) . '-' . $i;

        return new ContentItem(
            id: "item-{$i}",
            title: $title,
            bodyHtml: self::body($topic, $targetWords, $seed * 7919 + $i * 31 + $revision * 104_729),
            url: $url,
            date: $date,
            siteName: 'Synthetic Site',
            language: 'en',
            filters: [
                // Two multi-value dimensions so filter chunks and the facet
                // index are non-trivial: a single-value dimension is skipped
                // by buildFilterIndex() and would not exercise the path.
                'topic'    => $topic,
                'category' => $type,
            ],
        );
    }

    /**
     * Generate HTML with roughly $targetWords words, driven entirely by the LCG.
     */
    private static function body(string $topic, int $targetWords, int $seed): string
    {
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
        $poolSize = count($wordPool);

        $state = $seed % self::LCG_MODULUS;
        $next  = static function (int $lo, int $hi) use (&$state): int {
            $state = (self::LCG_MULTIPLIER * $state + self::LCG_INCREMENT) % self::LCG_MODULUS;

            return $lo + (int) ($state % (($hi - $lo) + 1));
        };

        $html  = "<h1>Introduction to {$topic}</h1>\n";
        $words = 0;

        while ($words < $targetWords) {
            $paraWords = min($next(20, 60), $targetWords - $words);
            $sentences = [];
            $sentWords = 0;

            while ($sentWords < $paraWords) {
                $sentLen = $next(8, 18);
                $sent    = [];
                for ($w = 0; $w < $sentLen; $w++) {
                    $sent[] = $wordPool[($seed + $words + $sentWords + $w) % $poolSize];
                }
                $sent[0]     = ucfirst($sent[0]);
                $sentences[] = implode(' ', $sent) . '.';
                $sentWords  += $sentLen;
                $words      += $sentLen;
            }

            $html .= '<p>' . implode(' ', $sentences) . "</p>\n";
        }

        return $html;
    }
}
