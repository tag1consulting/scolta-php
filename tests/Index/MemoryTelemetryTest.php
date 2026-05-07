<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\MemoryTelemetry;

class MemoryTelemetryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // elapsed_s is included in every emit
    // -------------------------------------------------------------------------

    public function testEmitIncludesElapsedSeconds(): void
    {
        $log     = new CapturingLogger();
        $telemetry = new MemoryTelemetry($log, MemoryBudget::conservative());

        $telemetry->emit('test_phase');

        $this->assertNotEmpty($log->records);
        $context = $log->records[0]['context'];
        $this->assertArrayHasKey('elapsed_s', $context, 'emit() must include elapsed_s in context');
        $this->assertIsFloat($context['elapsed_s']);
        $this->assertGreaterThanOrEqual(0.0, $context['elapsed_s']);
    }

    public function testElapsedSecondsAppearsInMessage(): void
    {
        $log       = new CapturingLogger();
        $telemetry = new MemoryTelemetry($log, MemoryBudget::conservative());

        $telemetry->emit('some_phase');

        $message = $log->records[0]['message'];
        $this->assertStringContainsString(
            '{elapsed_s}',
            $message,
            'Log message template must contain {elapsed_s} placeholder'
        );
    }

    public function testElapsedSecondsIsNonDecreasing(): void
    {
        $log       = new CapturingLogger();
        $telemetry = new MemoryTelemetry($log, MemoryBudget::conservative());

        $telemetry->emit('phase_a');
        $telemetry->emit('phase_b');

        $a = $log->records[0]['context']['elapsed_s'];
        $b = $log->records[1]['context']['elapsed_s'];
        $this->assertGreaterThanOrEqual($a, $b, 'elapsed_s must be non-decreasing across successive emits');
    }

    // -------------------------------------------------------------------------
    // Phase name and standard fields are still present
    // -------------------------------------------------------------------------

    public function testEmitIncludesPhaseAndMemoryFields(): void
    {
        $log       = new CapturingLogger();
        $telemetry = new MemoryTelemetry($log, MemoryBudget::conservative());

        $telemetry->emit('merge_start');

        $ctx = $log->records[0]['context'];
        $this->assertSame('merge_start', $ctx['phase']);
        $this->assertArrayHasKey('peak_mb', $ctx);
        $this->assertArrayHasKey('current_mb', $ctx);
        $this->assertArrayHasKey('limit_pct', $ctx);
    }

    public function testEmitMergesExtraContext(): void
    {
        $log       = new CapturingLogger();
        $telemetry = new MemoryTelemetry($log, MemoryBudget::conservative());

        $telemetry->emit('chunk_committed', ['pages' => 42, 'chunk' => 3]);

        $ctx = $log->records[0]['context'];
        $this->assertSame(42, $ctx['pages']);
        $this->assertSame(3, $ctx['chunk']);
    }

    // -------------------------------------------------------------------------
    // Threshold uses current live memory, not monotonic peak
    // -------------------------------------------------------------------------

    public function testAbortThresholdUsesCurrentNotPeak(): void
    {
        $limit = 100 * 1_048_576; // 100 MB

        // current = 5 MB (well under threshold), peak = 95 MB (would trip old code)
        $current = 5 * 1_048_576;
        $peak    = 95 * 1_048_576;

        $log = new CapturingLogger();

        // Set limit BEFORE construction so parseMemoryLimit() reads 100M.
        ini_set('memory_limit', '100M');
        try {
            $telemetry = new MemoryTelemetry(
                $log,
                MemoryBudget::conservative(),
                static fn () => $current,
                static fn () => $peak,
            );
            $telemetry->emit('chunk_start');
        } finally {
            ini_set('memory_limit', '-1');
        }

        // Must NOT throw — current is only 5%, peak is 95% but that's irrelevant.
        $this->assertNotEmpty($log->records);
        $this->assertNotSame('error', $log->records[0]['level'],
            'Abort threshold must use current live memory, not monotonic peak');
    }

    public function testAbortThresholdFiresOnHighCurrentMemory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds safe threshold/');

        // current = 92 MB (over 90% threshold), peak = 92 MB
        $current = 92 * 1_048_576;

        $log = new CapturingLogger();

        // Set limit BEFORE construction so parseMemoryLimit() reads 100M.
        ini_set('memory_limit', '100M');
        try {
            $telemetry = new MemoryTelemetry(
                $log,
                MemoryBudget::conservative(),
                static fn () => $current,
                static fn () => $current,
            );
            $telemetry->emit('chunk_start');
        } finally {
            ini_set('memory_limit', '-1');
        }
    }
}

/**
 * Minimal PSR-3 logger that records every call for assertions.
 */
class CapturingLogger extends AbstractLogger
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
