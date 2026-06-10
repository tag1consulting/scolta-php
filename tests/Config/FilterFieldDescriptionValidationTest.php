<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Config;

use PHPUnit\Framework\TestCase;

class FilterFieldDescriptionValidationTest extends TestCase
{
    /**
     * Extract enumerated values from a filter_field_descriptions string.
     *
     * Recognizes two formats:
     *   "Valid values: Foo, Bar, Baz"
     *   "Values: Foo, Bar, Baz"
     *
     * @return string[] extracted values, or empty if no enumerated list found
     */
    private function extractEnumeratedValues(string $description): array
    {
        // Match "Valid values:" or "Values:" followed by a comma-separated list.
        if (!preg_match('/(?:Valid v|V)alues:\s*(.+)/i', $description, $m)) {
            return [];
        }

        $raw = $m[1];
        // Split on commas, trim quotes and whitespace.
        $values = array_map(
            fn(string $v) => trim($v, " \t\n\r\0\x0B\"'"),
            explode(',', $raw),
        );

        return array_filter($values, fn(string $v) => $v !== '');
    }

    public function testExtractsValidValuesFormat(): void
    {
        $desc = 'Subject area or domain. Valid values: Arts, Biography, Science';
        $values = $this->extractEnumeratedValues($desc);
        $this->assertSame(['Arts', 'Biography', 'Science'], $values);
    }

    public function testExtractsValuesWithQuotes(): void
    {
        $desc = 'Geographic region. Values: Africa, Americas, "Global / Multiple Regions", Oceania';
        $values = $this->extractEnumeratedValues($desc);
        $this->assertContains('Africa', $values);
        $this->assertContains('Americas', $values);
        $this->assertContains('Global / Multiple Regions', $values);
        $this->assertContains('Oceania', $values);
    }

    public function testReturnsEmptyForFreeformDescription(): void
    {
        $desc = 'Total number of words in the article (typically 2,000–15,000)';
        $this->assertSame([], $this->extractEnumeratedValues($desc));
    }

    /**
     * @dataProvider validDescriptionProvider
     */
    public function testDescriptionValuesExistInIndex(
        string $field,
        string $description,
        array $actualValues,
    ): void {
        $described = $this->extractEnumeratedValues($description);
        if (empty($described)) {
            $this->markTestSkipped("No enumerated values in description for '{$field}'");
        }

        $missing = array_diff($described, $actualValues);
        $this->assertEmpty(
            $missing,
            sprintf(
                "Filter field '%s' description references values not in the index: %s\nActual values: %s",
                $field,
                implode(', ', $missing),
                implode(', ', $actualValues),
            ),
        );
    }

    /**
     * @dataProvider validDescriptionProvider
     */
    public function testIndexValuesAppearInDescription(
        string $field,
        string $description,
        array $actualValues,
    ): void {
        $described = $this->extractEnumeratedValues($description);
        if (empty($described)) {
            $this->markTestSkipped("No enumerated values in description for '{$field}'");
        }

        $undocumented = array_diff($actualValues, $described);
        $this->assertEmpty(
            $undocumented,
            sprintf(
                "Filter field '%s' has index values not mentioned in description: %s\nDescribed values: %s",
                $field,
                implode(', ', $undocumented),
                implode(', ', $described),
            ),
        );
    }

    public static function validDescriptionProvider(): iterable
    {
        // Wikipedia / Athenaeum topics taxonomy terms.
        yield 'topics' => [
            'topics',
            'Subject area or domain. Valid values: Arts, Biography, Engineering, Geography, History, Mathematics, Medicine, Military, Nature, Philosophy, Religion, Science, Society, Sports, Technology',
            ['Arts', 'Biography', 'Engineering', 'Geography', 'History', 'Mathematics', 'Medicine', 'Military', 'Nature', 'Philosophy', 'Religion', 'Science', 'Society', 'Sports', 'Technology'],
        ];

        yield 'era' => [
            'era',
            'Historical period. Values: "Ancient (before 500 CE)", "Medieval (500-1500)", "Early Modern (1500-1800)", "Modern (1800-1945)", "Contemporary (1945-present)", "Timeless"',
            ['Ancient (before 500 CE)', 'Medieval (500-1500)', 'Early Modern (1500-1800)', 'Modern (1800-1945)', 'Contemporary (1945-present)', 'Timeless'],
        ];

        yield 'region' => [
            'region',
            'Geographic region. Values: Africa, Americas, Antarctica, Asia, Europe, "Global / Multiple Regions", "Not Geographic", Oceania, Space',
            ['Africa', 'Americas', 'Antarctica', 'Asia', 'Europe', 'Global / Multiple Regions', 'Not Geographic', 'Oceania', 'Space'],
        ];
    }

    public function testDetectsInventedValues(): void
    {
        $description = 'Subject area (Arts, Biology, Chemistry, Physics, etc.)';
        // This free-form format with "etc." won't match our strict extraction.
        // That's intentional — descriptions should use "Valid values:" for
        // exhaustive lists to make the constraint machine-parseable.
        $values = $this->extractEnumeratedValues($description);
        $this->assertEmpty($values, 'Parenthesized lists with "etc." should not be treated as exhaustive enumerations');
    }
}
