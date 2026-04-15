# Scolta Hosting Guide

## How Scolta Stores Data

Scolta produces a static search index (Pagefind format) stored in your
web-accessible directory. The index is a set of small binary fragment files
plus a JavaScript loader. Total size scales with content: roughly 1 MB per
1,000 pages.

Build state (progress tracking, chunk manifests) is stored in a non-public
state directory and is safe to delete after a successful build.

## Indexer Options

Scolta supports two indexers:

- **binary** (default): Runs the Pagefind binary. Fast, native performance.
  Requires `exec()` — unavailable on some managed hosts.
- **php**: Pure PHP indexer. No binary dependencies. Works everywhere PHP runs.
  Set `indexer: php` in config (or `SCOLTA_INDEXER=php` in `.env`).

**Auto-detection** (`indexer: auto`) tries the binary first, falls back to PHP.

For faster indexing and 33+ language support, use the binary indexer when
`exec()` is available. The PHP indexer supports 14 languages (Snowball stemming)
and has no external dependencies.

## Platform-Specific Notes

### WordPress — Managed Hosts

| Host | exec() | Recommended Indexer | Notes |
|------|--------|---------------------|-------|
| WP Engine | No | php | Ephemeral filesystem resets on deploy; rebuild via Action Scheduler |
| Kinsta | No | php | |
| Flywheel | No | php | |
| Pressable | No | php | |
| WordPress.com Business | No | php | Requires Business plan for plugin installation |
| Self-hosted / VPS | Yes | binary | Best performance |

### Drupal — Managed Hosts

| Host | exec() | Recommended Indexer | Notes |
|------|--------|---------------------|-------|
| Pantheon | Limited | php | Use PHP indexer; filesystem is ephemeral outside `/files` |
| Acquia | Yes | binary | Configure `output_dir` under `/files` for persistence |
| Platform.sh | Yes | binary | Mount output directory as a persistent disk |

### Laravel — Cloud Hosts

| Host | exec() | Recommended Indexer | Notes |
|------|--------|---------------------|-------|
| Vapor (serverless) | No | php | Use S3 StorageDriver for state + index persistence |
| Forge | Yes | binary | Standard VPS, no restrictions |
| Ploi | Yes | binary | |

## Ephemeral Filesystems

Hosts like WP Engine, Pantheon, and Vapor reset the filesystem on deploy.
The search index must be rebuilt after each deploy. Options:

1. **Auto-rebuild on deploy** — trigger a build via deploy hook or post-deploy
   cron. WordPress: Action Scheduler. Drupal: `drush scolta:build`.
   Laravel: `artisan scolta:build` in a deploy step.

2. **Persistent storage** — configure `output_dir` to a persistent
   mount or cloud bucket (see StorageDriver below).

3. **CI build** — generate the index during CI and include it in the
   deployment artifact. The index is static files; committing them is fine
   for small sites.

## StorageDriver for Cloud Persistence

For Vapor or other serverless environments, implement a custom `StorageDriver`
that writes to S3 or GCS instead of the local filesystem. Scolta's
`StorageDriverInterface` supports this pattern:

- `FilesystemDriver` (default) — local disk, works everywhere.
- Custom: implement `Tag1\Scolta\Storage\StorageDriverInterface` with
  your cloud SDK and bind it in the service container.

```php
// Example: bind a custom driver in AppServiceProvider::register()
$this->app->bind(StorageDriverInterface::class, S3StorageDriver::class);
```

## Cron / Scheduled Rebuilds

All platforms support scheduled rebuilds. If auto-rebuild on content change
is insufficient (for example, content imported via external ETL), schedule a
periodic full rebuild:

- **WordPress**: Action Scheduler (bundled with WooCommerce, or standalone).
  Register a recurring action: `as_schedule_recurring_action(time(), 86400, 'scolta_rebuild')`.

- **Drupal**: Queue Worker via cron or Drush.
  ```
  drush scolta:build
  ```
  Add to your crontab or platform cron configuration.

- **Laravel**: Laravel Scheduler.
  ```php
  // In App\Console\Kernel::schedule()
  $schedule->command('scolta:build')->daily();
  ```

## Index Size Reference

| Content size | Index size (approx) | Build time (PHP indexer) |
|-------------|---------------------|--------------------------|
| 100 pages | ~1 MB | < 1 s |
| 1,000 pages | ~10 MB | ~10 s |
| 10,000 pages | ~80 MB | ~90 s |
| 50,000 pages | ~350 MB | ~7 min |

The binary (Pagefind CLI) indexer is approximately 10× faster.

Fragment files are individually small (< 50 KB each) and served on demand
by the browser — visitors only download the fragments needed for their query.
