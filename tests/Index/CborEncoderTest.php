<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\CborEncoder;

class CborEncoderTest extends TestCase
{
    private CborEncoder $cbor;

    protected function setUp(): void
    {
        $this->cbor = new CborEncoder();
    }

    public function testEncodeUintZero(): void
    {
        $this->assertSame("\x00", $this->cbor->encodeUint(0));
    }

    public function testEncodeUintOne(): void
    {
        $this->assertSame("\x01", $this->cbor->encodeUint(1));
    }

    public function testEncodeUintTen(): void
    {
        $this->assertSame("\x0a", $this->cbor->encodeUint(10));
    }

    public function testEncodeUint23IsOneByteCanonical(): void
    {
        $this->assertSame("\x17", $this->cbor->encodeUint(23));
    }

    public function testEncodeUint24IsTwoBytes(): void
    {
        $this->assertSame("\x18\x18", $this->cbor->encodeUint(24));
    }

    public function testEncodeUint100(): void
    {
        $this->assertSame("\x18\x64", $this->cbor->encodeUint(100));
    }

    public function testEncodeUint1000(): void
    {
        $this->assertSame("\x19\x03\xe8", $this->cbor->encodeUint(1000));
    }

    public function testEncodeUint255(): void
    {
        $this->assertSame("\x18\xff", $this->cbor->encodeUint(255));
    }

    public function testEncodeUint65535(): void
    {
        $this->assertSame("\x19\xff\xff", $this->cbor->encodeUint(65535));
    }

    public function testEncodeUint65536(): void
    {
        $this->assertSame("\x1a\x00\x01\x00\x00", $this->cbor->encodeUint(65536));
    }

    public function testEncodeNegativeOne(): void
    {
        $this->assertSame("\x20", $this->cbor->encodeNegInt(-1));
    }

    public function testEncodeNegativeTen(): void
    {
        $this->assertSame("\x29", $this->cbor->encodeNegInt(-10));
    }

    public function testEncodeNegative100(): void
    {
        $this->assertSame("\x38\x63", $this->cbor->encodeNegInt(-100));
    }

    public function testEncodeEmptyString(): void
    {
        $this->assertSame("\x60", $this->cbor->encodeString(''));
    }

    public function testEncodeSingleCharString(): void
    {
        $this->assertSame("\x61\x61", $this->cbor->encodeString('a'));
    }

    public function testEncodeEmptyArray(): void
    {
        $this->assertSame("\x80", $this->cbor->encodeArray([]));
    }

    public function testEncodeArrayOfInts(): void
    {
        $items = [
            $this->cbor->encodeUint(1),
            $this->cbor->encodeUint(2),
            $this->cbor->encodeUint(3),
        ];
        $this->assertSame("\x83\x01\x02\x03", $this->cbor->encodeArray($items));
    }

    public function testEncodeNestedArray(): void
    {
        $inner = $this->cbor->encodeArray([
            $this->cbor->encodeUint(1),
        ]);
        $outer = $this->cbor->encodeArray([$inner]);
        // Outer: 1-element array containing 1-element array [1]
        $this->assertSame("\x81\x81\x01", $outer);
    }

    public function testEncodeUintRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cbor->encodeUint(-1);
    }

    public function testEncodeNegIntRejectsPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cbor->encodeNegInt(0);
    }
}
