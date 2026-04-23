<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\NullProgressReporter;
use Tag1\Scolta\Index\ProgressReporterInterface;

class NullProgressReporterTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $reporter = new NullProgressReporter();
        $this->assertInstanceOf(ProgressReporterInterface::class, $reporter);
    }

    public function testAllMethodsAreSilent(): void
    {
        $reporter = new NullProgressReporter();
        // Should not throw; return type is void.
        $reporter->start(10, 'Testing');
        $reporter->advance(1, 'step 1');
        $reporter->advance();
        $reporter->finish('done');
        $this->assertTrue(true);
    }
}
