<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PfIndexCodec;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * `patchEntry()` against the obvious implementation, on real chunks.
 *
 * The fast path splices bytes into a delta-coded posting run without decoding
 * it. The slow path decodes the entry, mutates PHP arrays and re-encodes. They
 * must agree byte for byte, for every shape of change, or an incremental update
 * publishes an index a full rebuild would not reproduce — and the failure mode
 * is a searchable index with subtly wrong postings rather than an error, which
 * is exactly the kind of bug a randomised differential test exists to catch.
 *
 * The cases are generated rather than enumerated because the interesting ones
 * are positional: a delete at the head of the run moves the next item's delta, a
 * delete in the middle moves one delta, an insert past the end moves none, and
 * the tail-slice shortcut only fires once nothing staged is left.
 */
#[CoversClass(PfIndexCodec::class)]
final class PfIndexCodecPatchTest extends TestCase
{
    /** Cases per shape of change. The prototype this replaces passed 300 of 300. */
    private const CASES_PER_SHAPE = 70;

    private string $base;
    private CborEncoder $cbor;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/scolta-patch-' . uniqid('', true);
        $this->cbor = new CborEncoder();
        mkdir($this->base . '/state', 0755, true);
        mkdir($this->base . '/out', 0755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->base)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->base);
    }

    /**
     * Build a real index and return its chunk bodies, delimiter and gzip stripped.
     *
     * Real chunks rather than hand-built ones: the shapes that matter — a term
     * on one page, a term on hundreds, multi-bucket position lists, meta
     * positions present and absent — are produced by the writer, and a fixture
     * would only exercise the ones the author remembered.
     *
     * @return list<string>
     */
    private function chunkBodies(): array
    {
        $orchestrator = new IndexBuildOrchestrator($this->base . '/state', $this->base . '/out');
        $result       = $orchestrator->build(
            BuildIntent::fresh(60, MemoryBudget::conservative()),
            SyntheticCorpus::generate(60),
        );
        $this->assertTrue($result->success, 'Build failed: ' . ($result->error ?? ''));

        $bodies = [];
        foreach (glob($this->base . '/out/pagefind/index/*.pf_index') ?: [] as $path) {
            $raw = (string) gzdecode((string) file_get_contents($path));
            if (str_starts_with($raw, 'pagefind_dcd')) {
                $raw = substr($raw, strlen('pagefind_dcd'));
            }
            $bodies[] = $raw;
        }
        $this->assertNotEmpty($bodies, 'The build produced no index chunks.');

        return $bodies;
    }

    /**
     * The obvious implementation: decode, mutate the arrays, re-encode.
     *
     * Mirrors what IncrementalIndexUpdater::applyTermDeltas() did before the
     * byte-level patch, including the variant handling, because that behaviour
     * is what has to be preserved.
     *
     * @param array<int|string, mixed>         $entry
     * @param list<int>                        $removals
     * @param array<int, array<string, mixed>> $additions
     * @param array<string, list<int>>         $variantAdds
     */
    private function reference(
        string $word,
        array $entry,
        array $removals,
        array $additions,
        array $variantAdds,
    ): ?string {
        foreach ($removals as $ordinal) {
            unset($entry[$ordinal]);
            if (isset($entry['_variants'])) {
                foreach ($entry['_variants'] as $form => $ordinals) {
                    $kept = array_values(array_filter($ordinals, static fn(int $o): bool => $o !== $ordinal));
                    if ($kept === []) {
                        unset($entry['_variants'][$form]);
                    } else {
                        $entry['_variants'][$form] = $kept;
                    }
                }
                if ($entry['_variants'] === []) {
                    unset($entry['_variants']);
                }
            }
        }

        foreach ($additions as $ordinal => $pageEntry) {
            $entry[$ordinal] = $pageEntry;
        }

        foreach ($variantAdds as $form => $ordinals) {
            $merged = array_merge($entry['_variants'][$form] ?? [], $ordinals);
            sort($merged);
            $entry['_variants'][$form] = array_values(array_unique($merged));
        }

        $pageCount = count($entry) - (isset($entry['_variants']) ? 1 : 0);
        if ($pageCount === 0) {
            return null;
        }

        return PfIndexCodec::encodeWordEntry($this->cbor, $word, $entry);
    }

    /** A plausible new posting for an ordinal: one body bucket, sometimes a title hit. */
    private function newPageEntry(int $seed): array
    {
        $positions = [];
        $count     = 1 + $seed % 4;
        $at        = $seed % 7;
        for ($i = 0; $i < $count; $i++) {
            $positions[] = $at;
            $at         += 1 + ($seed + $i) % 5;
        }

        $entry = ['positions' => [25 => $positions], 'meta_positions' => []];
        if ($seed % 3 === 0) {
            $entry['meta_positions'] = [$seed % 4];
        }
        if ($seed % 5 === 0) {
            // A second weight bucket, which is the case that only exists since
            // attachment text became its own channel.
            $entry['positions'][13] = [$seed % 6, $seed % 6 + 2];
        }

        return $entry;
    }

    public function testPatchEntryMatchesDecodeMutateEncodeAcrossManyRandomCases(): void
    {
        $bodies = $this->chunkBodies();

        // Deterministic: a differential test that fails once a week on a seed
        // nobody recorded is worse than no test.
        mt_srand(20260823);

        $shapes   = ['delete', 'insert', 'replace', 'insert-past-end', 'variant-add', 'mixed'];
        $compared = 0;

        foreach ($shapes as $shape) {
            for ($case = 0; $case < self::CASES_PER_SHAPE; $case++) {
                $body    = $bodies[mt_rand(0, count($bodies) - 1)];
                $rawMap  = PfIndexCodec::splitEntries($body);
                $decoded = PfIndexCodec::decodeChunk($body);

                $words = array_keys($rawMap);
                $word  = (string) $words[mt_rand(0, count($words) - 1)];

                $entry    = $decoded[$word];
                $ordinals = array_values(array_filter(
                    array_keys($entry),
                    static fn($k): bool => $k !== '_variants',
                ));
                if ($ordinals === []) {
                    continue;
                }
                sort($ordinals);
                $maxOrdinal = (int) max($ordinals);

                $removals    = [];
                $additions   = [];
                $variantAdds = [];

                switch ($shape) {
                    case 'delete':
                        // Sometimes the first posting, sometimes the last, mostly
                        // the middle: each moves a different delta.
                        $pick       = match (mt_rand(0, 2)) {
                            0       => 0,
                            1       => count($ordinals) - 1,
                            default => mt_rand(0, count($ordinals) - 1),
                        };
                        $removals[] = (int) $ordinals[$pick];
                        break;

                    case 'insert':
                        // Between two existing postings, so the following item's
                        // delta has to shrink.
                        if ($maxOrdinal < 2) {
                            continue 2;
                        }
                        $candidate = mt_rand(0, $maxOrdinal);
                        if (in_array($candidate, array_map(intval(...), $ordinals), true)) {
                            continue 2;
                        }
                        $additions[$candidate] = $this->newPageEntry($case);
                        break;

                    case 'replace':
                        $target             = (int) $ordinals[mt_rand(0, count($ordinals) - 1)];
                        $additions[$target] = $this->newPageEntry($case + 1);
                        break;

                    case 'insert-past-end':
                        $additions[$maxOrdinal + 1 + mt_rand(0, 50)] = $this->newPageEntry($case + 2);
                        break;

                    case 'variant-add':
                        $variantAdds['running'] = [(int) $ordinals[mt_rand(0, count($ordinals) - 1)]];
                        if (mt_rand(0, 1) === 1) {
                            $variantAdds['runs'] = [$maxOrdinal + 1];
                        }
                        break;

                    case 'mixed':
                    default:
                        $removals[] = (int) $ordinals[mt_rand(0, count($ordinals) - 1)];
                        $spare      = $maxOrdinal + 1 + mt_rand(0, 20);
                        $additions[$spare] = $this->newPageEntry($case + 3);
                        if (count($ordinals) > 2) {
                            $target             = (int) $ordinals[mt_rand(0, count($ordinals) - 1)];
                            $additions[$target] = $this->newPageEntry($case + 4);
                        }
                        $variantAdds['running'] = [$spare];
                        break;
                }

                $expected = $this->reference($word, $entry, $removals, $additions, $variantAdds);
                $actual   = PfIndexCodec::patchEntry($this->cbor, $rawMap[$word], $removals, $additions, $variantAdds);

                $this->assertSame(
                    $expected === null ? null : bin2hex($expected),
                    $actual === null ? null : bin2hex($actual),
                    sprintf(
                        'patchEntry() disagreed with decode-mutate-encode. shape=%s case=%d word=%s '
                        . 'removals=%s additions=%s variants=%s',
                        $shape,
                        $case,
                        $word,
                        json_encode($removals),
                        json_encode(array_keys($additions)),
                        json_encode(array_keys($variantAdds)),
                    ),
                );
                $compared++;
            }
        }

        $this->assertGreaterThanOrEqual(
            300,
            $compared,
            'The differential sweep compared fewer cases than it claims to.',
        );
    }

    public function testRemovingEveryPostingLeavesNoEntry(): void
    {
        $bodies = $this->chunkBodies();
        $rawMap = PfIndexCodec::splitEntries($bodies[0]);
        $terms  = PfIndexCodec::decodeChunk($bodies[0]);

        foreach ($terms as $word => $entry) {
            $ordinals = array_values(array_filter(
                array_keys($entry),
                static fn($k): bool => $k !== '_variants',
            ));
            $patched = PfIndexCodec::patchEntry(
                $this->cbor,
                $rawMap[(string) $word],
                array_map(intval(...), $ordinals),
                [],
                [],
            );

            // A term whose last posting went away has left the vocabulary, which
            // is the one way an update shrinks a chunk's word list.
            $this->assertNull($patched, "Term '{$word}' kept an entry after every posting was removed.");
        }
    }

    public function testAnUnchangedEntryPatchesToItsOwnBytes(): void
    {
        // The property the "write only when the bytes changed" check rests on:
        // a patch that stages nothing must reproduce the entry exactly, so a
        // chunk touched only by a term that did not actually move is not
        // rewritten under a new name for nothing.
        $bodies = $this->chunkBodies();

        foreach ($bodies as $body) {
            foreach (PfIndexCodec::splitEntries($body) as $word => $raw) {
                $this->assertSame(
                    bin2hex($raw),
                    bin2hex((string) PfIndexCodec::patchEntry($this->cbor, $raw, [], [], [])),
                    "Patching term '{$word}' with no changes altered its bytes.",
                );
            }
        }
    }

    public function testSplitEntriesAndAssembleChunkRoundTripTheWholeChunk(): void
    {
        foreach ($this->chunkBodies() as $body) {
            $entries = PfIndexCodec::splitEntries($body);
            $this->assertSame(
                bin2hex($body),
                bin2hex(PfIndexCodec::assembleChunk($this->cbor, $entries)),
                'splitEntries() + assembleChunk() did not reproduce the chunk.',
            );
        }
    }

    public function testSplitEntriesAgreesWithTheDecoderOnWhichTermsExist(): void
    {
        foreach ($this->chunkBodies() as $body) {
            $this->assertSame(
                array_map(strval(...), array_keys(PfIndexCodec::decodeChunk($body))),
                array_map(strval(...), array_keys(PfIndexCodec::splitEntries($body))),
                'The byte-level split and the decoder disagree on the chunk word list or its order.',
            );
        }
    }

    public function testANumericLookingTermSurvivesTheSplit(): void
    {
        // PHP turns a decimal-integer array key into an int, so a term like
        // "2024" comes back from array_keys() as int 2024. The chunk hash joins
        // the word list, so losing the string type renames the file.
        $entry = ['positions' => [25 => [0, 3]], 'meta_positions' => []];
        $body  = PfIndexCodec::encodeChunk($this->cbor, [
            '2024' => [7 => $entry],
            'alpha' => [7 => $entry],
        ]);

        $split = PfIndexCodec::splitEntries($body);
        $this->assertSame(['2024', 'alpha'], PfIndexCodec::wordList($split));
        $this->assertSame(bin2hex($body), bin2hex(PfIndexCodec::assembleChunk($this->cbor, $split)));
    }

    public function testAMalformedChunkIsRejectedRatherThanMisread(): void
    {
        $this->expectException(\RuntimeException::class);
        PfIndexCodec::splitEntries("\x82\x80\x80"); // a two-element outer array
    }

    public function testATruncatedChunkIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        PfIndexCodec::splitEntries("\x81\x83"); // an inner array claiming three entries
    }
}
