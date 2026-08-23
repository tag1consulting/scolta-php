<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Benchmark\Support;

/**
 * Structural equivalence of two published `pagefind/` directories.
 *
 * Two indexes are equivalent when the same file names exist under `index/`,
 * `fragment/` and `filter/`, alongside the same `pf_meta` and `scolta.facets`,
 * every pair decompresses to identical bytes, and `pagefind-entry.json`
 * decodes to the same array.
 *
 * The comparison is on the decompressed bytes deliberately: the compression
 * level is not part of the format, so a build that trades a level for wall
 * clock produces different files carrying the same index, and comparing the
 * compressed bytes would report that as a regression.
 *
 * @since 1.3.1
 * @stability experimental
 */
final class IndexDirectoryComparer
{
    /** @return list<string> differences, empty when equivalent */
    public static function differences(string $a, string $b): array
    {
        $list = static function (string $d): array {
            $files = array_merge(
                glob("$d/*.pf_meta") ?: [],
                glob("$d/scolta.facets") ?: [],
                glob("$d/index/*") ?: [],
                glob("$d/fragment/*") ?: [],
                glob("$d/filter/*") ?: [],
            );
            $rel = array_map(static fn(string $p): string => substr($p, strlen($d) + 1), $files);
            sort($rel);

            return $rel;
        };
        $la = $list($a);
        $lb = $list($b);
        $out = [];
        foreach (array_diff($la, $lb) as $f) {
            $out[] = "only in first: $f";
        }
        foreach (array_diff($lb, $la) as $f) {
            $out[] = "only in second: $f";
        }
        foreach (array_intersect($la, $lb) as $f) {
            if (gzdecode((string) file_get_contents("$a/$f")) !== gzdecode((string) file_get_contents("$b/$f"))) {
                $out[] = "content differs: $f";
            }
        }
        $ea = json_decode((string) file_get_contents("$a/pagefind-entry.json"), true);
        $eb = json_decode((string) file_get_contents("$b/pagefind-entry.json"), true);
        if ($ea !== $eb) {
            $out[] = 'pagefind-entry.json differs';
        }

        return $out;
    }
}
