<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\CachedContentReference;

/**
 * Stay-in-sync guard between what the slim proxy reads and what the cached
 * reference can supply.
 *
 * IndexBuildOrchestrator::makeSlimProxy() builds the object the index builder
 * consumes, and it is handed either a full ContentItem (changed entity) or a
 * CachedContentReference (unchanged entity, timestamp-cache path). Every field
 * it reads off that object is therefore a contract: a field the proxy reads but
 * the reference cannot supply resolves through `?? []` to an empty value on the
 * cached path, which is the normal path, so the field is lost across the whole
 * corpus with no warning, no log line and no failing test.
 *
 * `metadata` shipped exactly that way: it joined ContentItem and the slim proxy
 * but not CachedContentReference, so arbitrary per-item meta keys survived a
 * forced rebuild and disappeared two plain builds later. `filters` and
 * `sortable` had their own passthrough tests; nothing asserted the set was
 * complete, which is what this test does.
 *
 * The asymmetry runs one way only, and deliberately. `entityKey` and
 * `contentHash` exist on the reference and not on the proxy: they are the cache
 * lookup keys the orchestrator consumes before it builds the proxy, so they are
 * expected on the other side of the diff rather than silently ignored, and
 * REFERENCE_ONLY names them. Adding a field to the reference that the proxy does
 * not read fails the reverse assertion until it is either read or justified
 * here.
 *
 * The parse is deliberately strict: the tripwire assertion on the extracted key
 * count runs BEFORE any diff, so a reformat of makeSlimProxy() that stops the
 * extraction matching fails loudly instead of passing while asserting nothing.
 */
class SlimProxyFieldParityTest extends TestCase
{
    /**
     * Promoted properties CachedContentReference has that the slim proxy does
     * not read.
     *
     * Both are cache-lookup keys the orchestrator reads directly off the
     * reference (PageWordCache::get($page->contentHash),
     * TimestampManifest::markSeen($page->entityKey)) before makeSlimProxy() is
     * called, so they have no business on the proxy.
     */
    private const REFERENCE_ONLY = [
        'contentHash',
        'entityKey',
    ];

    private static function orchestratorSource(): string
    {
        $path   = dirname(__DIR__, 2) . '/src/Index/IndexBuildOrchestrator.php';
        $source = file_get_contents($path);
        if ($source === false) {
            self::fail('Unable to read src/Index/IndexBuildOrchestrator.php');
        }

        return $source;
    }

    // ------------------------------------------------------------------
    // Forward: everything the proxy reads must be suppliable
    // ------------------------------------------------------------------

    /**
     * Every field makeSlimProxy() reads must exist as a promoted constructor
     * property on CachedContentReference.
     */
    public function test_every_slim_proxy_field_is_suppliable_by_the_cached_reference(): void
    {
        $proxyKeys  = $this->extractSlimProxyKeys(self::orchestratorSource());
        $refFields  = $this->cachedReferenceFields();

        foreach ($proxyKeys as $key) {
            $this->assertContains(
                $key,
                $refFields,
                sprintf(
                    'IndexBuildOrchestrator::makeSlimProxy() reads `$page->%s` but '
                    . 'CachedContentReference has no `%s` property, so the field resolves to an '
                    . 'empty value on the timestamp-cache path and is silently lost for every '
                    . 'unchanged entity. Add the property (and carry it in the manifest record).',
                    $key,
                    $key,
                ),
            );
        }
    }

    // ------------------------------------------------------------------
    // Reverse: nothing on the reference should be unread
    // ------------------------------------------------------------------

    /**
     * The only properties the reference carries beyond what the proxy reads are
     * the two cache-lookup keys. A third one means either a field the proxy
     * forgot to read or dead weight in the manifest record.
     */
    public function test_only_the_cache_lookup_keys_are_reference_only(): void
    {
        $proxyKeys = $this->extractSlimProxyKeys(self::orchestratorSource());
        $extra     = array_values(array_diff($this->cachedReferenceFields(), $proxyKeys));
        sort($extra);

        $this->assertSame(
            self::REFERENCE_ONLY,
            $extra,
            sprintf(
                'CachedContentReference carries [%s] beyond what makeSlimProxy() reads. Either '
                . 'the proxy should read the new field or it belongs in %s::REFERENCE_ONLY with '
                . 'a written justification.',
                implode(', ', $extra),
                __CLASS__,
            ),
        );
    }

    // ------------------------------------------------------------------
    // Extraction (tripwired)
    // ------------------------------------------------------------------

    /**
     * Distinct keys of the array literal makeSlimProxy() returns.
     *
     * The keys are matched rather than the `$page->x` reads because the literal
     * is the authoritative list of what reaches the index builder, and a read
     * outside the literal would not.
     *
     * @return list<string>
     */
    private function extractSlimProxyKeys(string $source): array
    {
        // fail() rather than assertNotFalse() at each step: it narrows the type for
        // the next strpos() offset, so a parse that stops matching reports the
        // reason it stopped instead of a TypeError three lines later.
        $start = strpos($source, 'private function makeSlimProxy(');
        if ($start === false) {
            $this->fail(
                'makeSlimProxy() not found in src/Index/IndexBuildOrchestrator.php — it may have '
                . 'been renamed. Update the parser in ' . __CLASS__ . ' so the guard keeps working.',
            );
        }

        $open = strpos($source, '[', $start);
        if ($open === false) {
            $this->fail('No array literal found in makeSlimProxy().');
        }

        $close = strpos($source, '];', $open);
        if ($close === false) {
            $this->fail('Unterminated array literal in makeSlimProxy().');
        }

        $literal = substr($source, $open, $close - $open);
        preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'\s*=>/", $literal, $matches);
        $keys = array_values(array_unique($matches[1]));

        // id, url, date, siteName, language, filters, sortable, metadata.
        $this->assertCount(
            8,
            $keys,
            'Expected exactly 8 fields in the makeSlimProxy() array literal but parsed '
            . count($keys) . '. Either a field was added, in which case check that '
            . 'CachedContentReference can supply it and move this count, or the method was '
            . 'reformatted so the extraction no longer matches, in which case update the parser '
            . 'in ' . __CLASS__ . ' so the guard keeps working.',
        );

        return $keys;
    }

    /**
     * Promoted constructor property names of CachedContentReference.
     *
     * Reflection rather than source parsing: the promoted properties ARE the
     * constructor parameters, so this cannot drift from what a caller can pass.
     *
     * @return list<string>
     */
    private function cachedReferenceFields(): array
    {
        $constructor = (new \ReflectionClass(CachedContentReference::class))->getConstructor();
        $this->assertNotNull($constructor, 'CachedContentReference has no constructor.');

        $fields = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isPromoted()) {
                $fields[] = $parameter->getName();
            }
        }

        $this->assertGreaterThanOrEqual(
            9,
            count($fields),
            'Parsed too few promoted properties from CachedContentReference — the constructor '
            . 'may have stopped promoting its parameters. Update ' . __CLASS__ . ' so the guard '
            . 'keeps working.',
        );

        return $fields;
    }
}
