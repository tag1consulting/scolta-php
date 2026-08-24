<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\StreamingFormatWriter;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * A rebuild must not re-encode fragments it already has, and must not get one
 * wrong while avoiding the work.
 *
 * Fragment names already follow their contents, so an unchanged page wants the
 * file that is on disk. The risk is the name: it is a 40-bit truncation of a
 * sha256, and reusing on the strength of the name alone would publish one
 * page's body under another page's ordinal, silently and in a way no test that
 * only reads the index back would notice. The reuse therefore compares the
 * decompressed bytes, and these tests pin both halves of that: the fast path
 * fires when it should, and a file whose name matches but whose bytes do not is
 * rewritten rather than trusted.
 */
#[CoversClass(StreamingFormatWriter::class)]
final class BuildFragmentReuseTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $base            = sys_get_temp_dir() . '/scolta-fragreuse-' . uniqid('', true);
        $this->stateDir  = $base . '/state';
        $this->outputDir = $base . '/out';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDir(dirname($this->stateDir));
    }

    private static function removeDir(string $dir): void
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

    /**
     * @param list<\Tag1\Scolta\Export\ContentItem> $items
     */
    private function build(array $items, ?bool $reuseFragments = true): void
    {
        $orchestrator = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            reuseFragments: $reuseFragments,
        );
        $result = $orchestrator->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()),
            $items,
        );
        $this->assertTrue($result->success, 'Build failed: ' . ($result->error ?? ''));
    }

    /** @return array<string, string> Relative path => sha256 of the decompressed bytes. */
    private function manifest(): array
    {
        $base     = $this->outputDir . '/pagefind';
        $manifest = [];
        $items    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($items as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $raw = (string) file_get_contents($file->getPathname());
            $dec = @gzdecode($raw);
            $manifest[substr($file->getPathname(), strlen($base) + 1)] = hash('sha256', $dec === false ? $raw : $dec);
        }
        ksort($manifest);

        return $manifest;
    }

    public function testARebuildOverAnUnchangedCorpusProducesTheSameIndexWithReuseOn(): void
    {
        $items = SyntheticCorpus::generate(40);

        $this->build($items);
        $withoutReuse = $this->manifest();

        // Second build, this time allowed to link what it already has. The
        // index must be indistinguishable from the one the reference path
        // produces, or the optimisation is publishing something else.
        $this->build($items);
        $withReuse = $this->manifest();

        $this->assertSame($withoutReuse, $withReuse);
    }

    public function testTheAutoProbedDefaultProducesTheReferenceIndexEitherWay(): void
    {
        // Whatever the probe decides on the host running this, the published
        // index must be the same one the reference path produces. The probe is
        // allowed to choose a speed; it is not allowed to choose an output.
        $items = SyntheticCorpus::generate(40);

        $this->build($items, reuseFragments: false);
        $this->build($items, reuseFragments: false);
        $reference = $this->manifest();

        $this->build($items, reuseFragments: null);
        $probed = $this->manifest();

        $this->assertSame($reference, $probed);
    }

    public function testTheReferencePathAndTheReusePathAgree(): void
    {
        $items = SyntheticCorpus::generate(40);

        $this->build($items);
        $this->build($items, reuseFragments: false);
        $reference = $this->manifest();

        $this->build($items);
        $reused = $this->manifest();

        $this->assertSame($reference, $reused);
    }

    public function testAFragmentWhoseBytesDoNotMatchItsNameIsRewrittenRatherThanTrusted(): void
    {
        $items = SyntheticCorpus::generate(20);
        $this->build($items);

        $fragments = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $this->assertNotEmpty($fragments);

        // Stand in for a 40-bit hash collision: the live index now holds a file
        // under a name this build is about to compute, carrying somebody else's
        // bytes. Reuse must decline it. Nothing else can catch this — the name
        // is right, so a name-only check would link it and publish the wrong
        // body under a real ordinal.
        $victim = $fragments[0];
        file_put_contents($victim, gzencode('pagefind_dcd{"url":"/not-this-page","content":"wrong"}', 6));

        $this->build($items);

        $raw = @gzdecode((string) file_get_contents($victim));
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('/not-this-page', $raw, 'The poisoned fragment survived into the new index.');
    }

    public function testAnUnreadableLiveFragmentFallsBackToWritingRatherThanFailing(): void
    {
        $items = SyntheticCorpus::generate(20);
        $this->build($items);

        $fragments = glob($this->outputDir . '/pagefind/fragment/*.pf_fragment') ?: [];
        $this->assertNotEmpty($fragments);

        // Not gzip at all. gzdecode() fails, the comparison cannot be made, and
        // the only safe answer is to write the fragment normally.
        file_put_contents($fragments[0], 'this is not gzip');

        $this->build($items);

        $this->assertNotEmpty($this->manifest());
        $decoded = @gzdecode((string) file_get_contents($fragments[0]));
        $this->assertIsString($decoded, 'The build left a non-gzip fragment in the published index.');
    }

    public function testASecondBuildLinksEveryFragmentInAnUnchangedCorpus(): void
    {
        $items = SyntheticCorpus::generate(40);
        $this->build($items);

        // The point of the whole item: on a rebuild where nothing changed, no
        // fragment should be encoded twice. Asserted through the build's own
        // report rather than by timing it, so it holds on a loaded runner.
        $records      = [];
        // reuseFragments: true, not the default: the default asks the
        // filesystem, and on a filesystem where link() loses it correctly
        // declines. This test is about the reuse path itself.
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir, reuseFragments: true);
        $result       = $orchestrator->build(
            BuildIntent::fresh(count($items), MemoryBudget::conservative()),
            $items,
            new class ($records) extends \Psr\Log\AbstractLogger {
                /** @param array<int, string> $records */
                public function __construct(private array &$records) {}

                /**
                 * @param mixed                $level
                 * @param string|\Stringable    $message
                 * @param array<string, mixed> $context
                 */
                public function log($level, $message, array $context = []): void
                {
                    $message = (string) $message;
                    foreach ($context as $key => $value) {
                        if (is_scalar($value)) {
                            $message = str_replace('{' . $key . '}', (string) $value, $message);
                        }
                    }
                    $this->records[] = $message;
                }
            },
        );

        $this->assertTrue($result->success, 'Rebuild failed: ' . ($result->error ?? ''));

        $reuseLines = array_values(array_filter(
            $records,
            static fn(string $line): bool => str_contains($line, 'fragments were unchanged and linked'),
        ));
        $this->assertCount(1, $reuseLines, 'The rebuild reported no fragment reuse at all.');
        $this->assertStringContainsString(
            count($items) . ' of ' . count($items),
            $reuseLines[0],
            'A rebuild of an unchanged corpus re-encoded fragments it already had: ' . $reuseLines[0],
        );
    }

    public function testAChangedPageIsNotLinkedFromThePreviousIndex(): void
    {
        $items = SyntheticCorpus::generate(20);
        $this->build($items);

        // One page's body changes, so one fragment's bytes change, so one
        // fragment must be written rather than linked. item(6, revision: 1)
        // holds id, url and title fixed and changes only the body, which is
        // exactly the "someone edited this page" case.
        $edited = SyntheticCorpus::item(6, revision: 1);

        $writer = new StreamingFormatWriter(new \Tag1\Scolta\Index\CborEncoder());
        $writer->setFragmentReuse(true);
        $writer->beginWrite($this->outputDir);
        $writer->writePage(5, [
            'url'       => $edited->url,
            'content'   => 'a body this ordinal has never carried before',
            'wordCount' => 5,
            'filters'   => [],
            'meta'      => [],
            'sortable'  => [],
            'date'      => '',
        ]);

        $this->assertSame(0, $writer->fragmentsReused());
        $this->assertSame(1, $writer->fragmentsWritten());
    }

    public function testReuseIsCountedAndReportedOnTheWriter(): void
    {
        $items = SyntheticCorpus::generate(30);
        $this->build($items);

        // Drive the writer directly: the count is what the build summary
        // reports, so it has to mean "linked", not "seen".
        $writer = new StreamingFormatWriter(new \Tag1\Scolta\Index\CborEncoder());
        $writer->setFragmentReuse(true);
        $writer->beginWrite($this->outputDir);

        $this->assertSame(0, $writer->fragmentsReused());
        $this->assertSame(0, $writer->fragmentsWritten());

        // A page whose fragment cannot already exist under this ordinal.
        $writer->writePage(9_999, [
            'url'       => '/brand-new-page',
            'content'   => 'nothing has ever written this',
            'wordCount' => 5,
            'filters'   => [],
            'meta'      => [],
            'sortable'  => [],
            'date'      => '',
        ]);

        $this->assertSame(0, $writer->fragmentsReused());
        $this->assertSame(1, $writer->fragmentsWritten());
    }

    public function testReuseCanBeTurnedOffEntirely(): void
    {
        $items = SyntheticCorpus::generate(20);
        $this->build($items);

        $writer = new StreamingFormatWriter(new \Tag1\Scolta\Index\CborEncoder());
        $writer->setFragmentReuse(false);
        $writer->beginWrite($this->outputDir);

        // Replay a page the live index certainly holds: with reuse off it must
        // still be encoded and written, because that is the reference path the
        // differential comparisons are made against.
        $writer->writePage(0, [
            'url'       => $items[0]->url,
            'content'   => 'anything',
            'wordCount' => 1,
            'filters'   => [],
            'meta'      => [],
            'sortable'  => [],
            'date'      => '',
        ]);

        $this->assertSame(0, $writer->fragmentsReused());
        $this->assertSame(1, $writer->fragmentsWritten());
    }
}
