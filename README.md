# Scolta PHP

<!-- TODO: Add CI badge once repo is on GitHub -->
<!-- [![CI](https://github.com/tag1consulting/scolta-php/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-php/actions/workflows/ci.yml) -->

PHP language binding for the Scolta search engine. Bridges platform adapters (Drupal, WordPress, Laravel) to the scolta-core WebAssembly module via the Extism PHP SDK.

## What This Package Provides

scolta-core contains the algorithms (scoring, HTML processing, prompt templates) compiled to WebAssembly. This package wraps that WASM module in PHP classes and adds:

- **ScoltaWasm** - PHP bridge to all scolta-core WASM functions via FFI/Extism
- **ScoltaConfig** - Platform-agnostic configuration with scoring defaults
- **AiClient** - Provider-agnostic HTTP client for Anthropic and OpenAI APIs
- **ContentExporter** - Exports content items to Pagefind-compatible HTML files
- **ContentSourceInterface** - Contract that platform adapters implement for content enumeration
- **DefaultPrompts** - Prompt template loading and variable resolution
- **Shared assets** - `scolta.js` (search UI) and `scolta.css` (search styles) used by all platforms

Platform adapters depend on this package, never on scolta-core directly.

## Installation

```bash
composer require tag1/scolta-php
```

### Requirements

- PHP 8.1+
- [Extism](https://extism.org) shared library (`libextism.so` / `libextism.dylib`)
- PHP FFI extension enabled (`ffi.enable=true` in php.ini)

### Installing Extism

```bash
curl -s https://get.extism.org/cli | bash -s -- -y
sudo extism lib install --version latest
sudo ldconfig  # Linux only
```

## Architecture

```
Platform Adapters          scolta-php              scolta-core
(Drupal/WP/Laravel)        (this package)          (WASM)
                                                   
  ScoltaBackend ──────> ContentExporter ──────> cleanHtml()
  ScoltaAiService ────> AiClient                buildPagefindHtml()
  SettingsForm ───────> ScoltaConfig ─────────> toJsScoringConfig()
  SearchBlock ────────> DefaultPrompts ───────> resolvePrompt()
                                                scoreResults()
                                                mergeResults()
```

Platform adapters handle CMS-specific concerns (routing, permissions, templates). This package handles the PHP-to-WASM bridge and shared logic. Algorithms live in WASM.

## Usage

### Configuration

```php
use Tag1\Scolta\Config\ScoltaConfig;

// Create from a flat associative array (e.g., from wp_options, Drupal config, Laravel config).
$config = ScoltaConfig::fromArray([
    'ai_provider' => 'anthropic',
    'ai_api_key' => getenv('SCOLTA_API_KEY'),
    'ai_model' => 'claude-sonnet-4-5-20250929',
    'title_match_boost' => 1.0,
    'recency_half_life_days' => 365,
    'results_per_page' => 10,
]);

// Export scoring config for the JavaScript frontend.
// Returns SCREAMING_SNAKE_CASE keys matching window.scolta.scoring.
$jsConfig = $config->toJsScoringConfig();
// => ['TITLE_MATCH_BOOST' => 1.0, 'RECENCY_HALF_LIFE_DAYS' => 365, ...]

// Get config for the AI client.
$clientConfig = $config->toAiClientConfig();
// => ['provider' => 'anthropic', 'api_key' => '...', 'model' => '...']
```

### AI Client

```php
use Tag1\Scolta\AiClient;

$client = new AiClient([
    'provider' => 'anthropic',  // or 'openai'
    'api_key' => getenv('SCOLTA_API_KEY'),
    'model' => 'claude-sonnet-4-5-20250929',
]);

// Single-turn message.
$response = $client->message(
    systemPrompt: 'You expand search queries.',
    userMessage: 'Expand: product pricing',
    maxTokens: 512,
);

// Multi-turn conversation.
$response = $client->conversation(
    systemPrompt: 'You are a search assistant.',
    messages: [
        ['role' => 'user', 'content' => 'What is Docker?'],
        ['role' => 'assistant', 'content' => 'Docker is a containerization platform...'],
        ['role' => 'user', 'content' => 'How does it compare to VMs?'],
    ],
    maxTokens: 512,
);
```

### Content Export

```php
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;

$exporter = new ContentExporter('/path/to/build/dir');
$exporter->prepareOutputDir();

$item = new ContentItem(
    id: 'post-42',
    title: 'Getting Started with Docker',
    bodyHtml: '<p>Docker has transformed how developers...</p>',
    url: '/blog/getting-started-docker',
    date: '2026-04-01',
    siteName: 'My Site',
);

$exporter->export($item);
// Writes /path/to/build/dir/post-42.html with Pagefind data attributes.
```

### Prompt Templates

```php
use Tag1\Scolta\Prompt\DefaultPrompts;

// Get the raw template with placeholders.
$template = DefaultPrompts::getTemplate('expand_query');
// => "You expand search queries for {SITE_NAME} {SITE_DESCRIPTION}..."

// Resolve a template with actual values.
$prompt = DefaultPrompts::resolve('expand_query', 'Acme Corp', 'corporate website');
// => "You expand search queries for Acme Corp corporate website..."

// Available templates: 'expand_query', 'summarize', 'follow_up'
```

### WASM Bridge (Advanced)

```php
use Tag1\Scolta\Wasm\ScoltaWasm;

// All scolta-core functions are available as static methods.
$cleaned = ScoltaWasm::cleanHtml('<html>...full page...</html>', 'Page Title');
$scored = ScoltaWasm::scoreResults($results, $scoringConfig, 'search query');
$merged = ScoltaWasm::mergeResults($original, $expanded, primaryWeight: 0.7);
$terms = ScoltaWasm::parseExpansion('["term1", "term2"]');
$version = ScoltaWasm::version();

// Custom WASM binary path (default: wasm/scolta_core.wasm relative to this package).
ScoltaWasm::setWasmPath('/custom/path/scolta_core.wasm');

// Debug mode logs all WASM calls with timing.
ScoltaWasm::enableDebug();
// ... do work ...
$log = ScoltaWasm::getDebugLog();
ScoltaWasm::disableDebug();
```

### Content Source Interface

Platform adapters implement this to enumerate content for indexing:

```php
use Tag1\Scolta\Content\ContentSourceInterface;
use Tag1\Scolta\Export\ContentItem;

class MyContentSource implements ContentSourceInterface
{
    public function getPublishedContent(array $options = []): iterable { /* ... */ }
    public function getChangedContent(): iterable { /* ... */ }
    public function getDeletedIds(): array { /* ... */ }
    public function clearTracker(): void { /* ... */ }
    public function getTotalCount(array $options = []): int { /* ... */ }
    public function getPendingCount(): int { /* ... */ }
}
```

## Shared Frontend Assets

The `assets/` directory contains the JavaScript and CSS shared by all platform adapters:

- `assets/js/scolta.js` - Client-side search UI (Pagefind integration, scoring, AI features)
- `assets/css/scolta.css` - Search UI styles with CSS custom properties for theming

Platform adapters reference these files via symlinks (Drupal), `wp_enqueue_*` (WordPress), or `vendor:publish` (Laravel).

## Module Structure

```
src/
  AiClient.php                    # HTTP client for Anthropic/OpenAI APIs
  Config/ScoltaConfig.php         # Platform-agnostic configuration
  Content/ContentSourceInterface.php  # Contract for content enumeration
  Content/TrackerRecord.php       # DTO for change tracking records
  Export/ContentExporter.php      # Exports content to Pagefind HTML
  Export/ContentItem.php          # DTO for a single content item
  Prompt/DefaultPrompts.php       # Prompt template loading and resolution
  Provider/AiProviderInterface.php    # AI provider contract
  Provider/AiResponse.php         # DTO for AI responses
  Scorer/DefaultScorer.php        # Default scoring implementation
  Scorer/ScorerInterface.php      # Scorer contract
  Wasm/ScoltaWasm.php             # PHP-to-WASM bridge via Extism FFI
assets/
  js/scolta.js                    # Shared search UI JavaScript
  css/scolta.css                  # Shared search UI styles
wasm/
  scolta_core.wasm                # Pre-built WASM binary
```

## Testing

```bash
composer install
./vendor/bin/phpunit
```

WASM integration tests are skipped when `libextism` is not installed. This is expected in CI environments without the native runtime.

## Dependencies

- **extism/extism** ^1.0 - WASM runtime via FFI
- **guzzlehttp/guzzle** ^7.0 - HTTP client for AI API calls

## License

GPL-2.0-or-later
