<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

/**
 * The order of index terms, defined once.
 *
 * Terms are ordered by the byte order of their string form. Pagefind selects
 * the `pf_index` chunk for a query term by comparing it with each chunk's
 * `from` and `to` as Rust strings, which is byte order, so every step that
 * sorts, merges or routes terms has to use exactly this order.
 *
 * PHP's `<=>` is not a substitute. A term such as "1812" becomes an int array
 * key, and `<=>` compares numeric strings numerically and everything else as
 * strings, which is not transitive ("9" < "10", "10" < "10th", "10th" < "9")
 * and calls distinct terms equal ("01" and "1").
 *
 * @since 2.0.0
 * @stability experimental
 */
final class TermOrder
{
    /**
     * Compare two terms by the byte order of their string form.
     *
     * Accepts int because PHP returns a numeric-looking array key as an int.
     *
     * @return int Negative, zero or positive, as strcmp().
     * @since 2.0.0
     * @stability experimental
     */
    public static function compare(int|string $a, int|string $b): int
    {
        return strcmp((string) $a, (string) $b);
    }

    /**
     * The first violation in a `pf_meta` chunk list, or null if there is none.
     *
     * A valid list has `from <= to` in every chunk and each chunk's `from`
     * strictly after the previous chunk's `to`; otherwise Pagefind can never
     * select some chunk, or selects the wrong one.
     *
     * @param list<array{from: string, to: string, hash?: string}> $chunks
     * @since 2.0.0
     * @stability experimental
     */
    public static function chunkListViolation(array $chunks): ?string
    {
        $prev = null;
        foreach ($chunks as $i => $chunk) {
            if (self::compare($chunk['from'], $chunk['to']) > 0) {
                return sprintf('chunk %d has from "%s" after to "%s"', $i, $chunk['from'], $chunk['to']);
            }
            if ($prev !== null && self::compare($prev['to'], $chunk['from']) >= 0) {
                return sprintf(
                    'chunk %d ["%s", "%s"] does not sort before chunk %d ["%s", "%s"]',
                    $i - 1,
                    $prev['from'],
                    $prev['to'],
                    $i,
                    $chunk['from'],
                    $chunk['to'],
                );
            }
            $prev = $chunk;
        }

        return null;
    }

    /**
     * Throw unless the chunk list is ordered as {@see self::chunkListViolation()} requires.
     *
     * @param list<array{from: string, to: string, hash?: string}> $chunks
     * @throws \RuntimeException Naming the first offending chunk.
     * @since 2.0.0
     * @stability experimental
     */
    public static function assertChunkListOrdered(array $chunks): void
    {
        $violation = self::chunkListViolation($chunks);
        if ($violation !== null) {
            throw new \RuntimeException(
                "Index term chunks are out of order: {$violation}. Pagefind could not find the terms in them, "
                . 'so the index must not be published.',
            );
        }
    }
}
