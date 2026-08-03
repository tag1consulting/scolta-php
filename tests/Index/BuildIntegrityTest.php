<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\IndexMerger;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\StreamingFormatWriter;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\ChunkWriter;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Tests\Support\CborDecoder;

/**
 * A build that cannot finish must say so, and one that says it finished must
 * have produced an index that answers correctly.
 *
 * The defect these lock down: a build that hit the memory limit committed its
 * chunks, dropped the ordinal assignments that named the pages inside them,
 * and handed the outcome to a detached process. That process re-allocated the
 * same ordinals to different pages, the merge kept one page per ordinal, and
 * the whole chain reported success. Search then returned the wrong documents
 * for terms that were indexed correctly, with no error anywhere.
 *
 * The old resume test asserted only that the fragment *file count* matched a
 * single-pass build. Fragment filenames hash the ordinal together with the
 * url, so colliding ordinals still produce distinct files and that assertion
 * passed throughout. These assert the numbering and the posting lists.
 *
 * @since 1.1.1
 * @stability experimental
 */
class BuildIntegrityTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $uid             = uniqid('', true);
        $this->stateDir  = sys_get_temp_dir() . "/scolta-integrity-state-{$uid}";
        $this->outputDir = sys_get_temp_dir() . "/scolta-integrity-out-{$uid}";
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // -------------------------------------------------------------------
    // A resumed build is the same build
    // -------------------------------------------------------------------

    public function testAResumedBuildProducesTheSameIndexAsASinglePassBuild(): void
    {
        $items  = $this->makeItems(60);
        $budget = MemoryBudget::conservative()->withChunkSize(7);

        $single  = $this->buildSinglePass($items, $budget);
        $resumed = $this->buildAcrossResumeSegments($items, $budget);

        $this->assertGreaterThan(
            1,
            $resumed['segments'],
            'The corpus must actually have been split across processes for this to test anything',
        );

        $this->assertSame(
            $this->pageTable($single['dir']),
            $this->pageTable($resumed['dir']),
            'A resumed build must number pages exactly as a single-pass build does',
        );

        $this->assertSame(
            $this->postingListsByUrl($single['dir']),
            $this->postingListsByUrl($resumed['dir']),
            'A resumed build must resolve every term to the same documents',
        );

        $this->removeDir($single['stateDir']);
        $this->removeDir($single['dir']);
    }

    public function testEveryPageIsFoundByItsOwnTermAfterAResumedBuild(): void
    {
        $items  = $this->makeItems(40);
        $budget = MemoryBudget::conservative()->withChunkSize(6);

        $resumed  = $this->buildAcrossResumeSegments($items, $budget);
        $postings = $this->postingListsByUrl($resumed['dir']);

        // Each page carries a term that occurs in no other page, so a term
        // resolving anywhere else is the collision, stated as a search result
        // rather than as an internal number.
        // The index stores stemmed terms, so ask the same stemmer the indexer
        // used rather than guessing how a marker survives stemming.
        $stemmer = new Stemmer('en');

        foreach ($items as $i => $item) {
            $term = $stemmer->stem(self::marker($i));
            $this->assertArrayHasKey($term, $postings, "Term '{$term}' is missing from the index entirely");
            $this->assertSame(
                [$item->url],
                $postings[$term],
                "Term '{$term}' belongs only to {$item->url} but the index resolves it elsewhere",
            );
        }
    }

    /**
     * The gitmastery reproduction, at the layer the defect lives in.
     *
     * A corpus of known size driven through repeated memory aborts must come
     * out whole. The observed failure was 1150 fragments from 1426 documents,
     * reported as a success.
     */
    public function testALargeCorpusDrivenThroughManyResumeSegmentsLosesNoPages(): void
    {
        $items  = $this->makeItems(1426);
        $budget = MemoryBudget::conservative()->withChunkSize(50);

        $resumed = $this->buildAcrossResumeSegments($items, $budget);

        $this->assertGreaterThan(5, $resumed['segments'], 'Expected the corpus to span many segments');
        $this->assertCount(
            1426,
            glob($resumed['dir'] . '/pagefind/fragment/*.pf_fragment') ?: [],
            'Every document must have a fragment; a short index reported as success is the defect',
        );

        $ledger = new PageTableLedger($this->stateDir, new FilesystemDriver());
        $this->assertSame(1426, $ledger->liveCount());
        $this->assertSame(1426, $ledger->pageTableSize(), 'Ordinals must not have been handed out twice');
    }

    // -------------------------------------------------------------------
    // Failure is loud
    // -------------------------------------------------------------------

    public function testResumingWithoutTheOrdinalAssignmentsFailsInsteadOfCorrupting(): void
    {
        $items  = $this->makeItems(30);
        $budget = MemoryBudget::conservative()->withChunkSize(5);

        // Segment one, then throw away exactly what the pre-fix code failed to
        // keep: the ordinals naming the pages inside the committed chunks.
        $first = $this->runSegment($items, $budget, fresh: true, yieldOnce: true);
        $this->assertSame('memory_abort', $first->error);
        $this->assertGreaterThan(0, $first->chunksWritten);
        $this->stripOrdinalAssignments();

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report       = $orchestrator->build(BuildIntent::resume($budget), $items);

        $this->assertFalse($report->success, 'A build that cannot number its pages must not report success');
        $this->assertNotNull($report->error);
        $this->assertStringContainsString('--restart', (string) $report->error);
    }

    public function testAFragmentCountThatDisagreesWithThePageTableFailsTheBuild(): void
    {
        $items = $this->makeItems(12);

        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $report       = $orchestrator->build(BuildIntent::fresh(12, MemoryBudget::conservative()), $items);
        $this->assertTrue($report->success, (string) $report->error);

        // Deleting a fragment is the shape of every silent-loss bug: the index
        // is short and nothing else on disk says so.
        $fragments = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        unlink($fragments[0]);

        $method = new \ReflectionMethod(IndexBuildOrchestrator::class, 'verifyOutputHasFragments');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must not be served/');
        $method->invoke($orchestrator, count($items));
    }

    public function testTheMergeRefusesTwoChunksThatClaimTheSameOrdinal(): void
    {
        $chunkDir = $this->stateDir . '/collide';
        mkdir($chunkDir, 0755, true);

        $writer = new ChunkWriter();
        foreach (['alpha', 'beta'] as $i => $id) {
            $writer->write($chunkDir . "/chunk-{$i}.dat", [
                // Both chunks call their page ordinal 0, which is precisely
                // what two processes with no shared ledger produce.
                'pages' => [0 => [
                    'id'        => $id,
                    'url'       => "/{$id}",
                    'title'     => ucfirst($id),
                    'content'   => "content for {$id}",
                    'wordCount' => 3,
                    'date'      => '2024-01-01',
                    'filters'   => [],
                    'meta'      => [],
                    'sortable'  => [],
                ]],
                'index' => [],
            ], null);
        }

        $streamWriter = new StreamingFormatWriter(new CborEncoder());
        $streamWriter->beginWrite($this->outputDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Duplicate page ordinal 0 across chunks/');
        (new IndexMerger())->mergeStreaming(
            [$chunkDir . '/chunk-0.dat', $chunkDir . '/chunk-1.dat'],
            $streamWriter,
        );
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * A term unique to page $i, and still unique after stemming.
     *
     * Base-26 over 'a'..'z' behind a prefix no filler prose uses, closed with
     * 'qx' so the stemmer has no ending to strip: without it "zzmarkbe" stems
     * to "zzmarkb" and collides with page 1's marker, which would make this
     * test fail for a reason that has nothing to do with the index.
     */
    private static function marker(int $i): string
    {
        $suffix = '';
        do {
            $suffix = chr(ord('a') + ($i % 26)) . $suffix;
            $i      = intdiv($i, 26);
        } while ($i > 0);

        return 'zzmark' . $suffix . 'qx';
    }

    /** @return list<ContentItem> */
    private function makeItems(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = new ContentItem(
                id: 'page-' . $i,
                title: 'Page ' . $i,
                // The marker is letters only: the tokenizer splits a
                // letter/digit boundary, so "zzmarker12" would index as
                // "zzmarker" plus "12" and identify nothing.
                bodyHtml: '<p>Shared filler prose about indexing and search. '
                    . self::marker($i) . ' is unique to this page.</p>',
                url: '/page/' . $i,
                date: '2024-01-01',
                siteName: 'Test Site',
            );
        }

        return $items;
    }

    /**
     * @param list<ContentItem> $items
     * @return array{dir: string, stateDir: string}
     */
    private function buildSinglePass(array $items, MemoryBudget $budget): array
    {
        $uid       = uniqid('', true);
        $stateDir  = sys_get_temp_dir() . "/scolta-integrity-ref-state-{$uid}";
        $outputDir = sys_get_temp_dir() . "/scolta-integrity-ref-out-{$uid}";
        mkdir($stateDir, 0755, true);
        mkdir($outputDir, 0755, true);

        $orchestrator = new IndexBuildOrchestrator($stateDir, $outputDir);
        $report       = $orchestrator->build(BuildIntent::fresh(count($items), $budget), $items);
        $this->assertTrue($report->success, 'Reference build must succeed: ' . ($report->error ?? ''));

        return ['dir' => $outputDir, 'stateDir' => $stateDir];
    }

    /**
     * Drive the corpus through repeated memory aborts until it completes.
     *
     * Every segment is a fresh orchestrator over the same state directory, the
     * way a fresh process is, and every segment is handed the whole corpus —
     * the orchestrator is responsible for not indexing a page twice.
     *
     * @param list<ContentItem> $items
     * @return array{dir: string, segments: int}
     */
    private function buildAcrossResumeSegments(array $items, MemoryBudget $budget): array
    {
        $segments = 0;
        $maxSegments = 200;

        do {
            $report = $this->runSegment($items, $budget, fresh: $segments === 0, yieldOnce: true);
            $segments++;
        } while ($report->error === 'memory_abort' && $segments < $maxSegments);

        $this->assertTrue($report->success, 'Segmented build must ultimately succeed: ' . ($report->error ?? ''));

        return ['dir' => $this->outputDir, 'segments' => $segments];
    }

    /**
     * @param list<ContentItem> $items
     */
    private function runSegment(array $items, MemoryBudget $budget, bool $fresh, bool $yieldOnce): \Tag1\Scolta\Index\StatusReport
    {
        $fired = false;
        $orchestrator = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            memoryPressureProbe: static function () use (&$fired, $yieldOnce): bool {
                if ($yieldOnce && !$fired) {
                    $fired = true;

                    return true;
                }

                return false;
            },
        );

        $intent = $fresh
            ? BuildIntent::fresh(count($items), $budget)
            : BuildIntent::resume($budget);

        return $orchestrator->build($intent, $items);
    }

    /**
     * Delete the ledger snapshot and journal, leaving the chunks behind.
     *
     * This is the pre-fix state directory exactly: chunk files referencing
     * ordinals nothing on disk can explain.
     */
    private function stripOrdinalAssignments(): void
    {
        foreach ([PageTableLedger::FILENAME, PageTableLedger::JOURNAL_FILENAME] as $name) {
            $path = $this->stateDir . '/' . $name;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Page ordinal => fragment url, read back out of the published index.
     *
     * @return array<int, string>
     */
    private function pageTable(string $outputDir): array
    {
        $pagefindDir = $outputDir . '/pagefind';
        $metaFiles   = glob($pagefindDir . '/*.pf_meta') ?: [];
        $this->assertNotEmpty($metaFiles, 'No pf_meta file in ' . $pagefindDir);

        $meta  = CborDecoder::decodePfFile($metaFiles[0]);
        $table = [];

        foreach (($meta[1] ?? []) as $ordinal => $row) {
            $fragment = $pagefindDir . '/fragment/' . $row[0] . '.pf_fragment';
            $data     = $this->loadFragment($fragment);
            $table[$ordinal] = $data['url'] ?? '';
        }

        $this->assertNotEmpty($table, 'pf_meta carried no page table in ' . $pagefindDir);

        return $table;
    }

    /**
     * Stemmed term => sorted urls it resolves to.
     *
     * Urls rather than ordinals on purpose: two indexes that number pages
     * differently but answer identically are equivalent, and two that number
     * them the same while answering differently are not.
     *
     * @return array<string, list<string>>
     */
    private function postingListsByUrl(string $outputDir): array
    {
        $pageTable = $this->pageTable($outputDir);
        $postings  = [];

        foreach (glob($outputDir . '/pagefind/index/*.pf_index') ?: [] as $indexFile) {
            $decoded = CborDecoder::decodePfFile($indexFile);
            foreach (($decoded[0] ?? []) as $entry) {
                $term    = $entry[0];
                $running = 0;
                foreach ($entry[1] as $pageRef) {
                    $running += $pageRef[0];
                    // Never silently drop an unresolvable ordinal: that is the
                    // exact symptom being tested for, and skipping it here
                    // would make every comparison below vacuously equal.
                    $postings[$term][] = $pageTable[$running] ?? "MISSING-ORDINAL-{$running}";
                }
            }
        }

        $this->assertNotEmpty($postings, 'Read back no posting lists at all from ' . $outputDir);

        foreach ($postings as $term => $urls) {
            $unique = array_values(array_unique($urls));
            sort($unique);
            $postings[$term] = $unique;
        }
        ksort($postings);

        return $postings;
    }

    /**
     * Fragments are gzipped JSON behind a `pagefind_dcd` marker, not CBOR.
     *
     * @return array<string, mixed>
     */
    private function loadFragment(string $path): array
    {
        $this->assertFileExists($path, 'pf_meta references a fragment that is not on disk');

        $decompressed = gzdecode((string) file_get_contents($path));
        $this->assertNotFalse($decompressed, "Fragment {$path} is not readable");

        if (str_starts_with($decompressed, 'pagefind_dcd')) {
            $decompressed = substr($decompressed, 12);
        }

        $data = json_decode($decompressed, true);
        $this->assertIsArray($data, "Fragment {$path} did not decode to an object");

        return $data;
    }

    private function removeDir(string $dir): void
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
