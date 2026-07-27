<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\CborEncoder;
use Tag1\Scolta\Index\PagefindFormatWriter;
use Tag1\Scolta\Index\StreamingFormatWriter;

/**
 * An unencodable fragment must fail the build, not ship a corrupt index.
 *
 * Both fragment writers concatenated json_encode()'s return value without
 * checking it. On invalid UTF-8 that return is false, false concatenates as the
 * empty string, and the fragment file ends up containing nothing but the
 * twelve-byte delimiter. Nothing upstream notices; the failure surfaces as a
 * JSON.parse() error in the browser, on one result, at search time.
 *
 * FacetIndexWriter::build() already guarded correctly, so this is the same
 * pattern applied to the two writers that did not.
 */
class FragmentEncodingFailureTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scolta-fragenc-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    /** A lone 0x80 continuation byte: valid Latin-1, never valid UTF-8. */
    private function invalidUtf8(): string
    {
        return "broken \x80 content";
    }

    private function pageData(string $content): array
    {
        return [
            'url'       => '/broken',
            'content'   => $content,
            'wordCount' => 3,
            'filters'   => [],
            'meta'      => ['title' => 'Broken'],
            'sortable'  => [],
            'date'      => '2026-01-01',
        ];
    }

    public function testStreamingWriterThrowsOnUnencodableFragment(): void
    {
        $writer = new StreamingFormatWriter(new CborEncoder());
        $writer->beginWrite($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to encode fragment for page 0/');

        $writer->writePage(0, $this->pageData($this->invalidUtf8()));
    }

    public function testStreamingWriterWritesNoTruncatedFragmentFile(): void
    {
        $writer = new StreamingFormatWriter(new CborEncoder());
        $writer->beginWrite($this->tmpDir);

        try {
            $writer->writePage(0, $this->pageData($this->invalidUtf8()));
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException) {
            // Expected.
        }

        // The delimiter-only fragment that used to reach disk is the actual
        // damage; the exception is only how it gets reported.
        $fragments = glob($this->tmpDir . '/.scolta-building/fragment/*.pf_fragment') ?: [];
        $this->assertSame([], $fragments);
    }

    public function testStreamingWriterStillWritesAValidFragment(): void
    {
        $writer = new StreamingFormatWriter(new CborEncoder());
        $writer->beginWrite($this->tmpDir);
        $writer->writePage(0, $this->pageData('perfectly fine content'));

        $fragments = glob($this->tmpDir . '/.scolta-building/fragment/*.pf_fragment') ?: [];
        $this->assertCount(1, $fragments);
    }

    public function testPagefindWriterThrowsOnUnencodableFragment(): void
    {
        $writer = new PagefindFormatWriter(new CborEncoder());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to encode fragment for page 0/');

        $writer->write([], [0 => $this->pageData($this->invalidUtf8())], $this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
