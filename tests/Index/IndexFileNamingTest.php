<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\IndexFileNaming;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * The contract that makes a published index safe to put a cache in front of.
 *
 * Every file in the directory is fetched by URL from a browser, and the only
 * thing standing between a reader and a stale copy is the filename: if a name
 * can outlive the bytes it referred to, a cache that still holds the old bytes
 * answers for the new name and nothing on either side can tell. So the
 * invariant is a global one, across builds rather than within one — a name
 * never refers to two different byte strings — and that is what these assert.
 */
#[CoversClass(IndexFileNaming::class)]
final class IndexFileNamingTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::removeDir($dir);
        }
        $this->dirs = [];
    }

    public function testFragmentNameChangesWhenOnlyTheContentsChange(): void
    {
        $before = IndexFileNaming::fragmentHash(7, '/a', '{"meta":{}}');
        $after  = IndexFileNaming::fragmentHash(7, '/a', '{"meta":{"title":"A"}}');

        $this->assertNotSame(
            $before,
            $after,
            'A fragment rewritten with new metadata kept its filename, so a cached copy of '
            . 'the old one stays reachable under a name the new pf_meta points at.',
        );
    }

    public function testFragmentNameIsStableForIdenticalContents(): void
    {
        $this->assertSame(
            IndexFileNaming::fragmentHash(7, '/a', '{"meta":{}}'),
            IndexFileNaming::fragmentHash(7, '/a', '{"meta":{}}'),
            'Naming must be a function of its inputs alone, or an unchanged page churns '
            . 'its filename on every build and no cache ever warms.',
        );
    }

    public function testTombstoneFragmentsStayDistinctPerOrdinal(): void
    {
        // Every tombstone holds the same bytes. Naming them by contents alone
        // would collapse them onto one file, and then releasing one ordinal
        // would delete the fragment another ordinal's pf_meta row still names.
        $this->assertNotSame(
            IndexFileNaming::fragmentHash(7, '', '{"url":""}'),
            IndexFileNaming::fragmentHash(8, '', '{"url":""}'),
            'Two ordinals holding identical bytes share a filename.',
        );
    }

    public function testChunkNameChangesWhenOnlyThePostingsChange(): void
    {
        $words = ['alpha', 'beta'];

        $this->assertNotSame(
            IndexFileNaming::chunkHash($words, 'postings-v1'),
            IndexFileNaming::chunkHash($words, 'postings-v2'),
            'A chunk whose word list is unchanged but whose postings moved kept its '
            . 'filename, which is the case a full rebuild hits for nearly every chunk.',
        );
    }

    /**
     * The end-to-end form, and the one that reproduces the reported failure.
     *
     * Two builds of the same corpus with edited bodies. Any filename the two
     * published directories share must hold the same bytes in both — that is
     * the whole cache contract, stated directly. On a corpus this size the
     * pre-fix naming reuses nearly every fragment and chunk name with changed
     * contents, so this fails loudly rather than marginally.
     */
    public function testNoFilenameIsReusedForDifferentBytesAcrossBuilds(): void
    {
        $first  = $this->buildIndex(revision: 0);
        $second = $this->buildIndex(revision: 1);

        $shared = array_intersect_key($first, $second);
        $this->assertNotEmpty(
            $shared,
            'The two builds shared no filenames at all, so this proves nothing — '
            . 'the fixture stopped exercising the case it was written for.',
        );

        $reused = [];
        foreach ($shared as $name => $bytes) {
            if ($bytes !== $second[$name]) {
                $reused[] = $name;
            }
        }

        $this->assertSame(
            [],
            $reused,
            sprintf(
                '%d of %d shared filenames hold different bytes in the two builds. A cache '
                . 'holding the first build serves those files for the second: %s',
                count($reused),
                count($shared),
                implode(', ', array_slice($reused, 0, 5)) . (count($reused) > 5 ? ', …' : ''),
            ),
        );
    }

    /**
     * Build a corpus and return every published fragment and chunk by name.
     *
     * @return array<string, string> "fragment/en_xxx.pf_fragment" => raw bytes
     */
    private function buildIndex(int $revision): array
    {
        $stateDir  = sys_get_temp_dir() . '/scolta-naming-state-' . uniqid();
        $outputDir = sys_get_temp_dir() . '/scolta-naming-out-' . uniqid();
        mkdir($stateDir, 0755, true);
        mkdir($outputDir, 0755, true);
        $this->dirs[] = $stateDir;
        $this->dirs[] = $outputDir;

        $items = [];
        for ($i = 1; $i <= 120; $i++) {
            // Half the corpus is edited between builds, so the second build
            // shares plenty of filenames with the first while the bytes behind
            // a good many of them have moved.
            $item                = SyntheticCorpus::item($i, seed: 11, revision: $i % 2 === 0 ? $revision : 0);
            $items[$item->id]    = $item;
        }

        $indexer = new PhpIndexer($stateDir, $outputDir);
        foreach (array_chunk($items, 40, true) as $i => $chunk) {
            $indexer->processChunk($chunk, $i, count($items));
        }
        $result = $indexer->finalize();
        $this->assertTrue($result->success, 'Fixture build failed: ' . ($result->error ?? ''));

        $files = [];
        foreach (['fragment/*.pf_fragment', 'index/*.pf_index'] as $glob) {
            foreach (glob($outputDir . '/pagefind/' . $glob) ?: [] as $path) {
                $files[basename(dirname($path)) . '/' . basename($path)] = (string) file_get_contents($path);
            }
        }
        $this->assertNotEmpty($files, 'Fixture build published no fragments or chunks.');

        return $files;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
