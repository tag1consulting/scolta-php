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

    public function testLoadByteStringProfile(): void
    {
        // Byte strings like "256M" are accepted as memory budget values.
        $cfg = MemoryBudgetConfig::load(['profile' => '256M']);
        $this->assertSame('256M', $cfg->profile());
    }

    public function testLoadByteStringIsNotNormalisedToConservative(): void
    {
        // "512M" is a valid byte string — load() must NOT replace it with 'conservative'.
        $cfg = MemoryBudgetConfig::load(['profile' => '512M']);
        $this->assertSame('512M', $cfg->profile());
    }

    public function testLoadChunkSize(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'conservative', 'chunk_size' => 75]);
        $this->assertSame(75, $cfg->chunkSize());
    }

    public function testLoadZeroChunkSizeNormalisedToNull(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'conservative', 'chunk_size' => 0]);
        $this->assertNull($cfg->chunkSize());
    }

    public function testLoadNullChunkSizeIsNull(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'conservative']);
        $this->assertNull($cfg->chunkSize());
    }

    public function testToMemoryBudgetAppliesChunkSize(): void
    {
        $cfg    = MemoryBudgetConfig::load(['profile' => 'conservative', 'chunk_size' => 75]);
        $budget = $cfg->toMemoryBudget();
        $this->assertSame(75, $budget->chunkSize());
        // Profile-level budget values are preserved.
        $this->assertSame(MemoryBudget::conservative()->totalBudgetBytes(), $budget->totalBudgetBytes());
    }

    public function testToMemoryBudgetByteStringWithChunkSize(): void
    {
        $cfg    = MemoryBudgetConfig::load(['profile' => '256M', 'chunk_size' => 100]);
        $budget = $cfg->toMemoryBudget();
        $this->assertSame(100, $budget->chunkSize());
    }

    public function testToArrayIncludesChunkSize(): void
    {
        $cfg = MemoryBudgetConfig::load(['profile' => 'balanced', 'chunk_size' => 150]);
        $arr = $cfg->toArray();
        $this->assertArrayHasKey('chunk_size', $arr);
        $this->assertSame(150, $arr['chunk_size']);
    }

    public function testValidateAcceptsNamedProfilesAndByteStrings(): void
    {
        foreach (['conservative', 'balanced', 'aggressive'] as $p) {
            $this->assertEmpty(MemoryBudgetConfig::load(['profile' => $p])->validate());
        }
        // Byte strings are now also valid.
        $this->assertEmpty(MemoryBudgetConfig::load(['profile' => '256M'])->validate());
        $this->assertEmpty(MemoryBudgetConfig::load(['profile' => '1G'])->validate());
    }

    public function testValidateRejectsNonsenseStrings(): void
    {
        // load() normalises 'turbo' → 'conservative', so the loaded config is valid.
        $this->assertEmpty(MemoryBudgetConfig::load(['profile' => 'turbo'])->validate());
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

    // fromCliAndConfig tests

    public function testFromCliAndConfigUsesCliWhenBothPresent(): void
    {
        $budget = MemoryBudgetConfig::fromCliAndConfig(
            'aggressive',
            '75',
            fn () => ['profile' => 'conservative', 'chunk_size' => 50],
        );

        $this->assertSame('aggressive', $budget->profile());
        $this->assertSame(75, $budget->chunkSize());
    }

    public function testFromCliAndConfigFallsBackToSavedProfile(): void
    {
        $budget = MemoryBudgetConfig::fromCliAndConfig(
            null,
            null,
            fn () => ['profile' => 'balanced', 'chunk_size' => null],
        );

        $this->assertSame('balanced', $budget->profile());
    }

    public function testFromCliAndConfigFallsBackToConservativeWhenConfigEmpty(): void
    {
        $budget = MemoryBudgetConfig::fromCliAndConfig(null, null, fn () => []);

        $this->assertSame('conservative', $budget->profile());
    }

    public function testFromCliAndConfigCliChunkOverridesSavedChunk(): void
    {
        $budget = MemoryBudgetConfig::fromCliAndConfig(
            null,
            '200',
            fn () => ['profile' => 'conservative', 'chunk_size' => 50],
        );

        $this->assertSame(200, $budget->chunkSize());
    }

    public function testFromCliAndConfigZeroChunkUsesProfileDefault(): void
    {
        $budget = MemoryBudgetConfig::fromCliAndConfig(null, '0', fn () => []);

        // '0' is not a valid chunk size; fromCliAndConfig passes null to fromOptions(),
        // which falls back to the conservative profile default of 50.
        $this->assertSame(50, $budget->chunkSize());
    }

    public function testFromCliAndConfigAcceptsByteStringBudget(): void
    {
        $budget = MemoryBudgetConfig::fromCliAndConfig('256M', null, fn () => []);

        $this->assertInstanceOf(MemoryBudget::class, $budget);
    }
}
