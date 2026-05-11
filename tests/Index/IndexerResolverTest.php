<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\IndexerResolver;
use Tag1\Scolta\Index\MemoryBudget;

class IndexerResolverTest extends TestCase
{
    private string $tempDir;
    private string $fakeBinary;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scolta-resolver-test-' . uniqid('', true);
        $binDir = $this->tempDir . '/.scolta/bin';
        mkdir($binDir, 0755, true);

        $this->fakeBinary = $binDir . '/pagefind';
        file_put_contents($this->fakeBinary, "#!/bin/sh\necho 'pagefind 1.5.0'\n");
        chmod($this->fakeBinary, 0755);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------
    // 'php' mode
    // -------------------------------------------------------------------

    public function testPhpModeReturnsPhp(): void
    {
        $logger = new IndexerResolverLogger();
        $resolver = new IndexerResolver(new PagefindBinary(), $logger);

        $result = $resolver->resolve('php');

        $this->assertSame('php', $result);
    }

    public function testPhpModeLogsUsingPhpIndexer(): void
    {
        $logger = new IndexerResolverLogger();
        $resolver = new IndexerResolver(new PagefindBinary(), $logger);

        $resolver->resolve('php');

        $this->assertCount(1, $logger->records);
        $this->assertSame('notice', $logger->records[0]['level']);
        $this->assertStringContainsString('Using PHP indexer', $logger->records[0]['message']);
    }

    // -------------------------------------------------------------------
    // 'binary' mode — binary available
    // -------------------------------------------------------------------

    public function testBinaryModeWithAvailableBinaryReturnsBinary(): void
    {
        $logger = new IndexerResolverLogger();
        $binary = new PagefindBinary(configuredPath: $this->fakeBinary);
        $resolver = new IndexerResolver($binary, $logger);

        $result = $resolver->resolve('binary');

        $this->assertSame('binary', $result);
    }

    public function testBinaryModeWithAvailableBinaryLogsUsingBinaryIndexer(): void
    {
        $logger = new IndexerResolverLogger();
        $binary = new PagefindBinary(configuredPath: $this->fakeBinary);
        $resolver = new IndexerResolver($binary, $logger);

        $resolver->resolve('binary');

        $this->assertCount(1, $logger->records);
        $this->assertSame('notice', $logger->records[0]['level']);
        $this->assertStringContainsString('Using binary indexer', $logger->records[0]['message']);
    }

    // -------------------------------------------------------------------
    // 'binary' mode — binary not available → fallback
    // -------------------------------------------------------------------

    public function testBinaryModeWithMissingBinaryFallsBackToPhp(): void
    {
        $logger = new IndexerResolverLogger();
        $resolver = new IndexerResolver(new UnavailablePagefindBinary(), $logger);

        $result = $resolver->resolve('binary');

        $this->assertSame('php', $result);
    }

    public function testBinaryModeWithMissingBinaryLogsFallback(): void
    {
        $logger = new IndexerResolverLogger();
        $resolver = new IndexerResolver(new UnavailablePagefindBinary(), $logger);

        $resolver->resolve('binary');

        $this->assertCount(1, $logger->records);
        $this->assertSame('notice', $logger->records[0]['level']);
        $this->assertStringContainsString('Falling back to PHP indexer', $logger->records[0]['message']);
        $this->assertStringContainsString('binary not available', $logger->records[0]['message']);
    }

    // -------------------------------------------------------------------
    // 'auto' mode — always PHP regardless of binary availability
    // -------------------------------------------------------------------

    public function testAutoModeReturnsPhp(): void
    {
        $logger = new IndexerResolverLogger();
        $resolver = new IndexerResolver(new PagefindBinary(), $logger);

        $result = $resolver->resolve('auto');

        $this->assertSame('php', $result);
    }

    public function testAutoModeWithAvailableBinaryStillReturnsPhp(): void
    {
        $logger = new IndexerResolverLogger();
        $binary = new PagefindBinary(configuredPath: $this->fakeBinary);
        $resolver = new IndexerResolver($binary, $logger);

        $result = $resolver->resolve('auto');

        $this->assertSame('php', $result);
    }

    public function testAutoModeLogsUsingPhpIndexer(): void
    {
        $logger = new IndexerResolverLogger();
        $binary = new PagefindBinary(configuredPath: $this->fakeBinary);
        $resolver = new IndexerResolver($binary, $logger);

        $resolver->resolve('auto');

        $this->assertCount(1, $logger->records);
        $this->assertSame('notice', $logger->records[0]['level']);
        $this->assertStringContainsString('Using PHP indexer', $logger->records[0]['message']);
    }

    public function testAutoModeWithoutBinaryReturnsPhp(): void
    {
        $logger = new IndexerResolverLogger();
        $resolver = new IndexerResolver(new UnavailablePagefindBinary(), $logger);

        $result = $resolver->resolve('auto');

        $this->assertSame('php', $result);
    }

    // -------------------------------------------------------------------
    // IndexBuildOrchestrator emits "Using PHP indexer" notice
    // -------------------------------------------------------------------

    public function testBuildLogsUsingPhpIndexer(): void
    {
        $stateDir  = sys_get_temp_dir() . '/scolta-resolver-orch-state-' . uniqid('', true);
        $outputDir = sys_get_temp_dir() . '/scolta-resolver-orch-out-' . uniqid('', true);
        mkdir($stateDir, 0755, true);
        mkdir($outputDir, 0755, true);

        $logger = new IndexerResolverLogger();
        $orchestrator = new IndexBuildOrchestrator($stateDir, $outputDir);
        $intent = BuildIntent::fresh(1, MemoryBudget::conservative());
        $items = [new ContentItem(
            id: 'p1',
            title: 'Test',
            bodyHtml: '<p>hello world</p>',
            url: '/test',
            date: '2024-01-01',
            siteName: 'Site',
        )];

        $orchestrator->build($intent, $items, $logger);

        $notices = array_filter($logger->records, fn ($r) => $r['level'] === 'notice');
        $messages = array_column(array_values($notices), 'message');
        $this->assertTrue(
            in_array('[scolta] Using PHP indexer.', $messages, true),
            'Expected "[scolta] Using PHP indexer." notice in build log. Got: ' . implode(', ', $messages),
        );

        $this->removeDir($stateDir);
        $this->removeDir($outputDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}

/**
 * Stub that always reports the binary as unavailable (no PATH, npx, or configured path).
 * Used for tests that need to verify fallback behaviour in environments where
 * the real pagefind binary might be installed.
 */
class UnavailablePagefindBinary extends PagefindBinary
{
    public function resolve(): ?string
    {
        return null;
    }

    public function status(): array
    {
        return [
            'available' => false,
            'binary'    => null,
            'version'   => null,
            'via'       => 'none',
            'message'   => 'Pagefind binary not found (stub).',
        ];
    }
}

class IndexerResolverLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
