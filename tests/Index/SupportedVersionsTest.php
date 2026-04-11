<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\SupportedVersions;

class SupportedVersionsTest extends TestCase
{
    public function testBundledVersionIsInTestedVersions(): void
    {
        $this->assertContains(
            SupportedVersions::BUNDLED_VERSION,
            SupportedVersions::TESTED_VERSIONS,
            'Bundled version must be in tested versions list'
        );
    }

    public function testIsSupportedReturnsTrueForTestedVersions(): void
    {
        foreach (SupportedVersions::TESTED_VERSIONS as $version) {
            $this->assertTrue(SupportedVersions::isSupported($version), "Version {$version} should be supported");
        }
    }

    public function testIsSupportedReturnsFalseForUnknownVersion(): void
    {
        $this->assertFalse(SupportedVersions::isSupported('99.99.99'));
    }

    public function testWarnReturnsNullForSupportedVersion(): void
    {
        $this->assertNull(SupportedVersions::warn(SupportedVersions::BUNDLED_VERSION));
    }

    public function testWarnReturnsMessageForUnsupportedVersion(): void
    {
        $warning = SupportedVersions::warn('99.99.99');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('NOT been tested', $warning);
        $this->assertStringContainsString('99.99.99', $warning);
    }

    public function testWarnReturnsNullForEmptyVersionIfNotIncompatible(): void
    {
        $warning = SupportedVersions::warn('');
        // Empty string is not in tested versions, so should warn
        $this->assertNotNull($warning);
    }

    public function testGetVersionForMetadata(): void
    {
        $this->assertSame(SupportedVersions::BUNDLED_VERSION, SupportedVersions::getVersionForMetadata());
    }

    public function testGetVersionInfoContainsBundledVersion(): void
    {
        $info = SupportedVersions::getVersionInfo();
        $this->assertStringContainsString(SupportedVersions::BUNDLED_VERSION, $info);
        $this->assertStringContainsString(SupportedVersions::MIN_VERSION, $info);
    }

    public function testMinVersionIsInTestedVersions(): void
    {
        $this->assertContains(
            SupportedVersions::MIN_VERSION,
            SupportedVersions::TESTED_VERSIONS,
            'Min version must be in tested versions list'
        );
    }

    public function testIsIncompatibleReturnsFalseForTestedVersion(): void
    {
        $this->assertFalse(SupportedVersions::isIncompatible(SupportedVersions::BUNDLED_VERSION));
    }

    public function testVersionFormatIsValidSemver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            SupportedVersions::BUNDLED_VERSION,
            'Bundled version must be valid semver'
        );
    }
}
