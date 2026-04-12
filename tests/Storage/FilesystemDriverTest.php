<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Storage\FilesystemDriver;

class FilesystemDriverTest extends TestCase
{
    private FilesystemDriver $driver;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->driver = new FilesystemDriver();
        $this->tmpDir = sys_get_temp_dir() . '/scolta-fs-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->driver->deleteDirectory($this->tmpDir);
    }

    public function testPutAndGet(): void
    {
        $path = $this->tmpDir . '/test.txt';
        $this->assertTrue($this->driver->put($path, 'hello'));
        $this->assertSame('hello', $this->driver->get($path));
    }

    public function testExists(): void
    {
        $path = $this->tmpDir . '/exists.txt';
        $this->assertFalse($this->driver->exists($path));
        $this->driver->put($path, 'data');
        $this->assertTrue($this->driver->exists($path));
    }

    public function testDelete(): void
    {
        $path = $this->tmpDir . '/delete.txt';
        $this->driver->put($path, 'data');
        $this->assertTrue($this->driver->delete($path));
        $this->assertFalse($this->driver->exists($path));
    }

    public function testMakeDirectory(): void
    {
        $dir = $this->tmpDir . '/sub/nested';
        $this->assertTrue($this->driver->makeDirectory($dir));
        $this->assertTrue(is_dir($dir));
    }

    public function testMove(): void
    {
        $from = $this->tmpDir . '/from.txt';
        $to = $this->tmpDir . '/to.txt';
        $this->driver->put($from, 'moved');
        $this->assertTrue($this->driver->move($from, $to));
        $this->assertFalse($this->driver->exists($from));
        $this->assertSame('moved', $this->driver->get($to));
    }

    public function testFiles(): void
    {
        $this->driver->put($this->tmpDir . '/a.txt', '1');
        $this->driver->put($this->tmpDir . '/b.txt', '2');
        $files = $this->driver->files($this->tmpDir, '*.txt');
        $this->assertCount(2, $files);
    }

    public function testDeleteDirectory(): void
    {
        $dir = $this->tmpDir . '/toremove';
        mkdir($dir);
        $this->driver->put($dir . '/file.txt', 'data');
        $this->assertTrue($this->driver->deleteDirectory($dir));
        $this->assertFalse(is_dir($dir));
    }

    public function testPutCreatesParentDirectories(): void
    {
        $path = $this->tmpDir . '/deep/nested/dir/file.txt';
        $this->assertTrue($this->driver->put($path, 'deep'));
        $this->assertSame('deep', $this->driver->get($path));
    }
}
