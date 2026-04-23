<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\ChunkReader;
use Tag1\Scolta\Index\ChunkWriter;
use Tag1\Scolta\Index\OldChunkFormatException;

class ChunkWriterReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-chunk-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        if ($files) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        rmdir($this->tmpDir);
    }

    private function makePartial(int $pageOffset = 0): array
    {
        return [
            'pages' => [
                $pageOffset     => ['url' => "/page-{$pageOffset}", 'wordCount' => 10, 'content' => 'hello world', 'meta' => ['title' => "Page {$pageOffset}"], 'filters' => []],
                $pageOffset + 1 => ['url' => "/page-" . ($pageOffset + 1), 'wordCount' => 5, 'content' => 'foo bar', 'meta' => ['title' => "Page " . ($pageOffset + 1)], 'filters' => []],
            ],
            'index' => [
                'zebra' => [$pageOffset     => ['positions' => [25 => [1, 3]], 'meta_positions' => []]],
                'apple' => [$pageOffset + 1 => ['positions' => [25 => [2]], 'meta_positions' => [0]]],
                'mango' => [
                    $pageOffset     => ['positions' => [25 => [5]], 'meta_positions' => []],
                    $pageOffset + 1 => ['positions' => [25 => [7]], 'meta_positions' => []],
                ],
            ],
        ];
    }

    public function testRoundTripPreservesAllData(): void
    {
        $writer  = new ChunkWriter();
        $partial = $this->makePartial(0);
        $path    = $this->tmpDir . '/chunk-000.dat';
        $writer->write($path, $partial);

        $reader = new ChunkReader($path);
        $pages  = iterator_to_array($reader->openPages());
        $terms  = [];
        foreach ((new ChunkReader($path))->openIndex() as [$term, $data]) {
            $terms[$term] = $data;
        }

        $this->assertEquals($partial['pages'], $pages);
        $this->assertEquals(3, count($terms));
        $this->assertArrayHasKey('apple', $terms);
        $this->assertArrayHasKey('mango', $terms);
        $this->assertArrayHasKey('zebra', $terms);
    }

    public function testIndexTermsAreSortedAlphabetically(): void
    {
        $writer = new ChunkWriter();
        $path   = $this->tmpDir . '/chunk-sort.dat';
        $writer->write($path, $this->makePartial(0));

        $reader = new ChunkReader($path);
        $terms  = [];
        foreach ($reader->openIndex() as [$term]) {
            $terms[] = $term;
        }

        $sorted = $terms;
        sort($sorted);
        $this->assertSame($sorted, $terms, 'Terms must be in alphabetical order');
    }

    public function testIntegerPageKeysArePreserved(): void
    {
        $writer  = new ChunkWriter();
        $partial = $this->makePartial(42);
        $path    = $this->tmpDir . '/chunk-keys.dat';
        $writer->write($path, $partial);

        $reader = new ChunkReader($path);
        $pages  = iterator_to_array($reader->openPages());

        $this->assertArrayHasKey(42, $pages);
        $this->assertArrayHasKey(43, $pages);
        $this->assertIsInt(array_key_first($pages));
    }

    public function testIndexIntegerPageKeysPreserved(): void
    {
        $writer  = new ChunkWriter();
        $partial = $this->makePartial(7);
        $path    = $this->tmpDir . '/chunk-idx-keys.dat';
        $writer->write($path, $partial);

        $reader = new ChunkReader($path);
        foreach ($reader->openIndex() as [$term, $data]) {
            foreach (array_keys($data) as $key) {
                if ($key !== '_variants') {
                    $this->assertIsInt($key, "Page key for term '{$term}' must be integer, got " . gettype($key));
                }
            }
        }
    }

    public function testHmacVerificationSucceedsWithCorrectSecret(): void
    {
        $secret = 'test-secret';
        $writer = new ChunkWriter();
        $path   = $this->tmpDir . '/chunk-hmac.dat';
        $writer->write($path, $this->makePartial(0), $secret);

        $reader = new ChunkReader($path);
        $this->assertTrue($reader->verifyHmac($secret));
    }

    public function testHmacVerificationFailsWithWrongSecret(): void
    {
        $writer = new ChunkWriter();
        $path   = $this->tmpDir . '/chunk-hmac-bad.dat';
        $writer->write($path, $this->makePartial(0), 'correct-secret');

        $reader = new ChunkReader($path);
        $this->assertFalse($reader->verifyHmac('wrong-secret'));
    }

    public function testOpenIndexSkipsPagesWithoutReadingThem(): void
    {
        // Write a chunk with many pages — if openIndex() loads all pages into
        // RAM the memory use would be visible, but we just verify it yields terms.
        $pages = [];
        for ($i = 0; $i < 100; $i++) {
            $pages[$i] = ['url' => "/p/{$i}", 'wordCount' => 1, 'content' => 'x', 'meta' => [], 'filters' => []];
        }
        $partial = ['pages' => $pages, 'index' => ['foo' => [0 => ['positions' => [25 => [1]], 'meta_positions' => []]]]];

        $writer = new ChunkWriter();
        $path   = $this->tmpDir . '/chunk-skip.dat';
        $writer->write($path, $partial);

        $reader = new ChunkReader($path);
        $terms  = [];
        foreach ($reader->openIndex() as [$term]) {
            $terms[] = $term;
        }
        $this->assertSame(['foo'], $terms);
    }

    public function testOldChunkFormatThrowsException(): void
    {
        // Write a pre-0.2.5 serialized file.
        $path = $this->tmpDir . '/chunk-old.dat';
        file_put_contents($path, serialize(['index' => [], 'pages' => []]));

        $reader = new ChunkReader($path);
        $this->expectException(OldChunkFormatException::class);
        iterator_to_array($reader->openPages());
    }

    public function testEmptyPartialRoundTrip(): void
    {
        $writer = new ChunkWriter();
        $path   = $this->tmpDir . '/chunk-empty.dat';
        $writer->write($path, ['pages' => [], 'index' => []]);

        $reader = new ChunkReader($path);
        $pages  = iterator_to_array($reader->openPages());
        $terms  = [];
        foreach ($reader->openIndex() as $entry) {
            $terms[] = $entry;
        }

        $this->assertEmpty($pages);
        $this->assertEmpty($terms);
    }
}
