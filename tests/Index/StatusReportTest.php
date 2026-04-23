<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\StatusReport;

class StatusReportTest extends TestCase
{
    private function makeReport(bool $success = true, ?string $error = null): StatusReport
    {
        return new StatusReport(
            version: '0.3.0',
            pagefindVersion: '1.5.0',
            resolvedIndexer: 'php',
            pagesProcessed: 250,
            chunksWritten: 5,
            peakMemoryBytes: 40 * 1024 * 1024,
            memoryBudgetBytes: 96 * 1024 * 1024,
            durationSeconds: 3.142,
            outputDir: '/tmp/out',
            success: $success,
            error: $error,
        );
    }

    public function testSuccessfulReportProperties(): void
    {
        $r = $this->makeReport();
        $this->assertTrue($r->success);
        $this->assertSame(250, $r->pagesProcessed);
        $this->assertSame(5, $r->chunksWritten);
        $this->assertSame('php', $r->resolvedIndexer);
        $this->assertNull($r->error);
    }

    public function testPeakMemoryMbFormatting(): void
    {
        $r = $this->makeReport();
        $this->assertStringContainsString('MB', $r->peakMemoryMb());
        $this->assertStringContainsString('40', $r->peakMemoryMb());
    }

    public function testToBuildResultSuccess(): void
    {
        $result = $this->makeReport(true)->toBuildResult();
        $this->assertTrue($result->success);
        $this->assertSame(250, $result->pageCount);
        $this->assertStringContainsString('250', $result->message);
    }

    public function testToBuildResultFailure(): void
    {
        $result = $this->makeReport(false, 'Something went wrong')->toBuildResult();
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Something went wrong', $result->message);
    }

    public function testWarningsDefaultToNull(): void
    {
        $r = $this->makeReport();
        $this->assertNull($r->warnings);
    }
}
