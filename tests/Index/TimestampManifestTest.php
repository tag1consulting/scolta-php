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
    // Surviving the state-directory wipe
    // -------------------------------------------------------------------------

    /**
     * A fresh build unlinks every file in the state directory partway through,
     * after the manifest is already in memory. If pruneAndSave() then declines
     * to write because nothing changed, the manifest is gone and the next build
     * re-gathers the entire corpus — the more unchanged the corpus, the more
     * certain the loss.
     */
    public function test_rewrites_the_manifest_when_the_file_was_deleted_mid_build(): void
    {
        $m = $this->make();
        $m->put('42', 1_000_000, [['hash' => 'abc', 'id' => '42', 'url' => '/node/42', 'date' => '2026-01-01', 'siteName' => 'Test', 'language' => 'en', 'filters' => []]]);
        $m->pruneAndSave();

        // Second build: entry unchanged, so nothing marks the manifest dirty.
        $second = $this->make();
        unlink($this->stateDir . '/timestamp-manifest.php');
        $second->markSeen('42');
        $second->pruneAndSave();

        $this->assertNotNull($this->make()->get('42'));
    }

    public function test_rewrites_the_empty_set_when_the_file_was_deleted_mid_build(): void
    {
        $m = $this->make();
        $m->markEmpty('abc');
        $m->pruneAndSave();

        $second = $this->make();
        unlink($this->stateDir . '/timestamp-manifest-empty.php');
        $this->assertTrue($second->isKnownEmpty('abc'));
        $second->pruneAndSave();

        $this->assertTrue($this->make()->isKnownEmpty('abc'));
    }

    /**
     * The reverse: an empty manifest writes nothing, so a first build that
     * gathers nothing does not leave a file behind.
     */
    public function test_does_not_write_a_manifest_with_nothing_in_it(): void
    {
        $this->make()->pruneAndSave();

        $this->assertFileDoesNotExist($this->stateDir . '/timestamp-manifest.php');
        $this->assertFileDoesNotExist($this->stateDir . '/timestamp-manifest-empty.php');
    }

    // -------------------------------------------------------------------------
    // Known-empty content hashes
    // -------------------------------------------------------------------------

    public function test_unrecorded_hash_is_not_known_empty(): void
    {
        $m = $this->make();
        $this->assertFalse($m->isKnownEmpty('abc'));
        $this->assertSame(0, $m->knownEmptyCount());
    }

    public function test_known_empty_hash_survives_save_and_reload(): void
    {
        $m = $this->make();
        $m->markEmpty('abc');
        $m->pruneAndSave();

        $reloaded = $this->make();
        $this->assertTrue($reloaded->isKnownEmpty('abc'));
        $this->assertSame(1, $reloaded->knownEmptyCount());
    }

    /**
     * The entry manifest and the empty set are separate files, so a build that
     * records nothing but empties still persists them.
     */
    public function test_known_empty_hash_persists_without_any_entries(): void
    {
        $m = $this->make();
        $m->markEmpty('abc');
        $m->pruneAndSave();

        $this->assertTrue($this->make()->isKnownEmpty('abc'));
    }

    public function test_known_empty_hash_is_pruned_when_the_build_does_not_touch_it(): void
    {
        $m = $this->make();
        $m->markEmpty('abc');
        $m->markEmpty('def');
        $m->pruneAndSave();

        // Next build sees only 'abc' — 'def' belongs to content that has since
        // been edited or deleted, so its hash can never come back.
        $second = $this->make();
        $this->assertTrue($second->isKnownEmpty('abc'));
        $second->pruneAndSave();

        $third = $this->make();
        $this->assertTrue($third->isKnownEmpty('abc'));
        $this->assertFalse($third->isKnownEmpty('def'));
        $this->assertSame(1, $third->knownEmptyCount());
    }

    public function test_reading_a_known_empty_hash_keeps_it_across_a_prune(): void
    {
        $m = $this->make();
        $m->markEmpty('abc');
        $m->pruneAndSave();

        $second = $this->make();
        $this->assertTrue($second->isKnownEmpty('abc'));
        $second->pruneAndSave();

        $this->assertTrue($this->make()->isKnownEmpty('abc'));
    }

    /**
     * A manifest written before the empty set existed must still load.
     */
    public function test_loads_when_empty_set_file_is_absent(): void
    {
        $m = $this->make();
        $m->put('42', 1_000_000, [['hash' => 'abc', 'id' => '42', 'url' => '/node/42', 'date' => '2026-01-01', 'siteName' => 'Test', 'language' => 'en', 'filters' => []]]);
        $m->pruneAndSave();

        $this->assertFileDoesNotExist($this->stateDir . '/timestamp-manifest-empty.php');

        $reloaded = $this->make();
        $this->assertNotNull($reloaded->get('42'));
        $this->assertFalse($reloaded->isKnownEmpty('abc'));
    }

    public function test_loads_empty_set_as_empty_when_corrupted(): void
    {
        file_put_contents($this->stateDir . '/timestamp-manifest-empty.php', 'not valid php serialize data');

        $this->assertFalse($this->make()->isKnownEmpty('abc'));
    }

    // -------------------------------------------------------------------------
    // saveWithoutPruning — the interrupted-build paths
    // -------------------------------------------------------------------------

    /**
     * A process that did not gather the whole corpus cannot tell a deleted
     * entity from one it simply has not reached, so it must keep both.
     */
    public function test_save_without_pruning_keeps_entries_the_build_never_touched(): void
    {
        $m1 = $this->make();
        $m1->put('reached', 1_000, []);
        $m1->put('not-reached', 2_000, []);
        $m1->pruneAndSave();

        // A segment that yielded after seeing one of the two.
        $m2 = $this->make();
        $m2->markSeen('reached');
        $m2->saveWithoutPruning();

        $m3 = $this->make();
        $this->assertNotNull($m3->get('reached'));
        $this->assertNotNull($m3->get('not-reached'), 'A yield must not delete the entities it had not reached.');
        $this->assertSame(2, $m3->count());
    }

    public function test_save_without_pruning_keeps_known_empty_hashes_the_build_never_read(): void
    {
        $m1 = $this->make();
        $m1->put('entity', 1_000, []);
        $m1->markEmpty('hash-a');
        $m1->markEmpty('hash-b');
        $m1->pruneAndSave();

        $m2 = $this->make();
        $m2->isKnownEmpty('hash-a');
        $m2->saveWithoutPruning();

        $m3 = $this->make();
        $this->assertTrue($m3->isKnownEmpty('hash-a'));
        $this->assertTrue($m3->isKnownEmpty('hash-b'), 'A yield must not delete the known-empty hashes it never read.');
    }

    /**
     * Writing whenever the file is missing is the same rule pruneAndSave()
     * needs, for the same reason: a segment in which nothing changed marks
     * nothing dirty, and is exactly the one that can least afford to lose the
     * manifest.
     */
    public function test_save_without_pruning_rewrites_a_manifest_that_was_deleted_mid_build(): void
    {
        $m1 = $this->make();
        $m1->put('entity', 1_000, []);
        $m1->pruneAndSave();

        $m2 = $this->make();
        unlink($this->stateDir . '/timestamp-manifest.php');
        $m2->saveWithoutPruning();

        $this->assertNotNull($this->make()->get('entity'));
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
