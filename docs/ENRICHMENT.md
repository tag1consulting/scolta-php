# Prompt Enrichment

## What is prompt enrichment?

Prompt enrichment allows you to inject site-specific context into AI prompts after they have been resolved from WASM templates but before they are sent to the LLM provider. This is useful when your site has domain-specific knowledge that should inform AI responses, such as:

- Product catalogs and pricing
- Compliance and regulatory rules
- Brand voice guidelines
- Tenant-specific instructions in multi-tenant deployments
- Seasonal or time-sensitive context

## The interface

All enrichment is built on a single interface in scolta-php:

```php
namespace Tag1\Scolta\Prompt;

interface PromptEnricherInterface {
    public function enrich(
        string $resolvedPrompt,
        string $promptName,
        array $context = []
    ): string;
}
```

**Parameters:**

- `$resolvedPrompt` -- The prompt text after WASM template variable resolution.
- `$promptName` -- One of `'expand_query'`, `'summarize'`, or `'follow_up'`.
- `$context` -- An associative array with request-specific data:
  - For `expand_query`: `['query' => '...']`
  - For `summarize`: `['query' => '...', 'context' => '...']`
  - For `follow_up`: `['messages' => [...]]`

The default `NullEnricher` passes the prompt through unchanged.

## Platform-specific integration

### Drupal: Event subscribers

Scolta dispatches a `PromptEnrichEvent` via Symfony's event dispatcher. Subscribe to it in your module:

```php
// my_module/src/EventSubscriber/PromptEnrichSubscriber.php
namespace Drupal\my_module\EventSubscriber;

use Drupal\scolta\Event\PromptEnrichEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PromptEnrichSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      PromptEnrichEvent::class => 'onPromptEnrich',
    ];
  }

  public function onPromptEnrich(PromptEnrichEvent $event): void {
    if ($event->getPromptName() === 'summarize') {
      $prompt = $event->getResolvedPrompt();
      $prompt .= "\n\nAlways mention our 30-day return policy.";
      $event->setResolvedPrompt($prompt);
    }
  }

}
```

Register it as a service:

```yaml
# my_module.services.yml
services:
  my_module.prompt_enrich_subscriber:
    class: Drupal\my_module\EventSubscriber\PromptEnrichSubscriber
    tags:
      - { name: event_subscriber }
```

### WordPress: Filters

Use the `scolta_prompt` filter in your theme or plugin:

```php
add_filter('scolta_prompt', function (
    string $prompt,
    string $promptName,
    array $context
): string {
    if ($promptName === 'summarize') {
        $prompt .= "\n\nAlways mention our 30-day return policy.";
    }
    return $prompt;
}, 10, 3);
```

### Laravel: Event listeners

Listen for the `PromptEnrichEvent` in your application:

```php
// app/Listeners/EnrichScoltaPrompt.php
namespace App\Listeners;

use Tag1\ScoltaLaravel\Events\PromptEnrichEvent;

class EnrichScoltaPrompt
{
    public function handle(PromptEnrichEvent $event): void
    {
        if ($event->promptName === 'summarize') {
            $event->resolvedPrompt .= "\n\nAlways mention our 30-day return policy.";
        }
    }
}
```

Register it in `EventServiceProvider`:

```php
protected $listen = [
    \Tag1\ScoltaLaravel\Events\PromptEnrichEvent::class => [
        \App\Listeners\EnrichScoltaPrompt::class,
    ],
];
```

## Vertical examples

### Tech company (SaaS product search)

Inject current product tiers and pricing into summarization prompts so the AI references up-to-date plans:

```php
public function onPromptEnrich(PromptEnrichEvent $event): void {
    if ($event->getPromptName() !== 'summarize') {
        return;
    }

    $plans = $this->planRepository->getActivePlans();
    $planSummary = implode(', ', array_map(
        fn($p) => "{$p->name}: \${$p->price}/mo",
        $plans
    ));

    $event->setResolvedPrompt(
        $event->getResolvedPrompt()
        . "\n\nCurrent pricing plans: {$planSummary}."
        . "\nWhen discussing pricing, reference these plans by name."
    );
}
```

### Healthcare (compliance-aware search)

Append compliance disclaimers and restrict the AI from providing medical advice:

```php
public function onPromptEnrich(PromptEnrichEvent $event): void {
    $disclaimer = "\n\nIMPORTANT: You are a search assistant for a healthcare "
        . "organization. Never provide medical advice, diagnoses, or treatment "
        . "recommendations. Always direct users to consult their healthcare "
        . "provider. Include the disclaimer: 'This information is for "
        . "educational purposes only and is not a substitute for professional "
        . "medical advice.'";

    $event->setResolvedPrompt(
        $event->getResolvedPrompt() . $disclaimer
    );
}
```
