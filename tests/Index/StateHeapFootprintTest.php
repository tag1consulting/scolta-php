<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\TimestampManifest;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Tests\Benchmark\Support\SmlShapedCorpus;

/**
 * Heap cost of reloading the per-segment state from disk.
 *
 * Every resume segment reloads the timestamp manifest and the page-table
 * ledger. With entries held as nested arrays they cost 6.2 KB and 3.5 KB
 * each — 1.18 GB at 124k pages, against a yield line of 1.2 GB — so the
 * per-entry heap is a number the build's segment count depends on, and a
 * change that quietly goes back to arrays must fail here. The bounds are
 * well above the measured string cost (1.4 KB / 0.6 KB) and well below the
 * array cost.
 */
class StateHeapFootprintTest extends TestCase
{
    private const ENTRIES = 20_000;

    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-heap-' . uniqid('', true);
        mkdir($this->stateDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir . '/*') ?: [] as $f) {
            unlink($f); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
        rmdir($this->stateDir);
    }

    public function testAReloadedManifestAndLedgerStayCompact(): void
    {
        $corpus  = new SmlShapedCorpus(self::ENTRIES);
        $storage = new FilesystemDriver();

        $manifest = new TimestampManifest($this->stateDir, $storage);
        $ledger   = new PageTableLedger($this->stateDir, $storage);
        $ledger->beginBuild(fresh: true);
        foreach ($corpus->items() as $item) {
            $manifest->put($item->id, 1_700_000_000, [[
                'hash'     => md5($item->id),
                'id'       => $item->id,
                'url'      => $item->url,
                'date'     => $item->date,
                'siteName' => $item->siteName,
                'language' => $item->language,
                'filters'  => $item->filters,
                'sortable' => $item->sortable,
                'metadata' => $item->metadata,
            ]]);
            $ledger->allocate($item->id, $item->url, $item->filters, $item->sortable, md5($item->id));
        }
        $manifest->saveWithoutPruning();
        $ledger->save();
        unset($manifest, $ledger, $corpus);
        gc_collect_cycles();

        $before   = memory_get_usage();
        $manifest = new TimestampManifest($this->stateDir, $storage);
        $manifestBytes = memory_get_usage() - $before;

        $before = memory_get_usage();
        $ledger = new PageTableLedger($this->stateDir, $storage);
        $ledgerBytes = memory_get_usage() - $before;

        $this->assertSame(self::ENTRIES, $manifest->count());
        $this->assertSame(self::ENTRIES, $ledger->liveCount());
        $this->assertSame(self::ENTRIES, $manifest->count());

        $perEntry = $manifestBytes / self::ENTRIES;
        $perRow   = $ledgerBytes / self::ENTRIES;
        fwrite(STDERR, sprintf("\nmanifest %.0f B/entry, ledger %.0f B/row\n", $perEntry, $perRow));

        $this->assertLessThan(2_048, $perEntry, 'Manifest entries are being held as arrays again, not strings.');
        $this->assertLessThan(1_024, $perRow, 'Ledger rows are being held as arrays again, not strings.');

        // The reload is the real thing, not a compact shell: a hit decodes.
        $entry = $manifest->get('7');
        $this->assertSame(1_700_000_000, $entry['ts']);
        $this->assertSame('7', $entry['items'][0]['id']);
        $this->assertSame($entry['items'][0]['url'], $ledger->urlFor('7'));
        $this->assertTrue($ledger->wasSeenThisBuild('7'));
    }
}
