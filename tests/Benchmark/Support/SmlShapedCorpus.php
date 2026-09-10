<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Benchmark\Support;

use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * A synthetic corpus shaped like the real Share My Lesson state directory.
 *
 * Timing a build against SyntheticCorpus measures the wrong thing: its pages
 * draw from a twenty-word topic list, so the vocabulary saturates within a few
 * hundred pages and every index chunk is a handful of very long posting lists.
 * The corpus this package is actually slow on has the opposite shape — a long
 * Zipf tail where most terms appear once — and the merge, the chunk cover and
 * the compression all behave differently there.
 *
 * The proportions come from the SML state directory as measured on 2026-08-22
 * (109,308 pages): 66.8 tokens and 363 bytes of cleaned content per page,
 * fragments of 823 bytes, seven filter dimensions (content_type 13 values,
 * subject ~3,958, grade ~20, resource_type 14, audience 5, verified_standard 4,
 * standard_authority ~60), one sortable, and a dozen card metadata keys
 * carrying image and author URLs. The vocabulary is 60,000 words under a Zipf
 * 1.1 distribution, so common-word entries carry most of each chunk's bytes,
 * as they do on the real corpus.
 *
 * Every page is a pure function of (seed, n), so two runs generate the same
 * bytes and a differential comparison means something. An $edit revision
 * appends a paragraph to the body and changes nothing else, which is what lets
 * a body-only edit be told apart from a facet change.
 *
 * @since 1.4.0
 * @stability experimental
 */
final class SmlShapedCorpus
{
    /** @var list<string> */
    private array $vocab = [];
    private int $vocabSize;
    /** @var list<float> */
    private array $cdf = [];

    public function __construct(private readonly int $pages, int $vocabSize = 60_000, private readonly int $seed = 42)
    {
        $this->vocabSize = $vocabSize;
        mt_srand($seed);
        $head = ['the', 'and', 'of', 'to', 'students', 'lesson', 'will', 'in', 'for', 'this', 'with', 'a', 'on', 'learn', 'activity', 'plan', 'reading', 'math', 'science', 'grade'];
        $this->vocab = $head;
        $syll = ['ba', 'co', 'di', 'fu', 'ga', 'he', 'ji', 'ko', 'lu', 'me', 'na', 'po', 'qu', 'ra', 'si', 'tu', 'vo', 'wa', 'xi', 'yo', 'za', 'tion', 'ment', 'ing', 'ed', 'er', 'ly', 'ness'];
        while (count($this->vocab) < $vocabSize) {
            $n = mt_rand(2, 4);
            $w = '';
            for ($i = 0; $i < $n; $i++) {
                $w .= $syll[mt_rand(0, count($syll) - 1)];
            }
            $this->vocab[] = $w;
        }
        $sum = 0.0;
        $weights = [];
        for ($r = 1; $r <= $vocabSize; $r++) {
            $weights[] = 1 / ($r ** 1.1);
            $sum += $weights[$r - 1];
        }
        $acc = 0.0;
        foreach ($weights as $w) {
            $acc += $w / $sum;
            $this->cdf[] = $acc;
        }
    }

    public function count(): int
    {
        return $this->pages;
    }

    private function word(): string
    {
        $u = mt_rand() / mt_getrandmax();
        $lo = 0;
        $hi = $this->vocabSize - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->cdf[$mid] < $u) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $this->vocab[$lo];
    }

    private function words(int $n): string
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->word();
        }

        return implode(' ', $out);
    }

    /** One page, deterministic for (seed, n). $edit > 0 appends a body paragraph and changes nothing else. */
    public function item(int $n, int $edit = 0): ContentItem
    {
        mt_srand($this->seed * 1_000_003 + $n * 7919);
        $bundles = ['lesson' => 70, 'blog_post' => 8, 'webinar' => 6, 'collection' => 4, 'tntl' => 5, 'event' => 2, 'page' => 2, 'help' => 1, 'highlight' => 1, 'constituency' => 1];
        $roll = mt_rand(1, 100);
        $bundle = 'lesson';
        $acc = 0;
        foreach ($bundles as $b => $w) {
            $acc += $w;
            if ($roll <= $acc) {
                $bundle = $b;
                break;
            }
        }
        $titleWords = mt_rand(3, 9);
        $bodyWords = (int) min(600, max(8, round(exp(mt_rand(300, 460) / 100))));
        $title = ucfirst($this->words($titleWords));
        $body = '<p>' . $this->words($bodyWords) . '</p>';
        if ($edit > 0) {
            $body .= '<p>revision ' . $edit . ' updated paragraph ' . str_repeat('fu', $edit) . 'ba</p>';
        }
        $slug = strtolower(str_replace(' ', '-', $title));
        $url = '/' . $bundle . '/' . $slug . '-' . $n;

        $filters = ['content_type' => ucfirst(str_replace('_', ' ', $bundle))];
        if ($bundle === 'lesson') {
            $filters['subject'] = [];
            $k = mt_rand(1, 4);
            for ($i = 0; $i < $k; $i++) {
                $filters['subject'][] = 'Subject ' . mt_rand(1, 3958);
            }
            $filters['grade'] = [];
            $k = mt_rand(1, 3);
            for ($i = 0; $i < $k; $i++) {
                $filters['grade'][] = 'Grade ' . mt_rand(1, 20);
            }
            $filters['resource_type'] = ['Type ' . mt_rand(1, 14)];
            if (mt_rand(1, 100) <= 30) {
                $filters['audience'] = ['Audience ' . mt_rand(1, 5)];
            }
            $filters['verified_standard'] = ['State ' . mt_rand(1, 4)];
            if (mt_rand(1, 100) <= 60) {
                $filters['standard_authority'] = ['Authority ' . mt_rand(1, 60)];
            }
        }
        $date = date('Y-m-d', 1_400_000_000 + mt_rand(0, 380_000_000));
        $meta = [
            'entity_type' => 'node',
            'entity_id' => (string) $n,
            'langcode' => 'en',
            'bundle' => $bundle,
            'bundle_label' => ucfirst($bundle),
            'subtitle' => 'Presentation • Grades K-2',
            'rating' => (string) mt_rand(0, 5),
            'image_src' => '/sites/default/files/images/' . $slug . '.jpg',
            'image_webp' => '/sites/default/files/styles/card/public/images/' . $slug . '.jpg.webp?itok=' . substr(md5((string) $n), 0, 8),
            'image_nowebp' => '/sites/default/files/styles/card_nowebp/public/images/' . $slug . '.jpg?itok=' . substr(md5((string) $n), 8, 8),
            'author_name' => 'Author ' . mt_rand(1, 5000),
            'author_url' => '/user/' . mt_rand(1, 5000),
            'author_type' => 'user',
        ];

        return new ContentItem(
            id: (string) $n,
            title: $title,
            bodyHtml: $body,
            url: $url,
            date: $date,
            siteName: 'Share My Lesson [Local]',
            language: 'en',
            filters: $filters,
            metadata: $meta,
        );
    }

    /** @return \Generator<ContentItem> */
    public function items(): \Generator
    {
        for ($n = 1; $n <= $this->pages; $n++) {
            yield $this->item($n);
        }
    }

    /** What a warm adapter build hands the orchestrator: one reference per unchanged entity, no body. */
    public function cachedReference(ContentItem $item): CachedContentReference
    {
        return new CachedContentReference(
            entityKey: $item->id,
            contentHash: PhpIndexer::contentHash($item),
            id: $item->id,
            url: $item->url,
            date: $item->date,
            siteName: $item->siteName,
            language: $item->language,
            filters: $item->filters,
            sortable: $item->sortable,
            metadata: $item->metadata,
        );
    }

    /** @return \Generator<CachedContentReference> */
    public function cachedReferences(): \Generator
    {
        foreach ($this->items() as $item) {
            yield $this->cachedReference($item);
        }
    }
}
