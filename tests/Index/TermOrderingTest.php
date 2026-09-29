<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\ChunkReader;
use Tag1\Scolta\Index\ChunkWriter;
use Tag1\Scolta\Index\TermOrder;

/**
 * One collection, one ordering: byte order on the string form.
 *
 * Pagefind selects a `pf_index` chunk by comparing the query term with each
 * chunk's `from` and `to` as strings. A term that looks numeric ("41", "1812")
 * becomes an int array key, and PHP's `<=>` then mixes numeric and string
 * comparison, which is neither transitive nor able to tell "01" from "1". So
 * every step orders terms with TermOrder, and ChunkWriter is where a partial
 * chunk's stream gets that order.
 */
#[CoversClass(ChunkWriter::class)]
#[CoversClass(TermOrder::class)]
final class TermOrderingTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/scolta-termorder-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * Numeric-looking, digit-leading and alphabetic terms together, which is
     * what any real corpus produces.
     *
     * @return list<string>
     */
    private static function mixedVocabulary(): array
    {
        return ['part', '41', 'alpha', '250', '9', 'beta', '10', '10th', 'zulu', '01', '1', '0001', '2nd', 'x', '2024'];
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

    public function testChunkWriterEmitsTermsInByteOrderExactlyOnce(): void
    {
        (new ChunkWriter())->write($this->path, self::partialWithMixedTerms());

        $written = [];
        foreach ((new ChunkReader($this->path))->openIndex() as [$term, $_]) {
            $written[] = $term;
        }

        $expected = self::mixedVocabulary();
        sort($expected, SORT_STRING);

        $this->assertSame($expected, $written);
    }

    public function testCompareIsByteOrderWhereStandardComparisonIsNotAnOrder(): void
    {
        // PHP's <=> gives 9 < 10, 10 < "10th" and "10th" < 9: a cycle.
        $this->assertLessThan(0, TermOrder::compare(10, '10th'));
        $this->assertLessThan(0, TermOrder::compare('10th', 9));
        $this->assertLessThan(0, TermOrder::compare(10, 9));

        // <=> calls these equal; they are different terms.
        $this->assertNotSame(0, TermOrder::compare('01', 1));
        $this->assertNotSame(0, TermOrder::compare('0001', '1'));

        $this->assertSame(0, TermOrder::compare(1812, '1812'));
    }

    public function testChunkListViolationNamesTheFirstBadChunk(): void
    {
        $ok = [
            ['from' => '0001', 'to' => '1812', 'hash' => 'a'],
            ['from' => '2nd', 'to' => 'beta', 'hash' => 'b'],
            ['from' => 'part', 'to' => 'zulu', 'hash' => 'c'],
        ];
        $this->assertNull(TermOrder::chunkListViolation($ok));

        $inverted = $ok;
        $inverted[1] = ['from' => '2', 'to' => '100', 'hash' => 'b'];
        $this->assertStringContainsString('chunk 1', (string) TermOrder::chunkListViolation($inverted));

        $overlapping = $ok;
        $overlapping[2] = ['from' => 'alpha', 'to' => 'zulu', 'hash' => 'c'];
        $this->assertStringContainsString('chunk 1', (string) TermOrder::chunkListViolation($overlapping));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('chunk 1 has from "2" after to "100"');
        TermOrder::assertChunkListOrdered($inverted);
    }
}
