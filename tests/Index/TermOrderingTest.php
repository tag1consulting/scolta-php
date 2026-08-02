<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\ChunkReader;
use Tag1\Scolta\Index\ChunkWriter;

/**
 * One collection, one ordering.
 *
 * `IndexMerger::mergeStreaming()` N-way merges each chunk's term stream with an
 * SplMinHeap and groups equal terms by repeatedly taking the heap top, so it
 * requires every stream to be ascending *in the heap's comparison* — PHP's
 * standard comparison. `ChunkWriter` is the only place that establishes that
 * order, and it used `ksort()` with SORT_REGULAR.
 *
 * Those are not the same order on every PHP version. A term that looks numeric
 * ("41" from a title like "Part 41", or a url's trailing id) becomes an integer
 * array key, so the term map has mixed int and string keys:
 *
 *   PHP 8.1   ksort: alpha, beta, part, zulu, 2, 9, 10, 41   (strings first)
 *   PHP 8.2+  ksort: 2, 9, 10, 41, alpha, beta, part, zulu   (ints first)
 *   SplMinHeap on every version:  2, 9, 10, 41, alpha, …
 *
 * On 8.1 the merge therefore consumed unsorted streams, which lets one logical
 * term be emitted more than once and puts terms in the wrong `pf_index` chunk.
 * It produced a subtly wrong index rather than an error, which is why it went
 * unnoticed until something compared bytes.
 *
 * This pins the property rather than the version: whatever PHP does, the order
 * ChunkWriter writes and the order the merge expects must be the same one.
 */
#[CoversClass(ChunkWriter::class)]
final class TermOrderingTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/scolta-termorder-' . uniqid() . '.dat';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * A vocabulary mixing numeric-looking and alphabetic terms, which is what
     * any real corpus produces — "Part 41" alone is enough.
     *
     * @return list<string>
     */
    private static function mixedVocabulary(): array
    {
        return ['part', '41', 'alpha', '250', '9', 'beta', '10', 'zulu', '100', '2', 'x', '2024'];
    }

    /**
     * @return array{index: array<int|string, mixed>, pages: array<int, mixed>}
     */
    private static function partialWithMixedTerms(): array
    {
        $index = [];
        foreach (self::mixedVocabulary() as $term) {
            $index[$term] = [0 => ['positions' => [25 => [0]], 'meta_positions' => []]];
        }

        return [
            'index' => $index,
            'pages' => [0 => [
                'id'        => 'p0',
                'url'       => '/p0',
                'title'     => 'Part 41',
                'content'   => 'Part 41 alpha beta',
                'wordCount' => 4,
                'date'      => '2025-01-01',
                'filters'   => [],
                'meta'      => [],
                'sortable'  => [],
            ]],
        ];
    }

    public function testWrittenTermOrderIsTheOrderTheMergeHeapExpects(): void
    {
        (new ChunkWriter())->write($this->path, self::partialWithMixedTerms());

        $written = [];
        foreach ((new ChunkReader($this->path))->openIndex() as [$term, $_]) {
            $written[] = (string) $term;
        }

        $heap = new \SplMinHeap();
        foreach (self::mixedVocabulary() as $term) {
            // Re-create the int-vs-string key coercion an array performs, so
            // the heap sees exactly what ChunkReader yields.
            $probe = [];
            $probe[$term] = true;
            $heap->insert(array_key_first($probe));
        }
        $expected = [];
        while (!$heap->isEmpty()) {
            $expected[] = (string) $heap->extract();
        }

        $this->assertSame(
            $expected,
            $written,
            'ChunkWriter and IndexMerger must order terms the same way, or the N-way merge '
            . 'consumes unsorted streams and silently misplaces terms.',
        );
    }

    public function testWrittenTermOrderIsAscendingAndHasNoDuplicates(): void
    {
        (new ChunkWriter())->write($this->path, self::partialWithMixedTerms());

        $written = [];
        foreach ((new ChunkReader($this->path))->openIndex() as [$term, $_]) {
            $written[] = $term;
        }

        $this->assertSame(
            count(self::mixedVocabulary()),
            count($written),
            'Every term must be written exactly once.',
        );

        for ($i = 1; $i < count($written); $i++) {
            $this->assertLessThan(
                0,
                $written[$i - 1] <=> $written[$i],
                sprintf(
                    'Term stream must be strictly ascending; %s came before %s.',
                    var_export($written[$i - 1], true),
                    var_export($written[$i], true),
                ),
            );
        }
    }
}
