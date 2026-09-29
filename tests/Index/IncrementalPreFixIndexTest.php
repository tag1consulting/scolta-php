<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\CborDecoder;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\IncrementalIndexUpdater;
use Tag1\Scolta\Index\IncrementalUpdateUnavailable;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * An index built before terms were ordered by byte order has a chunk list
 * Pagefind cannot navigate, and routing new terms into it would only spread
 * the damage. The updater must refuse it, so the caller falls back to the
 * full build that replaces it.
 */
#[CoversClass(IncrementalIndexUpdater::class)]
final class IncrementalPreFixIndexTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/scolta-prefix-index-' . uniqid();
        mkdir($this->base . '/state', 0755, true);
        mkdir($this->base . '/out', 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->base);
    }

    public function testAScrambledChunkListRefusesTheUpdateAndAFullBuildRepairsIt(): void
    {
        $items = SyntheticCorpus::generate(20, seed: 3);
        $this->fullBuild($items);

        // The shape a pre-fix build left: ints first, compared numerically, so
        // the first chunk's from sorts after its to as a string.
        $metaPath = $this->metaPath();
        $meta     = CborDecoder::decodeArtifact($metaPath);
        $meta[2][0][0] = '2';
        $meta[2][0][1] = '100';
        file_put_contents($metaPath, gzencode('pagefind_dcd' . self::encode(new CborEncoder(), $meta)));
        $scrambled = (string) file_get_contents($metaPath);

        $edited  = SyntheticCorpus::item(5, seed: 3, revision: 1);
        $updater = new IncrementalIndexUpdater($this->base . '/state', $this->base . '/out');
        $updater->stageUpsert($edited);

        try {
            $updater->commit();
            $this->fail('An update against a scrambled chunk list must be refused.');
        } catch (IncrementalUpdateUnavailable $e) {
            $this->assertStringContainsString('predates byte-order term chunks', $e->getMessage());
            $this->assertStringContainsString('chunk 0 has from "2" after to "100"', $e->getMessage());
        }

        $this->assertSame($scrambled, (string) file_get_contents($metaPath), 'A refused update must not touch the index.');

        // The fallback: a full build replaces the index, and the next
        // incremental update is accepted.
        $items[4] = $edited;
        $this->fullBuild($items);

        $updater = new IncrementalIndexUpdater($this->base . '/state', $this->base . '/out');
        $updater->stageUpsert(SyntheticCorpus::item(5, seed: 3, revision: 2));
        $this->assertSame(1, $updater->commit()->pagesUpdated);
    }

    /**
     * @param list<\Tag1\Scolta\Export\ContentItem> $items
     */
    private function fullBuild(array $items): void
    {
        $result = (new IndexBuildOrchestrator($this->base . '/state', $this->base . '/out'))->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()),
            $items,
        );
        $this->assertTrue($result->success, 'Full build failed: ' . ($result->error ?? ''));
    }

    private function metaPath(): string
    {
        $paths = glob($this->base . '/out/pagefind/pagefind.*.pf_meta') ?: [];
        $this->assertCount(1, $paths);

        return $paths[0];
    }

    /** Re-encode a decoded pf_meta value; it holds only arrays, ints and strings. */
    private static function encode(CborEncoder $cbor, mixed $value): string
    {
        return match (true) {
            is_array($value)  => $cbor->encodeArray(array_map(
                static fn(mixed $v): string => self::encode($cbor, $v),
                array_values($value),
            )),
            is_int($value)    => $value >= 0 ? $cbor->encodeUint($value) : $cbor->encodeNegInt($value),
            is_string($value) => $cbor->encodeString($value),
            default           => throw new \LogicException('Unexpected value in pf_meta: ' . get_debug_type($value)),
        };
    }
}
