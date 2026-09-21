# municipio-clone

WordPress plugin that adds a sanitized export REST API and a `wp municipio clone` WP-CLI command for pulling masked database snapshots from another WordPress site.

## Features

- `wp municipio clone --url=<source> --target=<target> --api-key=<key> [--force]`
- REST export endpoint at `/wp-json/municipio-clone/v1/export`
- Per-user API key authentication with the `municipio_clone_export` capability
- Encrypted artifact cache with configurable TTL
- Built-in washer rules for comments, WooCommerce customer data, and common form-entry tables
- Serialization-aware source URL placeholder replacement

## Configuration

- `MUNICIPIO_CLONE_CACHE_TTL` - Cache lifetime for generated export artifacts in seconds. Defaults to `3600` (1 hour).
- `MUNICIPIO_CLONE_FORCE_WINDOW` - Minimum number of seconds between forced regenerations for the same source site. Defaults to `600` (10 minutes).
- `MUNICIPIO_CLONE_STORAGE_PATH` - Absolute directory path where encrypted export artifacts are stored. Defaults to a directory outside the web root when `ABSPATH` is available.
- `MUNICIPIO_CLONE_ENCRYPTION_KEY` - Encryption key used to protect cached export artifacts at rest. If not set, the plugin falls back to `AUTH_KEY`; one of these must be available.
- `MUNICIPIO_CLONE_PLACEHOLDER_URL` - Neutral placeholder URL written into exported data before import. Defaults to `https://municipio-clone-placeholder.invalid`.

## Clone usage

```bash
wp municipio clone \
  --url=https://source.example.se/site-a \
  --target=https://local.example.test/site-b \
  --api-key=your-personal-export-key \
  --force
```

## Testing

```bash
composer dump-autoload
phpunit --configuration phpunit.xml
```
