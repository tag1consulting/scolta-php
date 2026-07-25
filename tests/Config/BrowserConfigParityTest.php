<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Config;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Stay-in-sync guard between what the browser reads and what PHP emits.
 *
 * assets/js/scolta.js is the canonical browser bundle. Every config value it
 * consumes is read off the instance config object that toBrowserConfig()
 * produces, so the two are a contract: a key the bundle reads but no config
 * layer emits is a feature that is dead on arrival, and a key PHP emits but the
 * bundle never reads is dead weight. Three scoring keys
 * (SPECIFICITY_COOCCURRENCE, SPECIFICITY_AGREEMENT_GATE,
 * SPECIFICITY_AGREEMENT_DECAY) shipped readable-but-unsettable for exactly that
 * reason: nothing asserted the emitted config covered what the browser reads.
 *
 * This test parses scolta.js for the keys it reads and diffs them against
 * toBrowserConfig(), in both directions, recursing one level into the `scoring`
 * and `endpoints` sub-objects (a top-level-only check passes while a scoring
 * sub-key is missing, which is how those three hid).
 *
 * Two deliberate design choices:
 *
 * - **Comments are NOT stripped before matching.** Naively cutting `//` to end
 *   of line would corrupt every line containing a URL such as `https://` and
 *   could silently drop a real key. Today exactly one comment names a config
 *   key (`instanceConfig.currentLanguage`) and that key is real, so comment
 *   noise produces zero phantoms. If a future comment does introduce a phantom,
 *   this test fails loudly and the maintainer either emits the key or adds it to
 *   an allowlist with a written justification. Loud and occasionally wrong beats
 *   silent and blind.
 * - **The reverse assertion uses strict set membership, not a substring search
 *   of the bundle.** A substring search over 3,300 lines matches almost any
 *   plausible camelCase name and would make the assertion worthless. Strict
 *   membership against the extracted set is what actually catches a dead key.
 *
 * The parse is deliberately strict: the tripwire assertions run BEFORE any diff,
 * so a reformat of scolta.js that stops the extraction matching fails loudly
 * instead of passing while asserting nothing.
 */
class BrowserConfigParityTest extends TestCase
{
    /**
     * Keys scolta.js reads that toBrowserConfig() deliberately does not emit.
     *
     * This list subtracts from the extracted set, so it may only ever contain
     * keys the bundle actually reads.
     */
    private const FORWARD_ALLOWLIST = [
        // Supplied by the platform's language layer, not by the config object.
        'currentLanguage',
        // Has no config property. Adapters pass an empty array; a direct caller
        // supplies it through createInstance().
        'allowedLinkDomains',
        // Same as allowedLinkDomains: caller-supplied, no config property.
        'disclaimer',
        // Emitted by no adapter at all; caller-supplied through the
        // createInstance() public API only. Note the snake_case name, unlike
        // every other top-level key.
        'priority_pages',
    ];

    /**
     * Keys toBrowserConfig() emits that scolta.js does not read off the
     * instance config.
     *
     * This list subtracts from the emitted set, so it may only ever contain
     * keys this repo actually emits. Empty here: scolta-php emits nothing the
     * browser does not read.
     */
    private const REVERSE_ALLOWLIST = [];

    private static function bundlePath(): string
    {
        return dirname(__DIR__, 2) . '/assets/js/scolta.js';
    }

    private static function bundleSource(): string
    {
        $source = file_get_contents(self::bundlePath());
        self::assertNotFalse($source, 'Unable to read assets/js/scolta.js');

        return $source;
    }

    // ------------------------------------------------------------------
    // Forward: everything the browser reads must be emitted
    // ------------------------------------------------------------------

    /**
     * Every top-level key scolta.js reads off the instance config must be
     * emitted by toBrowserConfig(), minus the forward allowlist.
     */
    public function test_browser_read_top_level_keys_are_emitted(): void
    {
        $read     = $this->extractTopLevelKeys(self::bundleSource());
        $emitted  = array_keys((new ScoltaConfig())->toBrowserConfig());
        $required = array_diff($read, self::FORWARD_ALLOWLIST);

        foreach ($required as $key) {
            $this->assertContains(
                $key,
                $emitted,
                sprintf(
                    'scolta.js reads `instanceConfig.%s` but ScoltaConfig::toBrowserConfig() '
                    . 'does not emit it, so the feature behind it is unreachable. Either emit '
                    . 'the key or add it to %s::FORWARD_ALLOWLIST with a written justification.',
                    $key,
                    __CLASS__,
                ),
            );
        }
    }

    /**
     * Every scoring sub-key scolta.js reads must be emitted inside the
     * `scoring` array. Asserting only the top level is not enough: `scoring` is
     * an object, so a top-level presence check passes while sub-keys are absent.
     */
    public function test_browser_read_scoring_keys_are_emitted(): void
    {
        $read    = $this->extractScoringKeys(self::bundleSource());
        $emitted = array_keys((new ScoltaConfig())->toBrowserConfig()['scoring']);

        foreach ($read as $key) {
            $this->assertContains(
                $key,
                $emitted,
                sprintf(
                    'scolta.js reads scoring key `%s` but ScoltaConfig::toJsScoringConfig() '
                    . 'does not emit it, so it can only ever take its hardcoded JS fallback. '
                    . 'Add a config property for it.',
                    $key,
                ),
            );
        }
    }

    /**
     * Every endpoint sub-key scolta.js reads must be emitted inside the
     * `endpoints` array.
     */
    public function test_browser_read_endpoint_keys_are_emitted(): void
    {
        $read    = $this->extractEndpointKeys(self::bundleSource());
        $emitted = array_keys((new ScoltaConfig())->toBrowserConfig()['endpoints']);

        foreach ($read as $key) {
            $this->assertContains(
                $key,
                $emitted,
                sprintf(
                    'scolta.js reads endpoint `%s` but ScoltaConfig::toBrowserConfig() does '
                    . 'not emit it in `endpoints`.',
                    $key,
                ),
            );
        }
    }

    // ------------------------------------------------------------------
    // Reverse: nothing emitted should be dead
    // ------------------------------------------------------------------

    /**
     * Every top-level key toBrowserConfig() emits must be one scolta.js
     * actually reads, minus the reverse allowlist. Separate from the forward
     * assertions so it can be allowlisted independently.
     *
     * Semantics are strict set membership against the extracted key set, not a
     * substring search of the bundle (see the class docblock).
     */
    public function test_emitted_top_level_keys_are_read_by_the_browser(): void
    {
        $read    = $this->extractTopLevelKeys(self::bundleSource());
        $emitted = array_diff(
            array_keys((new ScoltaConfig())->toBrowserConfig()),
            self::REVERSE_ALLOWLIST,
        );

        foreach ($emitted as $key) {
            $this->assertContains(
                $key,
                $read,
                sprintf(
                    'ScoltaConfig::toBrowserConfig() emits `%s` but scolta.js never reads it '
                    . 'off the instance config, so it is dead weight in every page payload. '
                    . 'Either drop it or add it to %s::REVERSE_ALLOWLIST with a written '
                    . 'justification.',
                    $key,
                    __CLASS__,
                ),
            );
        }
    }

    // ------------------------------------------------------------------
    // Extraction (tripwired)
    // ------------------------------------------------------------------

    /**
     * Distinct top-level keys read as `instanceConfig.<key>`.
     *
     * @return list<string>
     */
    private function extractTopLevelKeys(string $source): array
    {
        preg_match_all('/instanceConfig\.([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches);
        $keys = array_values(array_unique($matches[1]));

        $this->assertGreaterThanOrEqual(
            11,
            count($keys),
            'Parsed too few top-level config reads from assets/js/scolta.js — the bundle '
            . 'may have been reformatted so `instanceConfig.<key>` no longer matches. '
            . 'Update the parser in ' . __CLASS__ . ' so the guard keeps working.',
        );

        return $keys;
    }

    /**
     * Distinct scoring keys read as `KEY: s.KEY ??` in the config return
     * literals.
     *
     * The regex matches two return literals, the module-level getConfig() block
     * and the getInstanceConfig() block, and their union is the full set only
     * because the former's keys are a strict subset of the latter's. That holds
     * today; if it ever stops holding, the tripwire count below moves and
     * whoever hits it reads this note.
     *
     * Parsing the literals rather than grepping consumption sites is deliberate:
     * several keys are forwarded to WASM wholesale and never named at a use
     * site, so a consumption-site grep would silently miss them.
     *
     * @return list<string>
     */
    private function extractScoringKeys(string $source): array
    {
        preg_match_all('/^\s*([A-Z][A-Z0-9_]*):\s*s\.\1\s*\?\?/m', $source, $matches);
        $keys = array_values(array_unique($matches[1]));

        $this->assertGreaterThanOrEqual(
            40,
            count($keys),
            'Parsed too few scoring keys from assets/js/scolta.js — the getInstanceConfig() '
            . 'return literal may have been reformatted so `KEY: s.KEY ??` no longer matches. '
            . 'Update the parser in ' . __CLASS__ . ' so the guard keeps working.',
        );

        return $keys;
    }

    /**
     * Distinct endpoint keys read as `key: e.key ||`.
     *
     * @return list<string>
     */
    private function extractEndpointKeys(string $source): array
    {
        preg_match_all('/^\s*([a-z]+):\s*e\.\1\s*\|\|/m', $source, $matches);
        $keys = array_values(array_unique($matches[1]));

        $this->assertCount(
            3,
            $keys,
            'Expected exactly 3 endpoint keys in assets/js/scolta.js (expand, summarize, '
            . 'followup) but parsed ' . count($keys) . '. Either an endpoint was added or the '
            . 'bundle was reformatted so `key: e.key ||` no longer matches. Update the parser '
            . 'in ' . __CLASS__ . ' so the guard keeps working.',
        );

        return $keys;
    }
}
