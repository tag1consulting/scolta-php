# Renderer parity fixtures

PHP `MarkdownRenderer::render()` and JS `formatSummary()`/`formatInline()`
(in `assets/js/scolta.js`) render the same AI output. They are not
byte-identical renderers — the JS side additionally supports headings and a
domain allowlist, and the PHP side entity-encodes quotes — but they share a
core contract: bold, italic, links, code-backtick passthrough, list and
paragraph structure, HTML escaping, and broken-markdown (truncated link)
repair.

Each `*.json` fixture here is asserted on BOTH sides — by
`tests/Util/RenderParityTest.php` (PHPUnit) and
`tests/js/render-parity.test.js` (Jest, driving the real `scolta.js` in
JSDOM) — so a change to either renderer that breaks the shared contract
fails that side's suite.

Fixture schema:

```json
{
    "input": "markdown string fed to both renderers",
    "mustContain": ["substring asserted present in both outputs"],
    "mustNotContain": ["substring asserted absent from both outputs"]
}
```

Keep fixture inputs free of double/single quotes in text content (the PHP
side entity-encodes them, the JS side does not) and of headings (JS-only
feature) — those are documented, deliberate differences, not drift.
