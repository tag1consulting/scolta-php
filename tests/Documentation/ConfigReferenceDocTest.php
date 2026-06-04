<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Documentation;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Stay-in-sync guard for docs/CONFIG_REFERENCE.md.
 *
 * CONFIG_REFERENCE.md is the single source of truth for ScoltaConfig defaults
 * and presets. Several other docs used to restate those numbers and drifted.
 * This test asserts that every scalar default and every meaningful preset
 * override documented in CONFIG_REFERENCE.md actually matches the live
 * ScoltaConfig class — so the doc can never silently diverge from the code.
 *
 * The parse is deliberately strict: if a property/preset row can no longer be
 * read (e.g. a doc reformat), the relevant "found at least N rows" assertion
 * fails loudly rather than silently skipping the check.
 */
class ConfigReferenceDocTest extends TestCase
{
    private const SCALAR_TYPES = ['string', 'int', 'float', 'bool'];

    private static function docPath(): string
    {
        return dirname(__DIR__, 2) . '/docs/CONFIG_REFERENCE.md';
    }

    private static function docContent(): string
    {
        $content = file_get_contents(self::docPath());
        self::assertNotFalse($content, 'Unable to read docs/CONFIG_REFERENCE.md');

        return $content;
    }

    // ------------------------------------------------------------------
    // Base defaults
    // ------------------------------------------------------------------

    /**
     * Every scalar default documented in CONFIG_REFERENCE.md's property tables
     * must equal the live default from a fresh ScoltaConfig.
     */
    public function test_documented_scalar_defaults_match_live_config(): void
    {
        $documented = $this->parsePropertyDefaults(self::docContent());

        // Fail loudly if the parser stopped matching the property tables.
        $this->assertGreaterThanOrEqual(
            30,
            count($documented),
            'Parsed too few property rows from CONFIG_REFERENCE.md — the property '
            . 'tables may have been reformatted. Update the parser in '
            . __CLASS__ . ' so the guard keeps working.'
        );

        $config = new ScoltaConfig();

        // Sanity-check the required scalar properties are all covered, so a
        // future doc edit that drops one of them is caught here too.
        $required = [
            'titleMatchBoost', 'recencyBoostMax', 'crossListBonus', 'contentMatchBoost',
            'expandPrimaryWeight', 'expandSubwordMaxFrequency', 'titleAllTermsMultiplier',
            'exactTitleMatchBoost', 'phraseAdjacentMultiplier', 'phraseNearMultiplier',
            'phraseWindow', 'phraseNearWindow', 'maxPagefindResults', 'resultsPerPage',
            'excerptLength', 'recencyHalfLifeDays', 'recencyMaxPenalty', 'recencyPenaltyAfterDays',
        ];
        foreach ($required as $name) {
            $this->assertArrayHasKey(
                $name,
                $documented,
                "Required scalar property `$name` is missing from CONFIG_REFERENCE.md."
            );
        }

        foreach ($documented as $property => $docDefault) {
            // Array/non-scalar defaults are not value-checked here.
            if ($docDefault['isScalar'] === false) {
                continue;
            }

            $this->assertTrue(
                property_exists($config, $property),
                "CONFIG_REFERENCE.md documents `$property` with a scalar default, "
                . 'but no such property exists on ScoltaConfig (renamed or removed?).'
            );

            $live = $config->$property;
            $this->assertTrue(
                $this->valuesMatch($live, $docDefault['raw']),
                sprintf(
                    'Default drift for `%s`: CONFIG_REFERENCE.md documents `%s` but '
                    . 'ScoltaConfig has `%s`. Update CONFIG_REFERENCE.md (the source of truth).',
                    $property,
                    $docDefault['raw'],
                    var_export($live, true)
                )
            );
        }
    }

    /**
     * Every scalar property on ScoltaConfig must be documented, so a newly
     * added scalar property cannot ship undocumented.
     */
    public function test_every_scalar_config_property_is_documented(): void
    {
        $documented = $this->parsePropertyDefaults(self::docContent());
        $config     = new ScoltaConfig();

        $reflection = new \ReflectionClass($config);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $type = $prop->getType()?->getName();
            if (!in_array($type, self::SCALAR_TYPES, true)) {
                continue; // arrays are documented but not value-checked
            }

            $this->assertArrayHasKey(
                $prop->getName(),
                $documented,
                sprintf(
                    'ScoltaConfig::$%s (%s) is not documented in CONFIG_REFERENCE.md. '
                    . 'Add it to the property tables.',
                    $prop->getName(),
                    $type
                )
            );
        }
    }

    // ------------------------------------------------------------------
    // Presets
    // ------------------------------------------------------------------

    /**
     * Every preset must appear in CONFIG_REFERENCE.md, and every preset value
     * that differs from the base default must be documented with a matching
     * value. (Redundant overrides that equal the base default may be omitted
     * from the doc's summary column.)
     */
    public function test_documented_presets_match_live_presets(): void
    {
        $docPresets = $this->parsePresetValues(self::docContent());

        $this->assertNotEmpty(
            $docPresets,
            'Parsed no presets from CONFIG_REFERENCE.md — the preset table may '
            . 'have been reformatted. Update the parser in ' . __CLASS__ . '.'
        );

        $defaults = new ScoltaConfig();

        foreach (ScoltaConfig::PRESETS as $name => $preset) {
            $this->assertArrayHasKey(
                $name,
                $docPresets,
                "Preset `$name` exists in ScoltaConfig::PRESETS but is not documented in CONFIG_REFERENCE.md."
            );

            $documentedCell = $docPresets[$name];

            foreach ($preset['values'] as $snakeKey => $value) {
                $camelKey = lcfirst(str_replace('_', '', ucwords($snakeKey, '_')));

                if (array_key_exists($camelKey, $documentedCell)) {
                    // Documented — the value must match.
                    $this->assertTrue(
                        $this->valuesMatch($value, $documentedCell[$camelKey]),
                        sprintf(
                            'Preset `%s` value drift for `%s`: CONFIG_REFERENCE.md documents '
                            . '`%s` but ScoltaConfig::PRESETS has `%s`.',
                            $name,
                            $camelKey,
                            $documentedCell[$camelKey],
                            var_export($value, true)
                        )
                    );
                    continue;
                }

                // Not documented — only acceptable if it is a redundant no-op
                // override (equal to the base default).
                $this->assertTrue(
                    property_exists($defaults, $camelKey) && $this->valuesMatch($defaults->$camelKey, (string) $value),
                    sprintf(
                        'Preset `%s` sets `%s` to a non-default value but it is not '
                        . 'documented in CONFIG_REFERENCE.md.',
                        $name,
                        $camelKey
                    )
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Parsing helpers
    // ------------------------------------------------------------------

    /**
     * Parse the "## Configuration Properties" section into a map of
     * camelCase property name => ['raw' => string, 'isScalar' => bool].
     *
     * Only rows shaped `| `prop` | <type> | `default` | desc |` where <type>
     * is one of string/int/float/bool/array are accepted, which excludes the
     * header, separator, and platform-mapping rows.
     *
     * @return array<string, array{raw: string, isScalar: bool}>
     */
    private function parsePropertyDefaults(string $doc): array
    {
        $section = $this->sliceBetween(
            $doc,
            '## Configuration Properties',
            '## Platform Config Mapping'
        );
        $this->assertNotSame('', $section, 'Could not locate the Configuration Properties section.');

        $result = [];
        foreach (explode("\n", $section) as $line) {
            if (!str_starts_with(trim($line), '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line)));
            // Leading and trailing pipes produce empty first/last cells.
            // cells[1]=property, [2]=type, [3]=default, [4]=description.
            if (count($cells) < 5) {
                continue;
            }
            $type = $cells[2];
            if (!in_array($type, ['string', 'int', 'float', 'bool', 'array'], true)) {
                continue;
            }
            if (!preg_match('/^`([a-zA-Z][a-zA-Z0-9]*)`$/', $cells[1], $m)) {
                continue;
            }
            $property = $m[1];
            $rawDefault = trim($cells[3], '`');

            $result[$property] = [
                'raw'      => $rawDefault,
                'isScalar' => $type !== 'array',
            ];
        }

        return $result;
    }

    /**
     * Parse the "Available presets" table into a map of
     * preset name => (camelCase key => documented value string).
     *
     * The "Key `values`" column lists overrides as backtick-wrapped
     * `camelKey: value` pairs, which is what this extracts.
     *
     * @return array<string, array<string, string>>
     */
    private function parsePresetValues(string $doc): array
    {
        $section = $this->sliceBetween($doc, 'Available presets:', '### Choosing a Preset');
        $this->assertNotSame('', $section, 'Could not locate the preset table section.');

        $presetNames = array_keys(ScoltaConfig::PRESETS);
        $result = [];
        foreach (explode("\n", $section) as $line) {
            if (!str_starts_with(trim($line), '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line)));
            if (count($cells) < 2 || !preg_match('/^`([a-zA-Z_]+)`$/', $cells[1], $m)) {
                continue;
            }
            $name = $m[1];
            if (!in_array($name, $presetNames, true)) {
                continue;
            }
            // The last non-empty cell is the "Key `values`" column (a row that
            // ends with `|` produces a trailing empty cell we must skip).
            $nonEmpty = array_values(array_filter($cells, static fn ($c) => $c !== ''));
            $valuesCell = end($nonEmpty);
            $pairs = [];
            if (preg_match_all('/`([a-zA-Z]+):\s*([^`]+)`/', $valuesCell, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $pair) {
                    $pairs[$pair[1]] = trim($pair[2]);
                }
            }
            $this->assertNotEmpty(
                $pairs,
                "Preset row `$name` in CONFIG_REFERENCE.md has no parseable `key: value` overrides."
            );
            $result[$name] = $pairs;
        }

        return $result;
    }

    /**
     * Return the substring of $doc between $start and $end markers (exclusive
     * of $end), or '' if either marker is missing.
     */
    private function sliceBetween(string $doc, string $start, string $end): string
    {
        $from = strpos($doc, $start);
        if ($from === false) {
            return '';
        }
        $to = strpos($doc, $end, $from);

        return $to === false
            ? substr($doc, $from)
            : substr($doc, $from, $to - $from);
    }

    /**
     * Compare a live PHP value against a documented token, normalizing number
     * and quote formatting so `2.0`, `2`, and `2.00` all match, and `'none'`
     * matches `none`.
     */
    private function valuesMatch(mixed $live, string $docToken): bool
    {
        $docToken = trim($docToken, ' `');

        if (is_bool($live)) {
            return $docToken === ($live ? 'true' : 'false');
        }

        if (is_int($live) || is_float($live)) {
            if (!is_numeric($docToken)) {
                return false;
            }

            return abs((float) $docToken - (float) $live) < 1e-9;
        }

        // String: tolerate surrounding single or double quotes in the doc.
        $docToken = trim($docToken, "'\"");

        return (string) $live === $docToken;
    }
}
