# Changelog

All notable changes to scolta-php will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased] (0.2.0-dev)

### Added

- `MarkdownRenderer` utility class (`Tag1\Scolta\Util\MarkdownRenderer`) for converting AI markdown responses to XSS-safe HTML (bold, links, bullet lists, paragraphs)
- `AiEndpointHandler::handleSummarize()` and `handleFollowUp()` now render AI markdown responses to HTML via `MarkdownRenderer` before returning results; all three platform adapters benefit automatically
- `aiLanguages` property on `ScoltaConfig` for multilingual AI response support (default: `['en']`)
- `AiEndpointHandler` accepts optional `aiLanguages` array; when multiple languages are configured, appends a language instruction to AI prompts so responses match the user's query language
- `toJsScoringConfig()` now includes `ai_languages` in the exported JS config
- `PromptEnricherInterface` and `NullEnricher` for site-specific prompt context injection between WASM resolution and LLM calls
- `AiEndpointHandler` now accepts an optional `PromptEnricherInterface` parameter (defaults to `NullEnricher`)
- `docs/ENRICHMENT.md` documenting the enrichment API with platform-specific examples

### Previously added

- `ScoltaWasm` bridge to all scolta-core WASM functions via Extism PHP SDK and FFI
- `ScoltaConfig` platform-agnostic configuration with `fromArray()`, `toJsScoringConfig()`, and `toAiClientConfig()` methods
- `AiClient` provider-agnostic HTTP client supporting Anthropic and OpenAI APIs with single-turn and multi-turn conversation modes
- `ContentExporter` for exporting content items to Pagefind-compatible HTML files
- `ContentSourceInterface` contract for platform adapters to implement content enumeration
- `DefaultPrompts` prompt template loading and variable resolution via WASM
- `PagefindBinary` cross-platform binary resolver with download support
- `SetupCheck` pre-flight dependency checker (PHP version, FFI, Extism, WASM, Pagefind, AI key)
- `ExtismCheck` runtime validation for the Extism PHP SDK and shared library
- `HealthChecker` health check aggregation for monitoring endpoints
- `AiEndpointHandler` shared request validation and response formatting for AI API endpoints
- `AiServiceAdapter` AI service wrapper used by platform adapters
- `CacheDriverInterface` contract for platform-specific cache implementations
- Shared frontend assets (`scolta.js`, `scolta.css`) used by all platform adapters
- Pre-built `scolta_core.wasm` binary shipped in the package
