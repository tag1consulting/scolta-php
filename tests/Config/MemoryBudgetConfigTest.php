<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Config;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Index\MemoryBudget;

class MemoryBudgetConfigTest extends TestCase
{
    public function testDefaultsReturnsConservative(): void
    {
        $cfg = MemoryBudgetConfig::defaults();
        $this->assertSame('conservative', $cfg->profile());
        $this->assertNull($cfg->customBytes());
    }

    public function testLoadValidProfile(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'balanced']);
        $this->assertSame('balanced', $cfg->profile());
    }

    public function testLoadInvalidProfileFallsBackToConservative(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'turbo']);
        $this->assertSame('conservative', $cfg->profile());
    }

    public function testLoadCustomBytes(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'conservative', 'custom_bytes' => 512 * 1024 * 1024]);
        $this->assertSame(512 * 1024 * 1024, $cfg->customBytes());
    }

    public function testLoadZeroCustomBytesNormalisedToNull(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'conservative', 'custom_bytes' => 0]);
        $this->assertNull($cfg->customBytes());
    }

    public function testToMemoryBudgetNamedProfile(): void
    {
        $cfg    = MemoryBudgetConfig::load(['profile' => 'aggressive']);
        $budget = $cfg->toMemoryBudget();
        $this->assertInstanceOf(MemoryBudget::class, $budget);
        $this->assertSame('aggressive', $budget->profile());
    }

    public function testToMemoryBudgetCustomBytes(): void
    {
        $cfg    = MemoryBudgetConfig::load(['profile' => 'conservative', 'custom_bytes' => 768 * 1024 * 1024]);
        $budget = $cfg->toMemoryBudget();
        // 768 MB hits the aggressive threshold in MemoryBudget::fromBytes()
        $this->assertSame('aggressive', $budget->profile());
    }

    public function testValidatePassesForValidProfile(): void
    {
        foreach (['conservative', 'balanced', 'aggressive'] as $profile) {
            $errors = MemoryBudgetConfig::load(['profile' => $profile])->validate();
            $this->assertEmpty($errors, "Expected no errors for profile '$profile'");
        }
    }

    public function testValidateAcceptsAllValidProfilesOnly(): void
    {
        // load() normalises invalid inputs, so validate() always passes after load().
        // This test confirms the valid set is exactly the three named profiles.
        foreach (['conservative', 'balanced', 'aggressive'] as $p) {
            $this->assertEmpty(MemoryBudgetConfig::load(['profile' => $p])->validate());
        }
        // An unknown profile is silently normalised to conservative by load(),
        // so validate() on a loaded config always returns no errors.
        $this->assertEmpty(MemoryBudgetConfig::load(['profile' => 'unknown'])->validate());
    }

    public function testSuggestReturnsArray(): void
    {
        $hint = MemoryBudgetConfig::defaults()->suggest();
        $this->assertArrayHasKey('profile', $hint);
        $this->assertArrayHasKey('reason', $hint);
        $this->assertArrayHasKey('confidence', $hint);
        $this->assertContains($hint['profile'], ['conservative', 'balanced', 'aggressive']);
    }

    public function testToArray(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'balanced', 'custom_bytes' => null]);
        $arr = $cfg->toArray();
        $this->assertSame('balanced', $arr['profile']);
        $this->assertNull($arr['custom_bytes']);
    }
}
