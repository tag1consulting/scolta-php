<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Environment;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Environment\HostingConstraints;
use Tag1\Scolta\Environment\HostingDetector;
use Tag1\Scolta\Environment\HostingEnvironment;

class HostingDetectorTest extends TestCase
{
    public function testDetectReturnsHostingEnvironment(): void
    {
        $env = HostingDetector::detect();
        $this->assertInstanceOf(HostingEnvironment::class, $env);
    }

    public function testConstraintsReturnsHostingConstraints(): void
    {
        $constraints = HostingDetector::constraints();
        $this->assertInstanceOf(HostingConstraints::class, $constraints);
    }

    public function testDescribeReturnsString(): void
    {
        $desc = HostingDetector::describe();
        $this->assertIsString($desc);
        $this->assertNotEmpty($desc);
    }

    public function testStandardEnvironmentHasExec(): void
    {
        // In test environment, exec should be available.
        $constraints = HostingDetector::constraints();
        $env = HostingDetector::detect();

        if ($env === HostingEnvironment::STANDARD) {
            $this->assertTrue($constraints->execAvailable);
        }

        $this->assertTrue(true);
    }

    public function testConstraintsHasDefaultValues(): void
    {
        $constraints = new HostingConstraints();
        $this->assertSame(0, $constraints->maxExecutionTime);
        $this->assertSame(0, $constraints->memoryLimit);
        $this->assertTrue($constraints->execAvailable);
        $this->assertFalse($constraints->ephemeralFilesystem);
        $this->assertSame('', $constraints->note);
    }

    public function testPantheonConstraints(): void
    {
        // Can't actually test Pantheon detection without environment vars,
        // but we can verify the constraints object structure.
        $constraints = new HostingConstraints(
            maxExecutionTime: 120,
            execAvailable: true,
            note: 'Pantheon test',
        );
        $this->assertSame(120, $constraints->maxExecutionTime);
        $this->assertTrue($constraints->execAvailable);
    }
}
