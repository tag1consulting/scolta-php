# Upgrading Pagefind

When Pagefind releases a new version, follow these steps to update Scolta's PHP indexer compatibility:

## 1. Regenerate reference fixtures

```bash
./scripts/generate-concordance-fixtures.sh X.Y.Z
```

This downloads the new Pagefind binary, builds the test corpus, and stores the output in `tests/fixtures/concordance/reference/`.

## 2. Run concordance tests

```bash
vendor/bin/phpunit tests/Concordance/
```

- If **fragment comparison** fails: the PHP indexer extracts content differently than Pagefind. Fix the content extraction.
- If **entry.json comparison** fails: version or page count mismatch. Update `SupportedVersions::BUNDLED_VERSION`.
- If **structural tests** fail: file format may have changed. Investigate Pagefind's release notes.

## 3. Update SupportedVersions

Edit `src/Index/SupportedVersions.php`:

```php
public const BUNDLED_VERSION = 'X.Y.Z';
public const TESTED_VERSIONS = ['1.3.0', '1.4.0', '1.5.0', 'X.Y.Z'];
```

## 4. Run full test suite

```bash
vendor/bin/phpunit
```

All tests must pass.

## 5. Commit everything

```bash
git add tests/fixtures/concordance/reference/ src/Index/SupportedVersions.php
git commit -m "Upgrade Pagefind compatibility to X.Y.Z"
```
