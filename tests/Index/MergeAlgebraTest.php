<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\IndexMerger;

/**
 * Exhaustive algebraic check of the term-entry combine function.
 *
 * IndexMerger::mergeEntries() is the fold that turns one term's page entries
 * from N chunks into one merged entry. Incremental recomputation is only
 * licensed when that fold is associative, and a retried update is only safe
 * when it is idempotent. Today both properties rest on one comment —
 * "Page numbers are globally unique; no collision possible" — and nothing
 * enforces it.
 *
 * Rather than reason about it, this exhausts the 25 pairs and 125 triples
 * over five representative values (including the degenerate ones: empty,
 * variants-only, and two values that collide on the same page key) and
 * reports the smallest witness for any failure.
 *
 * The commutativity case is the one that matters for the incremental path.
 * It fails, deliberately and by design, and the test pins that: last-write-
 * wins is order-dependent, so any caller that can produce two entries for
 * the same ordinal must resolve them before the fold sees them.
 */
#[CoversClass(IndexMerger::class)]
final class MergeAlgebraTest extends TestCase
{
    private IndexMerger $merger;

    /** @var array<string, array> Named representative values. */
    private array $values;

    protected function setUp(): void
    {
        $this->merger = new IndexMerger();

        $this->values = [
            // Identity candidate / degenerate empty.
            'empty'      => [],
            // Single ordinary page entry.
            'page0'      => [0 => ['positions' => [25 => [3, 7]], 'meta_positions' => []]],
            // SAME page key as page0, different payload: the collision case the
            // production precondition claims cannot happen.
            'page0alt'   => [0 => ['positions' => [25 => [11]], 'meta_positions' => [1]]],
            // Disjoint multi-page entry.
            'pages12'    => [
                1 => ['positions' => [25 => [0]], 'meta_positions' => []],
                2 => ['positions' => [25 => [4, 9]], 'meta_positions' => [0]],
            ],
            // Variants-only: exercises the union half of the fold in isolation.
            'variants'   => ['_variants' => ['café' => [0, 2]]],
        ];
    }

    /**
     * Binary view of the n-ary fold: f(a, b).
     */
    private function combine(array $a, array $b): array
    {
        return $this->invokeMergeEntries([$a, $b]);
    }

    private function invokeMergeEntries(array $allEntries): array
    {
        $method = new \ReflectionMethod(IndexMerger::class, 'mergeEntries');

        return $method->invoke($this->merger, $allEntries);
    }

    /**
     * Equality for the value type.
     *
     * mergeEntries() ksorts its output, so equality is defined up to key
     * order — otherwise f(a, identity) could never equal an unsorted a.
     * Variant page lists are compared as sets for the same reason: the fold
     * builds them with array_unique(array_merge(...)), which preserves first-
     * seen order rather than sorting.
     */
    private function normalize(array $v): array
    {
        $variants = $v['_variants'] ?? null;
        unset($v['_variants']);
        ksort($v, SORT_NUMERIC);

        if ($variants !== null) {
            ksort($variants);
            foreach ($variants as $form => $pages) {
                sort($pages);
                $variants[$form] = $pages;
            }
            $v['_variants'] = $variants;
        }

        return $v;
    }

    private function eq(array $a, array $b): bool
    {
        return $this->normalize($a) == $this->normalize($b);
    }

    public function testCombineIsAssociativeAcrossAll125Triples(): void
    {
        $failures = [];

        foreach ($this->values as $an => $a) {
            foreach ($this->values as $bn => $b) {
                foreach ($this->values as $cn => $c) {
                    $left  = $this->combine($this->combine($a, $b), $c);
                    $right = $this->combine($a, $this->combine($b, $c));
                    if (!$this->eq($left, $right)) {
                        $failures[] = "({$an} ⊕ {$bn}) ⊕ {$cn} ≠ {$an} ⊕ ({$bn} ⊕ {$cn})";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Associativity failed. Smallest witnesses:\n" . implode("\n", array_slice($failures, 0, 5)),
        );
    }

    public function testEmptyIsATwoSidedIdentityAcrossAllValues(): void
    {
        $failures = [];

        foreach ($this->values as $name => $v) {
            if (!$this->eq($this->combine([], $v), $v)) {
                $failures[] = "left identity failed for {$name}";
            }
            if (!$this->eq($this->combine($v, []), $v)) {
                $failures[] = "right identity failed for {$name}";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function testCombineIsIdempotentAcrossAllValues(): void
    {
        $failures = [];

        foreach ($this->values as $name => $v) {
            if (!$this->eq($this->combine($v, $v), $v)) {
                $failures[] = "idempotence failed for {$name}";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * The fold is NOT commutative, and that is the constraint the incremental
     * path has to respect.
     *
     * Two entries for the same page ordinal resolve by last-write-wins, so the
     * result depends on the order the chunk files were listed in. A full build
     * is safe only because InvertedIndexBuilder hands out each ordinal exactly
     * once. An updater that rewrites a page in place breaks that precondition
     * the moment it lets both the old and the new postings reach this fold.
     *
     * If this test ever starts failing because the fold learned to merge
     * colliding entries, the incremental design can be simplified — read this
     * docblock before deleting the test.
     */
    public function testCollidingPageKeysResolveByLastWriteWinsAndSoDependOnOrder(): void
    {
        $a = $this->values['page0'];
        $b = $this->values['page0alt'];

        $ab = $this->combine($a, $b);
        $ba = $this->combine($b, $a);

        $this->assertSame([11], $ab[0]['positions'][25], 'Last entry should win.');
        $this->assertSame([3, 7], $ba[0]['positions'][25], 'Reversing the order changes the result.');
        $this->assertFalse($this->eq($ab, $ba), 'Fold must be order-dependent on a page-key collision.');
    }

    /**
     * The variant half is a set union, so it IS commutative — only the page
     * half carries the ordering hazard.
     */
    public function testVariantUnionIsCommutative(): void
    {
        $a = ['_variants' => ['café' => [0, 2]]];
        $b = ['_variants' => ['café' => [2, 5], 'naïve' => [1]]];

        $this->assertTrue($this->eq($this->combine($a, $b), $this->combine($b, $a)));
    }
}
