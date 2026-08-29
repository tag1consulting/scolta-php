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

    public function testSweepRemovesASymlinkWithoutTouchingItsTarget(): void
    {
        $target = $this->outputDir . '/pagefind';
        mkdir($target, 0755, true);
        file_put_contents($target . '/pagefind-entry.json', '{}');

        $retired = $this->outputDir . '/.scolta-old';
        mkdir($retired, 0755, true);
        symlink($target, $retired . '/dir-link');
        symlink($target . '/pagefind-entry.json', $retired . '/file-link');

        $trash = new RetiredIndexTrash(new FilesystemDriver(), $this->outputDir);
        $trash->retire($retired);

        $this->assertTrue($trash->sweep($this->recordingLogger()));
        $this->assertSame([], glob($this->outputDir . '/' . RetiredIndexTrash::PREFIX . '*') ?: []);
        // The links are gone; what they pointed at is not.
        $this->assertFileExists($target . '/pagefind-entry.json');
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
}
