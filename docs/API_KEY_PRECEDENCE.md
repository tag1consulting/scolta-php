# AI API key precedence

Scolta can get its AI API key from several places. This document defines the
one order they are consulted in, and the one place that order is implemented.

Implementation: `Tag1\Scolta\Config\ApiKeyResolver::resolve()`.

## The canonical order

Highest priority first:

1. **Explicit keys**, in the order the platform supplies them.
   Every adapter puts the `SCOLTA_API_KEY` environment variable first. The
   rest is platform shape:

   | Platform | Order |
   |---|---|
   | Drupal | `SCOLTA_API_KEY` env var, then `$settings['scolta.api_key']` in settings.php |
   | WordPress | `SCOLTA_API_KEY` env var (including `$_ENV` / `$_SERVER`), then the `SCOLTA_API_KEY` constant in wp-config.php, then the legacy `scolta_settings['ai_api_key']` database option |
   | Laravel | `SCOLTA_API_KEY` env var, then the published `config/scolta.php` value |

2. **Stored Amazee.ai credentials**, when Amazee is eligible for the
   configured provider. Drupal's `drupal_ai` provider is not eligible: the AI
   module manages its own provider, key and model.

3. **Nothing.** AI features degrade; search itself is unaffected.

An explicit key beating stored Amazee credentials is deliberate. A site that
configured its own provider must never be silently rerouted through the
managed gateway.

## Sources

`Tag1\Scolta\Config\ApiKeySource` is the closed set of answers:

| Source | Meaning |
|---|---|
| `env` | The `SCOLTA_API_KEY` environment variable |
| `settings` | A platform settings file |
| `constant` | A PHP constant |
| `database` | A value persisted in the site database (legacy) |
| `amazee` | Stored Amazee.ai credentials |
| `none` | No key anywhere |

Amazee is a single source, and no surface says how the connection was
obtained. There were briefly two cases, `amazee:operator` and `amazee:auto`,
splitting a licensed connection from an auto-provisioned free trial. Nothing
records that: `AmazeeTrialProvisioner` and `AmazeeAccountUpgrader` both persist
the same three fields through `ConfigStorageInterface::store()`, so the store
cannot tell you which one ran. Each adapter substituted a local fact and got
pinned to one case regardless of the truth — Drupal always reported
`amazee:operator`, WordPress always `amazee:auto`, so WordPress announced every
deliberately connected account as a free trial.

Reporting a distinction that cannot be derived is worse than omitting it,
because the surface states it with the same confidence as a fact it knows. If
provenance is wanted later, the credential store has to record it first.

## Why the resolver returns the source

`ResolvedApiKey` carries the key, its source and the effective provider
together. This is not convenience packaging: the reason the resolver exists is
that these facts used to be derived twice.

Before this change, the effective-config path in scolta-drupal and scolta-wp
preferred an explicit key and fell through to Amazee, while a separate
`getApiKeySource()` / `get_api_key_source()` checked Amazee **first**. The two
had opposite precedence. A site with a valid `SCOLTA_API_KEY` and stored
Amazee credentials sent every request with its own key while the settings
form, the health payload and the CLI all reported Amazee, in success green.
That is a diagnostic surface lying in exactly the situation where somebody
reaches for it: on the Athenaeum Drupal demo the message was read as evidence
that the environment variable was missing, when a valid key had been present
in the container the whole time.

Returning the source alongside the key removes the second derivation. A caller
that receives both has nothing left to compute.

## Rules for adapters

- Resolve once. Pass the resulting `ResolvedApiKey` to the effective config,
  the settings/admin form, the health payload and the `status` / `check-setup`
  command.
- Never ask the credential store whether Amazee is active. `isAmazeeActive()`
  and its equivalents derive from `ResolvedApiKey::isAmazee()`. Stored
  credentials that lost to an explicit key are not active.
- Report an override rather than hiding it. When credentials are stored but
  an explicit key won, `ResolvedApiKey::amazeeOverridden()` is TRUE, and the
  surface says so: *"Amazee.ai credentials stored but overridden by the
  SCOLTA_API_KEY environment variable"*.
- Do not render that state in success green. `ResolvedApiKey::severity()`
  returns `warning` for it, for an unconfigured key, and for a
  half-provisioned Amazee install.

## Half-provisioned Amazee installs

Credentials can be stored while model resolution has never succeeded. The
gateway rejects the shipped dated default model with HTTP 400, which breaks AI
permanently and silently, whereas a key-less client throws
`ApiKeyMissingException` and the endpoints degrade that to an unexpanded,
unsummarized HTTP 200 — the same path as an unconfigured site.

So for that state the resolver reports `amazee` as the source, sets
`awaitingAmazeeModelResolution`, and returns an **empty key**. The state
self-heals when provisioning next resolves a model.
