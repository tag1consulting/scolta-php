<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Prompt;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * Prompt-text identity gate.
 *
 * The AI prompt templates (expand_query, summarize, follow_up) exist as three
 * hand-maintained copies of the same long text:
 *   - scolta-core/src/prompts.rs       — the Rust source for the serverless/WASM path
 *   - scolta-php DefaultPrompts.php     — this package's copy for the CMS/PHP path
 *   - scolta-python prompts.py          — a third copy (tested separately)
 *
 * Each copy claims to share the same base text, but nothing enforced it. If a
 * prompt's shared base text is edited in one place and not the other, the copies
 * silently diverge and every other test stays green — so the PHP-resolved system
 * prompt would differ from the WASM-resolved one. This test fails loudly on that.
 *
 * --- The contract this gate enforces -------------------------------------------
 *
 * The two copies share the SAME base text, modulo two documented, intentional
 * differences that are normalized out before comparison:
 *
 *  1. The `{DYNAMIC_ANCHORS}` placeholder line. Per-site prompt instructions are
 *     injected by DIFFERENT, path-specific mechanisms on each side:
 *       - WASM/serverless path: scolta-core's resolve_template() fills the
 *         `{DYNAMIC_ANCHORS}` token in SUMMARIZE/FOLLOW_UP (shipped deliberately
 *         in scolta-core PR #2, with tests). The token therefore exists ONLY in
 *         the Rust copy.
 *       - CMS/PHP path: per-site instructions are injected via
 *         PromptEnricherInterface::enrich() host hooks plus the `prompt_*` full-
 *         override config fields. DefaultPrompts::resolve() handling only
 *         {SITE_NAME}/{SITE_DESCRIPTION} is correct — anchors are NOT dropped
 *         server-side, they arrive through the enricher/override mechanism.
 *     So a lone `{DYNAMIC_ANCHORS}` line in the Rust text is expected and is
 *     normalized away here. Do NOT "fix" this asymmetry by editing either
 *     template — the two injection mechanisms are by design.
 *
 *  2. PHP single-quote escaping. The PHP templates are single-quoted literals, so
 *     `'` appears as `\'` in source — but getTemplate() returns the runtime
 *     string where it is already a plain `'`, matching Rust's raw-string `'`.
 *     No un-escaping is needed here; we compare the resolved runtime strings.
 *
 * Everything else must be byte-for-byte identical.
 *
 * scolta-core is NOT a Composer dependency of scolta-php, so its source is absent
 * from a published-package checkout. When the file is missing the test skips
 * gracefully; scolta-php's own CI checks out the sibling repo, so the gate runs
 * there.
 */
class PromptTextIdentityTest extends TestCase
{
    /**
     * Map of PHP template name => Rust const name in scolta-core/src/prompts.rs.
     */
    private const TEMPLATE_TO_CONST = [
        'expand_query' => 'EXPAND_QUERY',
        'summarize'    => 'SUMMARIZE',
        'follow_up'    => 'FOLLOW_UP',
    ];

    /**
     * Resolve the canonical Rust source relative to this test file.
     *
     * tests/Prompt/ -> tests/ -> scolta-php/ -> packages/ -> packages/scolta-core/...
     */
    private static function corePromptsPath(): string
    {
        return __DIR__ . '/../../../scolta-core/src/prompts.rs';
    }

    /**
     * @dataProvider templateProvider
     */
    public function testSharedBaseTextMatchesScoltaCore(string $phpName, string $rustConst): void
    {
        $path = self::corePromptsPath();
        if (!is_file($path)) {
            $this->markTestSkipped('scolta-core source not checked out (' . $path . ')');
        }

        $source = file_get_contents($path);
        $this->assertNotFalse($source, 'scolta-core/src/prompts.rs must be readable');

        // Rust base text = the raw const body with the path-specific
        // {DYNAMIC_ANCHORS} injection line normalized out (see class docblock).
        $coreBase = self::stripDynamicAnchorsLine(self::extractRustRawConst($source, $rustConst));
        $php = DefaultPrompts::getTemplate($phpName);

        $this->assertSame(
            $coreBase,
            $php,
            self::diffMessage($phpName, $rustConst, $coreBase, $php)
        );
    }

    public static function templateProvider(): array
    {
        $cases = [];
        foreach (self::TEMPLATE_TO_CONST as $phpName => $rustConst) {
            $cases[$phpName] = [$phpName, $rustConst];
        }

        return $cases;
    }

    /**
     * Remove any line consisting solely of `{DYNAMIC_ANCHORS}` (and its trailing
     * newline) from the Rust-side text.
     *
     * This is the WASM-path injection token; it is intentionally absent from the
     * PHP copy (see class docblock). Nothing else is normalized — the rest of the
     * comparison stays byte-exact.
     */
    private static function stripDynamicAnchorsLine(string $text): string
    {
        return preg_replace('/^\{DYNAMIC_ANCHORS\}\n/m', '', $text);
    }

    /**
     * Extract the verbatim body of a Rust raw-string constant from prompts.rs.
     *
     * The constants use Rust raw strings, which contain NO escape sequences — the
     * body is the literal text between the opening and closing delimiters. Two
     * raw-string forms appear in the source and the delimiter is detected
     * per-constant rather than assumed:
     *   - EXPAND_QUERY and SUMMARIZE use r#" ... "#
     *   - FOLLOW_UP uses r##" ... "## because its body contains the literal
     *     sequence `"#` (e.g. "#3"), which would prematurely close an r#" string.
     *
     * We locate `pub const NAME: &str =`, read the run of `#` after the opening
     * `r` to learn the hash count N, then take everything up to the first
     * closing `"` followed by exactly N `#`. Nothing is normalized or stripped
     * here — the point of the gate is exact byte identity.
     */
    private static function extractRustRawConst(string $source, string $constName): string
    {
        $declPos = strpos($source, 'pub const ' . $constName . ':');
        if ($declPos === false) {
            self::fail("Could not find `pub const {$constName}:` in scolta-core/src/prompts.rs");
        }

        // Find the `r` that opens the raw string, after the `=`.
        $eqPos = strpos($source, '=', $declPos);
        if ($eqPos === false) {
            self::fail("Malformed const {$constName}: no `=` after declaration");
        }

        // Match: r, one-or-more #, then ". Capture the hash run so we know N.
        if (!preg_match('/r(#+)"/', $source, $m, PREG_OFFSET_CAPTURE, $eqPos)) {
            self::fail("Could not find raw-string opener (r#\"/r##\") for const {$constName}");
        }

        $hashes = $m[1][0];                 // e.g. "#" or "##"
        $openEnd = $m[0][1] + strlen($m[0][0]); // byte offset just past the opening r#"…"
        $closer = '"' . $hashes;            // matching closer: "# or "##

        $closePos = strpos($source, $closer, $openEnd);
        if ($closePos === false) {
            self::fail("Could not find raw-string closer `{$closer}` for const {$constName}");
        }

        return substr($source, $openEnd, $closePos - $openEnd);
    }

    /**
     * Build an actionable failure message: name the divergent template and show
     * the first differing byte offset with a short window around it on both sides.
     *
     * The message does NOT assert which side is "wrong" — DefaultPrompts.php is
     * runtime-authoritative for all CMS adapters and demos, and the resolution
     * direction (align PHP to core or core to PHP) is a per-case decision for a
     * human reviewer, not something this gate prejudges.
     */
    private static function diffMessage(string $phpName, string $rustConst, string $coreBase, string $php): string
    {
        $len = min(strlen($coreBase), strlen($php));
        $offset = 0;
        while ($offset < $len && $coreBase[$offset] === $php[$offset]) {
            $offset++;
        }

        $window = 60;
        $start = max(0, $offset - $window);
        $coreWin = substr($coreBase, $start, $window * 2);
        $phpWin = substr($php, $start, $window * 2);

        return sprintf(
            "Shared base text for template '%s' diverged between scolta-php and "
            . 'scolta-core const %s (after normalizing the {DYNAMIC_ANCHORS} '
            . "injection line and PHP quote escaping).\n"
            . "  scolta-php base length:  %d bytes\n"
            . "  scolta-core base length: %d bytes\n"
            . "  first difference at byte offset %d\n"
            . "  scolta-core [offset %d ±%d]: %s\n"
            . "  scolta-php  [offset %d ±%d]: %s\n"
            . 'Reconcile the two copies; the resolution direction is a reviewer decision.',
            $phpName,
            $rustConst,
            strlen($php),
            strlen($coreBase),
            $offset,
            $start,
            $window,
            var_export($coreWin, true),
            $start,
            $window,
            var_export($phpWin, true)
        );
    }
}
