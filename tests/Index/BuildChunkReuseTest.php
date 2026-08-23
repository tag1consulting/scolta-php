<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildChunkReuse;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\CachedContentReference;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Tests\Support\SyntheticCorpus;

/**
 * The gate on chunk reuse: a carried-forward chunk must be indistinguishable
 * from a rebuilt one, and must stop being carried forward the moment anything
 * it depends on moves.
 *
 * Reuse is the one optimisation here that can publish stale *content* rather
 * than merely be slow, because it skips reading the data it is reproducing. So
 * every test below compares against the reference path — the same build with
 * reuse switched off — on decompressed bytes, and the cases that must invalidate
 * a key each get their own test rather than being folded into one.
 */
#[CoversClass(BuildChunkReuse::class)]
final class BuildChunkReuseTest extends TestCase
{
    private const CHUNK_SIZE = 10;

    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $base            = sys_get_temp_dir() . '/scolta-chunkreuse-' . uniqid('', true);
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

    private function budget(): MemoryBudget
    {
        return MemoryBudget::conservative()->withChunkSize(self::CHUNK_SIZE);
    }

    /**
     * What a warm adapter build hands the orchestrator: one reference per
     * unchanged entity, carrying every field the chunk depends on but no body.
     */
    private static function reference(ContentItem $item): CachedContentReference
    {
        return new CachedContentReference(
            entityKey: $item->id,
            contentHash: PhpIndexer::contentHash($item),
            id: $item->id,
            url: $item->url,
            date: $item->date,
            siteName: $item->siteName,
            language: $item->language,
            filters: $item->filters,
            sortable: $item->sortable,
            metadata: $item->metadata,
        );
    }

    /**
     * @param list<ContentItem|CachedContentReference> $pages
     * @return array{chunksReused: int, chunksWritten: int, log: list<string>}
     */
    private function build(array $pages, bool $reuseChunks = true): array
    {
        $records = [];
        $logger  = new class ($records) extends \Psr\Log\AbstractLogger {
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
        };

        $orchestrator = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            // Fragment reuse off throughout: it is a separate optimisation with
            // its own tests, and leaving it to a filesystem probe would make
            // these assertions depend on the host.
            reuseFragments: false,
            reuseChunks: $reuseChunks,
        );
        $result = $orchestrator->build(
            BuildIntent::fresh(count($pages), $this->budget()),
            $pages,
            $logger,
        );
        $this->assertTrue($result->success, 'Build failed: ' . ($result->error ?? ''));

        $reused = 0;
        foreach ($records as $line) {
            if (preg_match('/\[scolta\] (\d+) of \d+ index chunks were unchanged/', $line, $m) === 1) {
                $reused = (int) $m[1];
            }
        }

        return ['chunksReused' => $reused, 'chunksWritten' => $result->chunksWritten, 'log' => $records];
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

    private function keysPath(): string
    {
        return $this->stateDir . '/' . BuildChunkReuse::DIRNAME . '/' . BuildChunkReuse::KEYS_FILENAME;
    }

    // ── (a) Warm build after cold ──────────────────────────────────────────

    public function testAWarmBuildReusesEveryChunkAndPublishesTheColdIndex(): void
    {
        $items = SyntheticCorpus::generate(50);

        $this->build($items);
        $cold = $this->manifest();

        $references = array_map(self::reference(...), $items);
        $warm       = $this->build($references);

        $this->assertSame(
            $cold,
            $this->manifest(),
            'A warm build that reused its chunks published something other than the cold index.',
        );
        $this->assertSame(
            $warm['chunksWritten'],
            $warm['chunksReused'],
            'A warm build over an unchanged corpus rebuilt a chunk it already had.',
        );
        $this->assertGreaterThan(1, $warm['chunksReused'], 'The corpus should span several chunks.');
    }

    public function testAWarmBuildWithReuseOffProducesTheSameIndex(): void
    {
        $items      = SyntheticCorpus::generate(50);
        $references = array_map(self::reference(...), $items);

        $this->build($items);
        $this->build($references, reuseChunks: false);
        $reference = $this->manifest();

        $this->build($references);
        $this->assertSame($reference, $this->manifest());
    }

    // ── (b) Edits and a delete dirty only their own ranges ─────────────────

    public function testEditsAndADeleteRebuildOnlyTheRangesTheyTouch(): void
    {
        $items = SyntheticCorpus::generate(50);
        $this->build($items);

        // Three edits and one delete. item(n, revision: 1) holds id, url and
        // title fixed and changes the body, so the content hash moves and the
        // page's range must be rebuilt; every other range must not be.
        $edited = [3 => true, 24 => true, 41 => true];
        $next   = [];
        foreach ($items as $i => $item) {
            $n = $i + 1;
            if ($n === 12) {
                continue; // deleted at the source
            }
            $next[] = isset($edited[$n])
                ? self::reference(SyntheticCorpus::item($n, revision: 1))
                : self::reference($item);
        }

        $second = $this->build($next);

        // Ordinals are 0-based and assigned in arrival order on a first build,
        // so item n has ordinal n-1 and range intdiv(n-1, CHUNK_SIZE).
        $dirtyRanges = [];
        foreach ([3, 24, 41, 12] as $n) {
            $dirtyRanges[intdiv($n - 1, self::CHUNK_SIZE)] = true;
        }

        $this->assertSame(
            $second['chunksWritten'] - count($dirtyRanges),
            $second['chunksReused'],
            'Exactly the ranges holding an edited or deleted page should have been rebuilt.',
        );
        $withReuse = $this->manifest();

        // The reference: a fresh full build of the same ledger state with reuse
        // switched off. Same state directory, so the same ordinals.
        $this->build($next, reuseChunks: false);
        $this->assertSame(
            $this->manifest(),
            $withReuse,
            'A build that reused chunks around three edits and a delete did not match the reference path.',
        );
    }

    public function testAChangedFilterDirtiesTheChunkEvenThoughTheBodyDidNot(): void
    {
        // The case a content-hash key gets wrong. contentHash() covers language,
        // title, url and body — not filters — so a facet change leaves it
        // untouched while changing the chunk's page record and every facet
        // posting derived from it.
        $items = SyntheticCorpus::generate(30);
        $this->build($items);

        $references = [];
        foreach ($items as $i => $item) {
            if ($i === 5) {
                $references[] = new CachedContentReference(
                    entityKey: $item->id,
                    contentHash: PhpIndexer::contentHash($item),
                    id: $item->id,
                    url: $item->url,
                    date: $item->date,
                    siteName: $item->siteName,
                    language: $item->language,
                    filters: $item->filters + ['audience' => ['Teachers']],
                    sortable: $item->sortable,
                    metadata: $item->metadata,
                );
                continue;
            }
            $references[] = self::reference($item);
        }

        $second = $this->build($references);
        $this->assertSame(
            $second['chunksWritten'] - 1,
            $second['chunksReused'],
            'A page whose filters changed must dirty its chunk despite an unchanged content hash.',
        );

        $withReuse = $this->manifest();
        $this->build($references, reuseChunks: false);
        $this->assertSame($this->manifest(), $withReuse);
    }

    // ── (c) Compaction invalidates every key ───────────────────────────────

    public function testACompactionInvalidatesEveryKey(): void
    {
        $items = SyntheticCorpus::generate(30);
        $this->build($items);

        $references = array_map(self::reference(...), $items);

        // reset() is compaction: it renumbers from zero on the next build, and a
        // carried-forward chunk file has the old ordinals inside it. Every key
        // must fail, because the ordinal is part of the identity.
        $orchestrator = new IndexBuildOrchestrator($this->stateDir, $this->outputDir);
        $orchestrator->pageTableLedger()->reset();
        $orchestrator->pageTableLedger()->save();

        // Bodies, not references: a compacted ledger has no ordinals to look
        // token data up against, which is what a real compaction is followed by.
        $after = $this->build($items);
        $this->assertSame(0, $after['chunksReused'], 'A compaction left chunk keys standing.');

        $compacted = $this->manifest();
        $this->build($items, reuseChunks: false);
        $this->assertSame($this->manifest(), $compacted);

        // A compacted table renumbers from zero, so the page count is intact
        // and every ordinal is below it.
        $this->assertCount(count($items), glob($this->outputDir . '/pagefind/fragment/*') ?: []);
        $this->assertSame(count($references), count($items));
    }

    public function testADifferentChunkSizeInvalidatesEveryKey(): void
    {
        // Ranges are cut on the chunk size, so a build with a different size is
        // asking about ranges that never existed. The header catches it.
        $this->assertNotSame(
            BuildChunkReuse::header(10, 'en', null),
            BuildChunkReuse::header(50, 'en', null),
        );
        $this->assertNotSame(
            BuildChunkReuse::header(10, 'en', null),
            BuildChunkReuse::header(10, 'fr', null),
        );
        $this->assertNotSame(
            BuildChunkReuse::header(10, 'en', null),
            BuildChunkReuse::header(10, 'en', 'a-secret'),
        );
        $this->assertSame(
            BuildChunkReuse::header(10, 'en', null),
            BuildChunkReuse::header(10, 'en', '   '),
            'An empty secret is no secret, the way HmacSecret::normalize() reads it.',
        );
    }

    // ── (d) Missing or corrupt carried-forward state ───────────────────────

    public function testAMissingCarriedForwardChunkFallsBackToRebuilding(): void
    {
        $items = SyntheticCorpus::generate(30);
        $this->build($items);

        $carried = glob($this->stateDir . '/' . BuildChunkReuse::DIRNAME . '/range-*.dat') ?: [];
        $this->assertNotEmpty($carried, 'The build carried no chunk files forward.');
        unlink($carried[0]);

        $references = array_map(self::reference(...), $items);
        $after      = $this->build($references);

        // One range lost its file, so one range is rebuilt; nothing errors.
        $this->assertSame($after['chunksWritten'] - 1, $after['chunksReused']);

        $recovered = $this->manifest();
        $this->build($references, reuseChunks: false);
        $this->assertSame($this->manifest(), $recovered);
    }

    public function testACorruptKeysFileFallsBackToRebuildingEverything(): void
    {
        $items = SyntheticCorpus::generate(30);
        $this->build($items);

        file_put_contents($this->keysPath(), 'this is not a serialized array');

        $references = array_map(self::reference(...), $items);
        $after      = $this->build($references);

        $this->assertSame(0, $after['chunksReused'], 'A corrupt keys file was trusted.');

        $rebuilt = $this->manifest();
        $this->build($references, reuseChunks: false);
        $this->assertSame($this->manifest(), $rebuilt);
    }

    public function testKeysWrittenUnderADifferentHeaderAreIgnored(): void
    {
        $items = SyntheticCorpus::generate(30);
        $this->build($items);

        $state = unserialize((string) file_get_contents($this->keysPath()), ['allowed_classes' => false]);
        $this->assertIsArray($state);
        $state['header'] = 'from-an-incompatible-build';
        file_put_contents($this->keysPath(), serialize($state));

        $references = array_map(self::reference(...), $items);
        $after      = $this->build($references);

        $this->assertSame(0, $after['chunksReused']);

        $rebuilt = $this->manifest();
        $this->build($references, reuseChunks: false);
        $this->assertSame($this->manifest(), $rebuilt);
    }

    // ── Unit-level behaviour of the key itself ─────────────────────────────

    public function testPageIdentityCoversEveryFieldTheChunkDependsOn(): void
    {
        $item = SyntheticCorpus::item(1);
        $base = BuildChunkReuse::pageIdentity(7, 'hash', $item);

        // The ordinal is part of it: a compaction can move a page without
        // changing anything about the page, and the chunk file carries ordinals.
        $this->assertNotSame($base, BuildChunkReuse::pageIdentity(8, 'hash', $item));
        $this->assertNotSame($base, BuildChunkReuse::pageIdentity(7, 'other-hash', $item));

        $vary = static fn(array $overrides): object => (object) (array_merge([
            'id'       => $item->id,
            'url'      => $item->url,
            'date'     => $item->date,
            'siteName' => $item->siteName,
            'language' => $item->language,
            'filters'  => $item->filters,
            'sortable' => $item->sortable,
            'metadata' => $item->metadata,
        ], $overrides));

        $this->assertSame($base, BuildChunkReuse::pageIdentity(7, 'hash', $vary([])));

        foreach ([
            'id'       => 'a-different-id',
            'url'      => '/a-different-url',
            'date'     => '1999-12-31',
            'siteName' => 'Another Site',
            'language' => 'fr',
            'filters'  => ['content_type' => 'Something Else'],
            'sortable' => ['weight' => '42'],
            'metadata' => ['entity_id' => '9999'],
        ] as $field => $value) {
            $this->assertNotSame(
                $base,
                BuildChunkReuse::pageIdentity(7, 'hash', $vary([$field => $value])),
                "pageIdentity() ignores {$field}, which the chunk file carries.",
            );
        }
    }

    public function testARangeKeyIsIndependentOfArrivalOrder(): void
    {
        // Two gatherers may yield the same pages in different orders. The chunk
        // they produce is the same, so the key must be too.
        $this->assertSame(
            BuildChunkReuse::rangeKey([0 => 'a', 1 => 'b', 2 => 'c']),
            BuildChunkReuse::rangeKey([2 => 'c', 0 => 'a', 1 => 'b']),
        );
        $this->assertNotSame(
            BuildChunkReuse::rangeKey([0 => 'a', 1 => 'b']),
            BuildChunkReuse::rangeKey([0 => 'a', 1 => 'b', 2 => 'c']),
        );
        // Same identities against different ordinals is a different chunk.
        $this->assertNotSame(
            BuildChunkReuse::rangeKey([0 => 'a', 1 => 'b']),
            BuildChunkReuse::rangeKey([0 => 'b', 1 => 'a']),
        );
    }
}
