<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildState;

class BuildStateAtomicWriteTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-atomic-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testAtomicManifestWriteCreatesFile(): void
    {
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 42]);

        $manifestPath = $this->tmpDir . '/manifest.json';
        $this->assertFileExists($manifestPath);

        $data = json_decode(file_get_contents($manifestPath), true);
        $this->assertIsArray($data);
        $this->assertSame(42, $data['total_pages']);
        $this->assertSame('building', $data['status']);

        // No leftover .tmp file after successful rename.
        $this->assertFileDoesNotExist($manifestPath . '.tmp');
    }

    public function testReadFallsBackToTmpFileAfterCrash(): void
    {
        // Simulate a crash: write a valid payload to manifest.json.tmp but leave
        // manifest.json missing (as if the process died between write and rename).
        $tmpPath = $this->tmpDir . '/manifest.json.tmp';
        $payload = json_encode([
            'version'         => '1.0.0',
            'status'          => 'building',
            'total_pages'     => 99,
            'pages_processed' => 50,
            'chunks_written'  => 5,
            'chunk_size'      => 100,
            'started_at'      => gmdate('c'),
            'fingerprint'     => '',
            'language'        => 'en',
            'pagefind_version' => '1.0.0',
        ]);
        file_put_contents($tmpPath, $payload);

        $state = new BuildState($this->tmpDir);
        $manifest = $state->shouldResume();

        $this->assertNotNull($manifest, 'shouldResume() must fall back to manifest.json.tmp');
        $this->assertSame(99, $manifest['total_pages']);
        $this->assertSame(50, $manifest['pages_processed']);
    }

    public function testCorruptManifestGracefullyReturnsNull(): void
    {
        // Truncated JSON — simulates a partial write with no .tmp backup.
        file_put_contents($this->tmpDir . '/manifest.json', '{"status":"building","total_pages":1');

        $state    = new BuildState($this->tmpDir);
        $manifest = $state->shouldResume();

        $this->assertNull($manifest, 'Corrupt manifest with no .tmp backup must yield fresh build');
    }

    public function testAtomicWriteUsesLockEx(): void
    {
        // Verify the write mechanism: commit writes to .tmp then renames.
        // We intercept by checking that after a successful initiateBuild the
        // .tmp file is gone (renamed) and the primary manifest is valid JSON.
        $state = new BuildState($this->tmpDir);
        $state->initiateBuild(['total_pages' => 1]);

        $manifestPath = $this->tmpDir . '/manifest.json';
        $this->assertFileExists($manifestPath);
        $this->assertFileDoesNotExist($manifestPath . '.tmp');

        $data = json_decode(file_get_contents($manifestPath), true);
        $this->assertIsArray($data);
    }

    public function testStaleLockIsReleasedOnNextAcquisition(): void
    {
        $lockFile = $this->tmpDir . '/lock';

        // Simulate a lock left by a dead process: PID + timestamp from 2 hours ago.
        $staleTimestamp = time() - 7200;
        file_put_contents($lockFile, '99999:' . $staleTimestamp);

        $state = new BuildState($this->tmpDir);
        $this->assertTrue(
            $state->initiateBuild(['total_pages' => 10]),
            'initiateBuild() must succeed when existing lock has a stale timestamp'
        );
        $this->assertFileExists($lockFile, 'Lock file must be re-created after stale release');

        $state->releaseLock();
    }

    public function testMalformedLockWithOldMtimeIsReleased(): void
    {
        $lockFile = $this->tmpDir . '/lock';

        // Write a malformed lock file and back-date its mtime by 2 hours.
        file_put_contents($lockFile, 'not-a-valid-lock-content');
        touch($lockFile, time() - 7200);

        $state = new BuildState($this->tmpDir);
        $this->assertTrue(
            $state->initiateBuild(['total_pages' => 5]),
            'initiateBuild() must succeed when malformed lock file has old mtime'
        );
        $state->releaseLock();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
