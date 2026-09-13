# Scolta Hosting Guide

## How Scolta Stores Data

Scolta produces a static search index (Pagefind format) stored in your
web-accessible directory. The index is a set of small binary fragment files
plus a JavaScript loader. Total size scales with content: roughly 1 MB per
1,000 pages.

Build state (progress tracking, chunk manifests) is stored in a non-public
state directory and is safe to delete after a successful build.

## The Indexer

Scolta's indexer is pure PHP. It has no binary dependencies, does not call
`exec()`, and runs everywhere PHP runs, including managed hosts. It supports
14 languages (Snowball stemming). The `indexer` config key is still accepted
but every value selects the PHP indexer.

## Platform-Specific Notes

### WordPress — Managed Hosts

| Host | exec() | Notes |
| ------ | -------- | ------- |
| WP Engine | No | Ephemeral filesystem resets on deploy; rebuild via Action Scheduler |
| Kinsta | No |  |
| Flywheel | No |  |
| Pressable | No |  |
| WordPress.com Business | No | Requires Business plan for plugin installation |
| Self-hosted / VPS | Yes |  |

### Drupal — Managed Hosts

| Host | exec() | Notes |
| ------ | -------- | ------- |
| Pantheon | Limited | Filesystem is ephemeral outside `/files` |
| Acquia | Yes | Configure `output_dir` under `/files` for persistence |
| Platform.sh | Yes | Mount output directory as a persistent disk |

### Laravel — Cloud Hosts

| Host | exec() | Notes |
| ------ | -------- | ------- |
| Vapor (serverless) | No | Use S3 StorageDriver for state + index persistence |
| Laravel Cloud | Yes | Filesystem resets on deploy; persist the index in a Laravel Cloud [object storage bucket](https://laravel.com/cloud/docs/resources/object-storage) (S3-compatible) via an S3 StorageDriver |
| Forge | Yes | Standard VPS, no restrictions |
| Ploi | Yes |  |

## Ephemeral Filesystems

Hosts like WP Engine, Pantheon, Vapor, and Laravel Cloud reset the filesystem on deploy.
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

For Vapor, Laravel Cloud, or other ephemeral environments, implement a custom `StorageDriver`
that writes to S3 or GCS instead of the local filesystem. Scolta's
`StorageDriverInterface` supports this pattern:

- `FilesystemDriver` (default) — local disk, works everywhere.
- Custom: implement `Tag1\Scolta\Storage\StorageDriverInterface` with
  your cloud SDK and bind it in the service container.
  On Laravel Cloud, attach an [object storage bucket](https://laravel.com/cloud/docs/resources/object-storage)
  to the environment; it is S3-compatible and injects the `AWS_*` credentials
  that a Flysystem S3 disk picks up automatically.

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


Fragment files are individually small (< 50 KB each) and served on demand
by the browser — visitors only download the fragments needed for their query.
