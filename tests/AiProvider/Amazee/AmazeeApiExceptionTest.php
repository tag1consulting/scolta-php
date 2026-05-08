<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\AiProvider\Amazee;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;

class AmazeeApiExceptionTest extends TestCase
{
    public function testMessageAndStatusCode(): void
    {
        $e = new AmazeeApiException('Not found', 404);
        $this->assertSame('Not found', $e->getMessage());
        $this->assertSame(404, $e->getStatusCode());
    }

    public function testDefaultStatusCodeIsZero(): void
    {
        $e = new AmazeeApiException('Network error');
        $this->assertSame(0, $e->getStatusCode());
    }

    public function testPreviousException(): void
    {
        $previous = new \RuntimeException('Connection refused');
        $e = new AmazeeApiException('Request failed', 0, $previous);
        $this->assertSame($previous, $e->getPrevious());
    }

    public function testIsRuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new AmazeeApiException('test'));
    }
}
