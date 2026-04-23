<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\MemoryBudgetSuggestion;

class MemoryBudgetSuggestionTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // checkProfileFit — warn cases
    // ---------------------------------------------------------------------------

    public function testConservativeWarnsAt128Mb(): void
    {
        // conservative budget = 96 MB; 96/128 = 75% > 70% → warn
        $result = MemoryBudgetSuggestion::checkProfileFit('conservative', 128 * 1024 * 1024);

        $this->assertSame('warn', $result['status']);
        $this->assertNotNull($result['warning']);
        $this->assertStringContainsString('96 MB', $result['warning']);
        $this->assertStringContainsString('128 MB', $result['warning']);
        $this->assertSame(96 * 1024 * 1024, $result['profile_budget_bytes']);
        $this->assertSame(128 * 1024 * 1024, $result['limit_bytes']);
    }

    public function testBalancedWarnsAt512Mb(): void
    {
        // balanced budget = 384 MB; 384/512 = 75% > 70% → warn
        $result = MemoryBudgetSuggestion::checkProfileFit('balanced', 512 * 1024 * 1024);

        $this->assertSame('warn', $result['status']);
        $this->assertNotNull($result['warning']);
        $this->assertStringContainsString('384 MB', $result['warning']);
        $this->assertStringContainsString('512 MB', $result['warning']);
        $this->assertSame(384 * 1024 * 1024, $result['profile_budget_bytes']);
        $this->assertSame(512 * 1024 * 1024, $result['limit_bytes']);
    }

    public function testAggressiveWarnsAt1Gb(): void
    {
        // aggressive budget = 1024 MB; 1024/1024 = 100% > 70% → warn
        $result = MemoryBudgetSuggestion::checkProfileFit('aggressive', 1024 * 1024 * 1024);

        $this->assertSame('warn', $result['status']);
        $this->assertNotNull($result['warning']);
        $this->assertSame(1024 * 1024 * 1024, $result['profile_budget_bytes']);
        $this->assertSame(1024 * 1024 * 1024, $result['limit_bytes']);
    }

    // ---------------------------------------------------------------------------
    // checkProfileFit — safe cases
    // ---------------------------------------------------------------------------

    public function testConservativeSafeAt256Mb(): void
    {
        // conservative budget = 96 MB; 96/256 ≈ 37.5% ≤ 70% → safe
        $result = MemoryBudgetSuggestion::checkProfileFit('conservative', 256 * 1024 * 1024);

        $this->assertSame('safe', $result['status']);
        $this->assertNull($result['warning']);
        $this->assertSame(96 * 1024 * 1024, $result['profile_budget_bytes']);
        $this->assertSame(256 * 1024 * 1024, $result['limit_bytes']);
    }

    public function testBalancedSafeAt600Mb(): void
    {
        // balanced budget = 384 MB; 384/600 = 64% ≤ 70% → safe
        $result = MemoryBudgetSuggestion::checkProfileFit('balanced', 600 * 1024 * 1024);

        $this->assertSame('safe', $result['status']);
        $this->assertNull($result['warning']);
        $this->assertSame(384 * 1024 * 1024, $result['profile_budget_bytes']);
        $this->assertSame(600 * 1024 * 1024, $result['limit_bytes']);
    }

    public function testAggressiveSafeAt1536Mb(): void
    {
        // aggressive budget = 1024 MB; 1024/1536 ≈ 66.7% ≤ 70% → safe
        $result = MemoryBudgetSuggestion::checkProfileFit('aggressive', 1536 * 1024 * 1024);

        $this->assertSame('safe', $result['status']);
        $this->assertNull($result['warning']);
        $this->assertSame(1024 * 1024 * 1024, $result['profile_budget_bytes']);
        $this->assertSame(1536 * 1024 * 1024, $result['limit_bytes']);
    }

    // ---------------------------------------------------------------------------
    // checkProfileFit — unlimited / unknown limit
    // ---------------------------------------------------------------------------

    public function testUnlimitedLimitIsAlwaysSafe(): void
    {
        $result = MemoryBudgetSuggestion::checkProfileFit('aggressive', -1);

        $this->assertSame('safe', $result['status']);
        $this->assertNull($result['warning']);
        $this->assertSame(-1, $result['limit_bytes']);
    }

    public function testNullLimitAutoDetectsAndIsSafe(): void
    {
        // With null, the method reads ini_get('memory_limit'). We cannot know the
        // test environment's limit, but we can confirm the method returns valid keys
        // and status is one of the two recognised values.
        $result = MemoryBudgetSuggestion::checkProfileFit('conservative', null);

        $this->assertArrayHasKey('status', $result);
        $this->assertContains($result['status'], ['safe', 'warn']);
        $this->assertArrayHasKey('warning', $result);
        $this->assertArrayHasKey('profile_budget_bytes', $result);
        $this->assertArrayHasKey('limit_bytes', $result);
        $this->assertSame(96 * 1024 * 1024, $result['profile_budget_bytes']);
    }

    // ---------------------------------------------------------------------------
    // getMemoryLimitText
    // ---------------------------------------------------------------------------

    public function testGetMemoryLimitTextMb(): void
    {
        $this->assertSame('256 MB', MemoryBudgetSuggestion::getMemoryLimitText(256 * 1024 * 1024));
    }

    public function testGetMemoryLimitTextUnlimited(): void
    {
        $this->assertSame('unlimited', MemoryBudgetSuggestion::getMemoryLimitText(-1));
    }

    public function testGetMemoryLimitTextNullReadsFromIni(): void
    {
        // null triggers ini_get auto-detection; just verify a non-empty string is returned
        $text = MemoryBudgetSuggestion::getMemoryLimitText(null);

        $this->assertIsString($text);
        $this->assertNotEmpty($text);
    }
}
