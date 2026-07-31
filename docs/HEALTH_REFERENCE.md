# Health payload reference

`Tag1\Scolta\Health\HealthChecker::check()` returns the structure every adapter
serves from its health endpoint, with platform-specific fields merged on top by
the adapter. This document is the reference for the AI fields, and in particular
for the auth-failure marker, which is the one field in the payload that reports
a cached observation rather than something measured at request time.

## AI fields

| Field | Type | Meaning |
| --- | --- | --- |
| `ai_provider` | string | The effective provider, from the key resolution when one was passed. |
| `ai_configured` | bool | Credentials are present. An Amazee install still awaiting model resolution counts as configured. |
| `ai_usable` | bool | Configured **and** not known to be auth-failing **and** not awaiting Amazee model resolution. |
| `ai_auth_failing` | bool | The last recorded AI call failed authentication. A cached marker, not a live probe. |
| `ai_auth_failing_since` | int\|null | Unix timestamp of the failed call that recorded the marker. `null` when the marker is not set. |
| `ai_auth_failing_ttl` | int\|null | Seconds the marker survives without a further failing call (3600). `null` when the marker is not set. |
| `ai_key_source` | string\|null | Backing value of the resolved `ApiKeySource`, or `null` when the adapter passed no resolution. See [API_KEY_PRECEDENCE.md](API_KEY_PRECEDENCE.md). |
| `ai_amazee_overridden` | bool | Amazee.ai credentials are stored but lost to an explicit key. |

`ai_usable: false` drives `status: degraded`, as does a missing index or a stale
index artifact.

## The auth-failure marker

`ai_auth_failing` reports a cache marker that `KeyExpiryRecovery` writes when an
AI call is rejected for authentication. Health never makes a live API call, so
this marker is how a health request can know anything at all about whether the
stored credentials still work. The cost of that design is that the marker can
outlive the condition it describes, which is why it is reported with its age.

**Reading it.** `ai_auth_failing: true` means *an AI call failed authentication
at `ai_auth_failing_since`, and no AI call has succeeded since*. It does not
mean the credentials are bad right now. A site that has been fixed but has not
served a search since the fix will still report the marker until something calls
the AI path.

`ai_auth_failing_since` is what separates the two readings. A marker recorded
seconds ago on a site under traffic is a live failure. A marker recorded fifty
minutes ago on a site under traffic is not: something would have re-recorded it.

`ai_auth_failing_ttl` is the window the marker survives on its own. Under the
default 3600s a marker whose `since` is older than an hour cannot exist, so any
marker you see was recorded within the last hour.

### What sets it

Only authentication rejections of the stored credentials:
`ApiKeyInvalidException` (any HTTP 401), or one of `expired_key`,
`invalid_api_key`, `authentication error`, `invalid proxy server token` found
anywhere in the exception chain.

Deliberately **not** set by:

- **Budget exhaustion**, which routes to `BudgetAwareProviderDecorator`.
- **A provider/model mismatch**, which routes to the `ai_model_provider_mismatch`
  degraded reason on the AI response (`AiEndpointHandler::REASON_MODEL_MISMATCH`)
  and names both the model and the provider in the log. This exclusion is by
  exception type and is checked before the message chain is walked: a
  `ModelProviderMismatchException` carries the provider's original 4xx as its
  previous exception, and a gateway that words an unknown-model rejection as an
  authentication error would otherwise match on the marker list and report a
  credential problem for a configuration fault.
- **Transport failures and other provider rejections**, which degrade to the
  generic `ai_error` reason on the AI response.

### What clears it

In order of what an operator should reach for:

1. **A successful AI call.** The first AI call the provider accepts clears the
   marker, via `KeyExpiryRecovery::noteCallSucceeded()`, wired into every call
   path in `AiServiceAdapter`. This is the normal path: fix the credentials, run
   one search, health reports `ok`. No operator action, no cache flush, no
   waiting.
2. **The TTL.** The marker expires 3600 seconds after the failing call that
   recorded it, whether or not anything succeeds in the meantime.
3. **`KeyExpiryRecovery::clearAuthFailure()`**, for a site that cannot make a
   successful call at all and should not be reported as auth-failing while an
   operator works on it. It clears only this marker.

There is no dedicated scolta command for step 3, because the marker lives in
whatever cache the adapter passed to `KeyExpiryRecovery` and clearing a cache
entry is the platform's job. `scolta:clear-cache` (Drupal) and its equivalents
do **not** touch it: they bump the AI response generation and delete resolved
prompts. Per platform:

```bash
# Drupal — a cache.default entry under the bare key
drush php:eval '\Drupal::cache()->delete("scolta_amazee_auth_failure");'
drush cache:rebuild   # blunter: flushes the bin the marker sits in, along with everything else

# WordPress — a transient under the bare key
wp transient delete scolta_amazee_auth_failure

# Laravel — the default cache store, under the bare key
php artisan cache:forget scolta_amazee_auth_failure
```

The cache key is `KeyExpiryRecovery::CACHE_KEY_AUTH_FAILURE`, whose value is
`scolta_amazee_auth_failure`. All three shipped cache drivers pass the key
through unprefixed. An adapter that prefixes its keys must document its own
form; a wrapper command on the adapter side is the better answer where one
exists.

### What clearing it does not do

Clearing the auth-failure marker does not clear
`KeyExpiryRecovery::CACHE_KEY_UPGRADE_NEEDED`. That marker means the stored
credentials were rejected and an admin still has to re-authenticate through the
email verification flow; it is cleared only by `clearUpgradeNeeded()`, once they
have. A successful AI call after an operator supplies a working key clears the
first and leaves the second standing, which is correct: the site is serving AI
again, and the Amazee credentials it was provisioned with are still dead.

## History

`ai_auth_failing`, `ai_usable` and the `degraded` status arrived with Amazee
trial-key expiry detection: an expired key had kept `ai_configured: true` for
~24h while every call failed (django demo, 2026-06-09).

The marker was written and never cleared. `recordAuthFailure()` promised health
would report AI unusable "until calls succeed again or the marker ages out", but
only the ageing half existed, and the timestamp it stored was cast to bool by
both readers and thrown away. On the Athenaeum Drupal demo that produced
`ai_configured: true`, `ai_usable: false`, `ai_auth_failing: true` on a site
whose real fault was a model name the effective provider did not recognise: the
marker was stale, and it had been set by something that was not an
authentication failure in the first place.
