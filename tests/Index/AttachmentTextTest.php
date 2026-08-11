<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\InvertedIndexBuilder;
use Tag1\Scolta\Index\PfIndexCodec;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\Tokenizer;

/**
 * Attachment text is indexed in its own weight bucket and can carry an excerpt.
 *
 * The two properties under test pull in opposite directions and are easy to
 * break independently: the text has to score *lower* than body text, which
 * wants a separate bucket, and it has to be *excerptable*, which requires its
 * positions to stay inside the same word sequence the fragment content is
 * split on. Position order is what reconciles them.
 */
class AttachmentTextTest extends TestCase
{
    private const BODY_WEIGHT       = 25;
    private const ATTACHMENT_WEIGHT = 13;

    private InvertedIndexBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InvertedIndexBuilder(new Tokenizer(), new Stemmer('en'));
    }

    private function item(string $body, string $attachment): ContentItem
    {
        return new ContentItem(
            'test-1',
            'Photosynthesis',
            $body,
            '/lesson/plants',
            '2026-08-11',
            attachmentText: $attachment,
        );
    }

    /**
     * Attachment positions must sit between the body and the URL.
     *
     * Pagefind builds an excerpt by splitting the fragment content on
     * whitespace and indexing into it by match position. URL tokens are
     * deliberately numbered past wordCount so that builder cannot reach them;
     * attachment tokens placed after them would inherit that, and a match in
     * an attachment would silently excerpt nothing.
     */
    public function testAttachmentPositionsFallBetweenBodyAndUrl(): void
    {
        $tokenData = $this->builder->tokenizeItem(
            $this->item('<p>Leaves capture sunlight.</p>', 'Chloroplasts absorb photons.'),
        );

        $this->assertNotNull($tokenData);

        $lastBody   = max(array_map(fn($t) => $t->position, $tokenData['bodyTokens']));
        $attachment = array_map(fn($t) => $t->position, $tokenData['attachmentTokens']);
        $firstUrl   = min(array_map(fn($t) => $t->position, $tokenData['urlTokens']));

        $this->assertNotSame([], $attachment, 'Attachment text produced no tokens.');
        $this->assertGreaterThan($lastBody, min($attachment), 'Attachment tokens overlap the body.');
        $this->assertLessThan($firstUrl, max($attachment), 'Attachment tokens were pushed past the URL.');
        $this->assertSame(
            range(min($attachment), max($attachment)),
            $attachment,
            'Attachment positions must be contiguous or the excerpt slices the wrong words.',
        );
    }

    /**
     * The excerpt source is the fragment content, so the text has to be in it.
     */
    public function testAttachmentTextIsAppendedToFragmentContent(): void
    {
        $tokenData = $this->builder->tokenizeItem(
            $this->item('<p>Leaves capture sunlight.</p>', 'Chloroplasts absorb photons.'),
        );

        $this->assertNotNull($tokenData);
        $this->assertStringContainsString('Chloroplasts absorb photons.', $tokenData['content']);
        $this->assertStringContainsString('Leaves capture sunlight.', $tokenData['content']);
    }

    /**
     * Pagefind divides by word_count for length normalization, so text that is
     * present in content has to be counted or every page carrying an
     * attachment reads as artificially dense.
     */
    public function testWordCountIncludesAttachmentTokens(): void
    {
        $without = $this->builder->tokenizeItem($this->item('<p>Leaves capture sunlight.</p>', ''));
        $with    = $this->builder->tokenizeItem(
            $this->item('<p>Leaves capture sunlight.</p>', 'Chloroplasts absorb photons.'),
        );

        $this->assertNotNull($without);
        $this->assertNotNull($with);
        $this->assertSame(
            $without['wordCount'] + count($with['attachmentTokens']),
            $with['wordCount'],
        );
    }

    /**
     * A word only in the attachment must be findable, and must land in the
     * lighter bucket rather than counting as body text.
     */
    public function testAttachmentTokensIndexIntoTheirOwnWeightBucket(): void
    {
        $result = $this->builder->build([
            $this->item('<p>Leaves capture sunlight.</p>', 'Chloroplasts absorb photons.'),
        ]);

        $stemmed = array_keys($result['index']);
        $this->assertContains('chloroplast', $stemmed, 'Attachment-only word is not searchable.');

        $buckets = $result['index']['chloroplast'][0]['positions'];
        $this->assertSame([self::ATTACHMENT_WEIGHT], array_keys($buckets));

        $bodyBuckets = $result['index']['sunlight'][0]['positions'];
        $this->assertSame([self::BODY_WEIGHT], array_keys($bodyBuckets));
    }

    /**
     * Both buckets on one term must survive encode -> decode intact. A page
     * whose word appears in body and attachment is the case that regressed
     * silently while the encoder flattened every bucket into one.
     */
    public function testMultipleWeightBucketsSurviveTheRoundTrip(): void
    {
        $terms = [
            'photon' => [
                3 => [
                    'positions' => [
                        self::BODY_WEIGHT       => [4, 9],
                        self::ATTACHMENT_WEIGHT => [21, 30, 31],
                    ],
                    'meta_positions' => [1],
                ],
            ],
        ];

        $decoded = PfIndexCodec::decodeChunk(PfIndexCodec::encodeChunk(new CborEncoder(), $terms));

        $this->assertSame([4, 9], $decoded['photon'][3]['positions'][self::BODY_WEIGHT]);
        $this->assertSame([21, 30, 31], $decoded['photon'][3]['positions'][self::ATTACHMENT_WEIGHT]);
        $this->assertSame([1], $decoded['photon'][3]['meta_positions']);
    }

    /**
     * The body bucket has to encode exactly as it did before this feature, or
     * every existing index needs rebuilding for a page that has no attachment.
     */
    public function testBodyOnlyPagesEncodeUnchanged(): void
    {
        $cbor  = new CborEncoder();
        $terms = ['alpha' => [3 => ['positions' => [self::BODY_WEIGHT => [0, 4, 9]], 'meta_positions' => [1]]]];

        $decoded = PfIndexCodec::decodeChunk(PfIndexCodec::encodeChunk($cbor, $terms));

        $this->assertSame([0, 4, 9], $decoded['alpha'][3]['positions'][self::BODY_WEIGHT]);
        $this->assertSame([self::BODY_WEIGHT], array_keys($decoded['alpha'][3]['positions']));
    }

    /**
     * Token data is cached under the content hash. If the hash ignored
     * attachment text, replacing an attachment would serve the old tokens.
     */
    public function testContentHashRespondsToAttachmentText(): void
    {
        $base = $this->item('<p>Leaves capture sunlight.</p>', '');

        $this->assertNotSame(
            PhpIndexer::contentHash($base),
            PhpIndexer::contentHash($base->cloneWith(['attachmentText' => 'Chloroplasts absorb photons.'])),
        );
    }

    /**
     * cloneWith() is the documented way to enrich an item; a field it forgets
     * is dropped silently at the call site that looks most correct.
     */
    public function testCloneWithCarriesAttachmentTextForward(): void
    {
        $item = $this->item('<p>Leaves capture sunlight.</p>', 'Chloroplasts absorb photons.');

        $this->assertSame(
            'Chloroplasts absorb photons.',
            $item->cloneWith(['title' => 'Changed'])->attachmentText,
        );
    }
}
