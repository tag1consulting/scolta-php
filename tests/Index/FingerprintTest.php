<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * The corpus fingerprint must move for any edit that changes the built
 * output, and only for those.
 *
 * The v1 formula hashed body (+ attachment) alone, so a title-only or
 * URL-only edit produced an identical fingerprint, shouldBuild() reported
 * "up to date", and the edit never reached the index. Every field asserted
 * here reaches the fragments or the token streams.
 */
class FingerprintTest extends TestCase
{
    private function item(array $overrides = []): ContentItem
    {
        $base = new ContentItem(
            id: 'post-1',
            title: 'Photosynthesis',
            bodyHtml: '<p>Leaves capture sunlight.</p>',
            url: '/lesson/plants',
            date: '2026-08-11',
            siteName: 'Botany',
            language: 'en',
            filters: ['topics' => ['Science', 'Biology'], 'grade' => '5'],
            metadata: ['published' => '2026-08-11'],
            sortable: ['rating' => '4.5'],
            attachmentText: 'Chloroplasts absorb photons.',
        );

        return $base->cloneWith($overrides);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function outputAffectingEdits(): array
    {
        return [
            'title' => [['title' => 'Respiration']],
            'url' => [['url' => '/lesson/plants-2']],
            'siteName' => [['siteName' => 'Zoology']],
            'language' => [['language' => 'es']],
            'date' => [['date' => '2026-08-12']],
            'filters' => [['filters' => ['topics' => ['Science'], 'grade' => '5']]],
            'metadata' => [['metadata' => ['published' => '2026-08-12']]],
            'sortable' => [['sortable' => ['rating' => '4.6']]],
            'bodyHtml' => [['bodyHtml' => '<p>Roots absorb water.</p>']],
            'attachmentText' => [['attachmentText' => 'Stomata regulate gas exchange.']],
        ];
    }

    /**
     * @param array<string, mixed> $edit
     */
    #[DataProvider('outputAffectingEdits')]
    public function testEveryOutputAffectingFieldMovesTheFingerprint(array $edit): void
    {
        $this->assertNotSame(
            PhpIndexer::computeFingerprint([$this->item()]),
            PhpIndexer::computeFingerprint([$this->item($edit)]),
        );
    }

    /**
     * Associative key order in filters/metadata/sortable is presentation
     * jitter from the source CMS, not a content change. Reading it as one
     * would rebuild the corpus every time an exporter iterates a map in a
     * different order.
     */
    public function testAssociativeKeyOrderDoesNotMoveTheFingerprint(): void
    {
        $this->assertSame(
            PhpIndexer::computeFingerprint([$this->item([
                'filters' => ['topics' => ['Science', 'Biology'], 'grade' => '5'],
            ])]),
            PhpIndexer::computeFingerprint([$this->item([
                'filters' => ['grade' => '5', 'topics' => ['Science', 'Biology']],
            ])]),
        );
    }

    /**
     * Multi-value filter order is preserved, not sorted away: each element
     * emits its own filter entry and the exporter's order is part of what it
     * exported. Only associative keys are canonicalized.
     */
    public function testMultiValueFilterOrderMovesTheFingerprint(): void
    {
        $this->assertNotSame(
            PhpIndexer::computeFingerprint([$this->item([
                'filters' => ['topics' => ['Science', 'Biology']],
            ])]),
            PhpIndexer::computeFingerprint([$this->item([
                'filters' => ['topics' => ['Biology', 'Science']],
            ])]),
        );
    }

    /**
     * Sources yield items in whatever order their query returns; two walks
     * over the same corpus must agree.
     */
    public function testItemOrderDoesNotMoveTheFingerprint(): void
    {
        $a = $this->item();
        $b = $this->item(['id' => 'post-2', 'title' => 'Respiration']);

        $this->assertSame(
            PhpIndexer::computeFingerprint([$a, $b]),
            PhpIndexer::computeFingerprint([$b, $a]),
        );
    }

    /**
     * The streaming composition scolta-laravel uses — fingerprintEntry() per
     * item, combineFingerprintEntries() at the end — is the same value as the
     * all-at-once method.
     */
    public function testStreamingCompositionMatchesComputeFingerprint(): void
    {
        $items = [$this->item(), $this->item(['id' => 'post-2', 'title' => 'Respiration'])];

        $this->assertSame(
            PhpIndexer::computeFingerprint($items),
            PhpIndexer::combineFingerprintEntries(
                array_map(PhpIndexer::fingerprintEntry(...), $items),
            ),
        );
    }
}
