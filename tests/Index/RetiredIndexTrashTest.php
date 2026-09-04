<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Tag1\Scolta\Index\RetiredIndexTrash;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\Scolta\Storage\StorageDriverInterface;

class RetiredIndexTrashTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/scolta-trash-test-' . uniqid('', true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->outputDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->outputDir);
    }

    /** A logger that records every entry by level. */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var array<string, list<string>> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[(string) $level][] = (string) $message;
            }
        };
    }

    public function testRetireRenamesTheDirectoryWithoutTouchingItsContents(): void
    {
        $retired = $this->outputDir . '/.scolta-old';
        mkdir($retired . '/fragment', 0755, true);
        file_put_contents($retired . '/fragment/a.pf_fragment', 'x');

        $trash = new RetiredIndexTrash(new FilesystemDriver(), $this->outputDir);

        $this->assertTrue($trash->retire($retired));
        $this->assertDirectoryDoesNotExist($retired);

        $trashDirs = glob($this->outputDir . '/' . RetiredIndexTrash::PREFIX . '*') ?: [];
        $this->assertCount(1, $trashDirs);
        $this->assertFileExists($trashDirs[0] . '/fragment/a.pf_fragment');
    }

    public function testSweepDeletesEveryTrashDirectoryAndLogsANotice(): void
    {
        // One trash dir shaped like a real retired index — enough files that
        // the parallel workers all get fed — plus a second one, which also
        // pins retire()'s unique naming: a fixed name would make the second
        // rename fail against the first.
        $retired = $this->outputDir . '/.scolta-old';
        mkdir($retired . '/fragment', 0755, true);
        for ($i = 0; $i < 200; $i++) {
            file_put_contents(sprintf('%s/fragment/%03d.pf_fragment', $retired, $i), 'x');
        }
        mkdir($this->outputDir . '/.scolta-new', 0755, true);
        file_put_contents($this->outputDir . '/.scolta-new/corpse.txt', 'stale');

        $trash = new RetiredIndexTrash(new FilesystemDriver(), $this->outputDir);
        $this->assertTrue($trash->retire($retired));
        $this->assertTrue($trash->retire($this->outputDir . '/.scolta-new'));
        $this->assertCount(2, $trash->trashDirs());

        // A live index must survive the sweep untouched.
        mkdir($this->outputDir . '/pagefind', 0755, true);
        file_put_contents($this->outputDir . '/pagefind/pagefind-entry.json', '{}');

        $logger = $this->recordingLogger();
        $this->assertTrue($trash->sweep($logger));

        $this->assertSame([], glob($this->outputDir . '/' . RetiredIndexTrash::PREFIX . '*') ?: []);
        $this->assertFileExists($this->outputDir . '/pagefind/pagefind-entry.json');
        // notice, not info: info is hidden at default drush verbosity, and the
        // whole point of the line is to keep a slow sweep from reading as a hang.
        $this->assertNotEmpty($logger->records[LogLevel::NOTICE] ?? []);
        $this->assertArrayNotHasKey(LogLevel::WARNING, $logger->records);
    }

    public function testSweepStopsWhenTheTimeBudgetIsAlreadySpent(): void
    {
        $trash = new RetiredIndexTrash(new FilesystemDriver(), $this->outputDir);
        mkdir($this->outputDir . '/.scolta-old', 0755, true);
        file_put_contents($this->outputDir . '/.scolta-old/corpse.txt', 'stale');
        $trash->retire($this->outputDir . '/.scolta-old');

        $logger = $this->recordingLogger();
        $result = $trash->sweep($logger, 0.0);

        $this->assertFalse($result);
        // The directory survives for the next sweep, and the stop is
        // announced rather than silent.
        $this->assertCount(1, glob($this->outputDir . '/' . RetiredIndexTrash::PREFIX . '*') ?: []);
        $budgetNotices = array_filter(
            $logger->records[LogLevel::NOTICE] ?? [],
            fn(string $m) => str_contains($m, 'budget'),
        );
        $this->assertNotEmpty($budgetNotices);
        $this->assertArrayNotHasKey(LogLevel::WARNING, $logger->records);
    }

    public function testSweepFailureWarnsAndDoesNotThrow(): void
    {
        $inner   = new FilesystemDriver();
        $storage = new class ($inner) implements StorageDriverInterface {
            public function __construct(private readonly FilesystemDriver $inner) {}

            public function deleteDirectory(string $path): bool
            {
                return false;
            }

            public function exists(string $path): bool
            {
                return $this->inner->exists($path);
            }
            public function get(string $path): string
            {
                return $this->inner->get($path);
            }
            public function put(string $path, string $c): bool
            {
                return $this->inner->put($path, $c);
            }
            public function delete(string $path): bool
            {
                return $this->inner->delete($path);
            }
            public function makeDirectory(string $path): bool
            {
                return $this->inner->makeDirectory($path);
            }
            public function move(string $from, string $to): bool
            {
                return $this->inner->move($from, $to);
            }
            public function files(string $dir, string $p = '*'): array
            {
                return $this->inner->files($dir, $p);
            }
        };

        $trash = new RetiredIndexTrash($storage, $this->outputDir);
        mkdir($this->outputDir . '/.scolta-old', 0755, true);
        $trash->retire($this->outputDir . '/.scolta-old');

        $logger = $this->recordingLogger();
        $trash->sweep($logger);

        $this->assertNotEmpty($logger->records[LogLevel::WARNING] ?? []);
        // The directory is still there, waiting for the next sweep.
        $this->assertCount(1, glob($this->outputDir . '/' . RetiredIndexTrash::PREFIX . '*') ?: []);
    }

    public function testAnUnbudgetedSweepOffTheCliIsBudgetedAndSaysSo(): void
    {
        // The orchestrator sweeps after every swap and passes no budget. Off
        // the CLI there is no fast path, so that used to be an unbounded
        // serial unlink inside a web request (scolta-laravel's FinalizeIndex
        // job under QUEUE_CONNECTION=sync).
        $retired = $this->outputDir . '/.scolta-old';
        mkdir($retired . '/fragment/nested', 0755, true);
        for ($i = 0; $i < 20; $i++) {
            file_put_contents(sprintf('%s/fragment/%03d.pf_fragment', $retired, $i), 'x');
        }
        file_put_contents($retired . '/fragment/nested/deep.pf_fragment', 'x');

        $storage = new RecordingFilesystemDriver();
        $trash   = new RetiredIndexTrash($storage, $this->outputDir, 'fpm-fcgi');
        $this->assertTrue($trash->retire($retired));

        $logger = $this->recordingLogger();
        $this->assertTrue($trash->sweep($logger));

        // A generous enough budget still finishes the job.
        $this->assertSame([], glob($this->outputDir . '/' . RetiredIndexTrash::PREFIX . '*') ?: []);

        // Deleted by the deadline-aware walk, not by a driver call that cannot
        // stop: deleteDirectory() takes no deadline, so one call can run hours
        // past a deadline the caller believed it had set.
        $this->assertSame([], $storage->deletedDirectories);

        // An operator who set no budget must be able to learn one was set.
        $sapiNotices = array_filter(
            $logger->records[LogLevel::NOTICE] ?? [],
            fn(string $m) => str_contains($m, 'limited to') && str_contains($m, 'SAPI'),
        );
        $this->assertNotEmpty($sapiNotices);
        $this->assertArrayNotHasKey(LogLevel::WARNING, $logger->records);
    }

    public function testAnUnbudgetedSweepOnTheCliStaysUnbudgeted(): void
    {
        // The CLI half of the same default: deletion is parallel there, so
        // capping it would leave trash behind for no gain.
        $retired = $this->outputDir . '/.scolta-old';
        mkdir($retired . '/fragment', 0755, true);
        file_put_contents($retired . '/fragment/a.pf_fragment', 'x');

        $trash = new RetiredIndexTrash(new FilesystemDriver(), $this->outputDir, 'cli');
        $this->assertTrue($trash->retire($retired));

        $logger = $this->recordingLogger();
        $this->assertTrue($trash->sweep($logger));

        $this->assertSame([], glob($this->outputDir . '/' . RetiredIndexTrash::PREFIX . '*') ?: []);
        $budgeted = array_filter(
            $logger->records[LogLevel::NOTICE] ?? [],
            fn(string $m) => str_contains($m, 'limited to'),
        );
        $this->assertSame([], $budgeted);
    }

    public function testABudgetedSweepStopsInsideADirectoryRatherThanBetweenDirectories(): void
    {
        // Enough files that no filesystem empties the directory inside the
        // budget: finishing would need ~400k unlinks/second.
        $retired = $this->outputDir . '/.scolta-old';
        mkdir($retired . '/fragment', 0755, true);
        for ($i = 0; $i < 4000; $i++) {
            file_put_contents(sprintf('%s/fragment/%04d.pf_fragment', $retired, $i), 'x');
        }

        $storage = new RecordingFilesystemDriver();
        $trash   = new RetiredIndexTrash($storage, $this->outputDir, 'fpm-fcgi');
        $this->assertTrue($trash->retire($retired));

        $logger = $this->recordingLogger();
        $this->assertFalse($trash->sweep($logger, 0.01));

        // The budget bounds work inside one directory, not merely the choice
        // to start another one.
        $this->assertSame([], $storage->deletedDirectories);
        $this->assertCount(1, glob($this->outputDir . '/' . RetiredIndexTrash::PREFIX . '*') ?: []);
        $budgetNotices = array_filter(
            $logger->records[LogLevel::NOTICE] ?? [],
            fn(string $m) => str_contains($m, 'budget'),
        );
        $this->assertNotEmpty($budgetNotices);
        // Running out of time is not a failure to delete.
        $this->assertArrayNotHasKey(LogLevel::WARNING, $logger->records);
    }
}

/** A FilesystemDriver that records the whole-directory deletions asked of it. */
class RecordingFilesystemDriver extends FilesystemDriver
{
    /** @var list<string> */
    public array $deletedDirectories = [];

    public function deleteDirectory(string $path): bool
    {
        $this->deletedDirectories[] = $path;

        return parent::deleteDirectory($path);
    }
}
