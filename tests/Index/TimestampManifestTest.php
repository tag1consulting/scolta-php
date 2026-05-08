<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\TimestampManifest;
use Tag1\Scolta\Storage\FilesystemDriver;

/**
 * Tests for TimestampManifest — entity-level changed-timestamp tracking.
 */
class TimestampManifestTest extends TestCase
{
    private string $stateDir;
    private FilesystemDriver $storage;

    protected function setUp(): void
    {
        $uid = uniqid('', true);
        $this->stateDir = sys_get_temp_dir() . "/scolta-ts-manifest-{$uid}";
        mkdir($this->stateDir, 0755, true);
        $this->storage = new FilesystemDriver();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
    }

    // -------------------------------------------------------------------------
    // Basic get / put
    // -------------------------------------------------------------------------

    public function test_get_returns_null_for_unknown_key(): void
    {
        $m = $this->make();
        $this->assertNull($m->get('entity-42'));
    }

    public function test_put_and_get_roundtrip(): void
    {
        $m = $this->make();
        $items = [['hash' => 'abc', 'id' => '42', 'url' => '/node/42', 'date' => '2026-01-01', 'siteName' => 'Test', 'language' => 'en', 'filters' => []]];
        $m->put('42', 1_000_000, $items);

        $entry = $m->get('42');
        $this->assertNotNull($entry);
        $this->assertSame(1_000_000, $entry['ts']);
        $this->assertSame($items, $entry['items']);
    }

    public function test_put_overwrites_existing_entry(): void
    {
        $m = $this->make();
        $m->put('42', 1_000_000, [['hash' => 'old', 'id' => '42', 'url' => '/node/42', 'date' => '2026-01-01', 'siteName' => 'Test', 'language' => 'en', 'filters' => []]]);
        $m->put('42', 2_000_000, [['hash' => 'new', 'id' => '42', 'url' => '/node/42', 'date' => '2026-02-01', 'siteName' => 'Test', 'language' => 'en', 'filters' => []]]);

        $entry = $m->get('42');
        $this->assertSame(2_000_000, $entry['ts']);
        $this->assertSame('new', $entry['items'][0]['hash']);
    }

    // -------------------------------------------------------------------------
    // isEmpty / count
    // -------------------------------------------------------------------------

    public function test_is_empty_when_freshly_constructed(): void
    {
        $this->assertTrue($this->make()->isEmpty());
    }

    public function test_is_not_empty_after_put(): void
    {
        $m = $this->make();
        $m->put('42', 1_000_000, []);
        $this->assertFalse($m->isEmpty());
    }

    public function test_count_reflects_entries(): void
    {
        $m = $this->make();
        $m->put('1', 100, []);
        $m->put('2', 200, []);
        $this->assertSame(2, $m->count());
    }

    // -------------------------------------------------------------------------
    // pruneAndSave — pruning logic
    // -------------------------------------------------------------------------

    public function test_prune_removes_unseen_entries(): void
    {
        // First build: both entities exist — save them.
        $m1 = $this->make();
        $m1->put('keep', 1_000, []);
        $m1->put('prune', 2_000, []);
        $m1->markSeen('keep');
        $m1->markSeen('prune');
        $m1->pruneAndSave();

        // Second build: only 'keep' is encountered — 'prune' entity was deleted.
        $m2 = $this->make();
        $m2->markSeen('keep');
        $m2->pruneAndSave();

        $m3 = $this->make();
        $this->assertNotNull($m3->get('keep'));
        $this->assertNull($m3->get('prune'));
    }

    public function test_put_implicitly_marks_seen(): void
    {
        $m = $this->make();
        $m->put('entity', 999, []);
        // No explicit markSeen — put() should mark it seen.
        $m->pruneAndSave();

        $this->assertNotNull($m->get('entity'));
    }

    public function test_prune_does_not_save_when_no_changes(): void
    {
        $m = $this->make();
        $m->put('a', 1, []);
        $m->markSeen('a');
        $m->pruneAndSave(); // saves once (dirty from put)

        $manifestFile = $this->stateDir . '/timestamp-manifest.php';
        $mtime1 = filemtime($manifestFile);

        // Second prune with no changes.
        $m2 = $this->make();
        $m2->markSeen('a');
        $m2->pruneAndSave();
        $mtime2 = filemtime($manifestFile);

        $this->assertSame($mtime1, $mtime2, 'File should not be re-written when nothing changed.');
    }

    // -------------------------------------------------------------------------
    // Persistence — save and reload
    // -------------------------------------------------------------------------

    public function test_persists_across_instances(): void
    {
        $items = [['hash' => 'h1', 'id' => '10', 'url' => '/node/10', 'date' => '2026-01-01', 'siteName' => 'S', 'language' => 'en', 'filters' => []]];
        $m1 = $this->make();
        $m1->put('10', 1_234_567, $items);
        $m1->markSeen('10');
        $m1->pruneAndSave();

        $m2 = $this->make();
        $entry = $m2->get('10');
        $this->assertNotNull($entry);
        $this->assertSame(1_234_567, $entry['ts']);
        $this->assertSame($items, $entry['items']);
    }

    public function test_reload_starts_with_empty_seen_set(): void
    {
        // Put an entry and save.
        $m1 = $this->make();
        $m1->put('zombie', 100, []);
        $m1->markSeen('zombie');
        $m1->pruneAndSave();

        // Load fresh instance — 'zombie' is in data but NOT in seen.
        // Prune without markSeen → it is removed.
        $m2 = $this->make();
        $m2->pruneAndSave();

        $m3 = $this->make();
        $this->assertNull($m3->get('zombie'));
    }

    // -------------------------------------------------------------------------
    // Multilingual items
    // -------------------------------------------------------------------------

    public function test_multiple_items_per_entity_key(): void
    {
        $m = $this->make();
        $items = [
            ['hash' => 'en_hash', 'id' => '5', 'url' => '/node/5', 'date' => '2026-01-01', 'siteName' => 'S', 'language' => 'en', 'filters' => []],
            ['hash' => 'es_hash', 'id' => '5-es', 'url' => '/es/node/5', 'date' => '2026-01-01', 'siteName' => 'S', 'language' => 'es', 'filters' => []],
        ];
        $m->put('5', 9_999_999, $items);
        $m->markSeen('5');
        $m->pruneAndSave();

        $m2 = $this->make();
        $entry = $m2->get('5');
        $this->assertCount(2, $entry['items']);
        $this->assertSame('en_hash', $entry['items'][0]['hash']);
        $this->assertSame('es_hash', $entry['items'][1]['hash']);
    }

    // -------------------------------------------------------------------------
    // Corruption tolerance
    // -------------------------------------------------------------------------

    public function test_loads_empty_when_manifest_corrupted(): void
    {
        $path = $this->stateDir . '/timestamp-manifest.php';
        file_put_contents($path, 'not valid php serialize data');

        $m = $this->make();
        $this->assertTrue($m->isEmpty());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function make(): TimestampManifest
    {
        return new TimestampManifest($this->stateDir, $this->storage);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
