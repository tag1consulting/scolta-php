<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\LargeContent;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\MemoryBudget;

/**
 * Base class for large-content index tests.
 *
 * Run with: vendor/bin/phpunit --group large-content
 *
 * Tests in this group are skipped in standard CI (they can take 30–120s
 * depending on corpus size) but must pass in the dedicated large-content
 * CI job and before every minor release.
 */
abstract class AbstractLargeContentTestCase extends TestCase
{
    protected string $stateDir;
    protected string $outputDir;

    protected function setUp(): void
    {
        $uid             = uniqid('', true);
        $this->stateDir  = sys_get_temp_dir() . "/scolta-large-state-{$uid}";
        $this->outputDir = sys_get_temp_dir() . "/scolta-large-out-{$uid}";
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    abstract protected function configureBudget(): MemoryBudget;

    abstract protected function assertPeakUnderBudget(int $actualBytes): void;

    /**
     * Generate a deterministic corpus of $pageCount pages.
     *
     * Each page has a realistic title (~5 words) and body (~500 words) drawn
     * from a fixed ~5 000-word vocabulary seeded with $seed, making the
     * output reproducible across platforms.
     *
     * @return iterable<ContentItem>
     */
    protected function makeLoremCorpus(int $pageCount, int $seed = 42): iterable
    {
        $vocab  = self::buildVocabulary();
        $vcount = count($vocab);

        // Simple 32-bit LCG (reproducible across platforms, unlike rand()).
        $state = $seed & 0xFFFFFFFF;
        $next  = static function () use (&$state): int {
            $state = (($state * 1664525 + 1013904223) & 0xFFFFFFFF);

            return $state;
        };

        for ($i = 0; $i < $pageCount; $i++) {
            // Title: 4–7 words.
            $titleLen = ($next() % 4) + 4;
            $titleWords = [];
            for ($t = 0; $t < $titleLen; $t++) {
                $titleWords[] = ucfirst($vocab[$next() % $vcount]);
            }
            $title = implode(' ', $titleWords);

            // Body: ~500 words across 3–5 paragraphs.
            $body = '';
            $paraCount = ($next() % 3) + 3;
            for ($p = 0; $p < $paraCount; $p++) {
                $wordCount = ($next() % 60) + 80;
                $words     = [];
                for ($w = 0; $w < $wordCount; $w++) {
                    $words[] = $vocab[$next() % $vcount];
                }
                $body .= '<p>' . implode(' ', $words) . '</p>';
            }

            yield new ContentItem(
                id: "page-{$i}",
                title: $title,
                bodyHtml: $body,
                url: "/page/{$i}",
                date: '2024-01-01',
                siteName: 'Large Content Test Site',
            );
        }
    }

    /**
     * A deterministic ~5 000-word vocabulary (Latin + English stopwords +
     * domain terms) for realistic term-frequency distribution.
     */
    private static function buildVocabulary(): array
    {
        // Lorem Ipsum base words.
        $lorem = [
            'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit',
            'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'labore', 'magna', 'aliqua',
            'enim', 'minim', 'veniam', 'quis', 'nostrud', 'exercitation', 'ullamco',
            'laboris', 'nisi', 'aliquip', 'commodo', 'consequat', 'duis', 'aute', 'irure',
            'reprehenderit', 'voluptate', 'velit', 'esse', 'cillum', 'dolore', 'fugiat',
            'nulla', 'pariatur', 'excepteur', 'sint', 'occaecat', 'cupidatat', 'proident',
            'culpa', 'qui', 'officia', 'deserunt', 'mollit', 'anim', 'est', 'laborum',
        ];

        // Common English stopwords.
        $stopwords = [
            'the', 'and', 'is', 'in', 'it', 'of', 'to', 'a', 'an', 'that', 'was',
            'for', 'on', 'are', 'with', 'as', 'at', 'be', 'by', 'from', 'or', 'have',
            'this', 'but', 'not', 'from', 'had', 'his', 'her', 'they', 'we', 'you',
            'were', 'which', 'been', 'have', 'will', 'more', 'when', 'there', 'so',
            'some', 'would', 'also', 'into', 'than', 'then', 'these', 'no', 'other',
        ];

        // Domain terms for realistic distribution.
        $domain = [
            'search', 'index', 'content', 'page', 'result', 'query', 'term', 'word',
            'document', 'relevance', 'score', 'rank', 'site', 'web', 'data', 'text',
            'article', 'blog', 'post', 'title', 'body', 'tag', 'category', 'author',
            'date', 'published', 'updated', 'section', 'heading', 'paragraph',
            'language', 'english', 'french', 'german', 'spanish', 'tokenize', 'stem',
            'filter', 'facet', 'highlight', 'excerpt', 'summary', 'description',
            'keyword', 'phrase', 'boolean', 'prefix', 'fuzzy', 'exact', 'match',
            'weight', 'boost', 'decay', 'recency', 'frequency', 'position', 'offset',
            'chunk', 'merge', 'build', 'output', 'directory', 'file', 'path', 'stream',
        ];

        // Expand to ~5 000 words by cycling and varying the above sets.
        $base  = array_merge($lorem, $stopwords, $domain);
        $vocab = [];
        while (count($vocab) < 5000) {
            foreach ($base as $word) {
                $vocab[] = $word;
                if (count($vocab) >= 5000) {
                    break;
                }
            }
        }

        return $vocab;
    }

    protected function removeDir(string $dir): void
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
