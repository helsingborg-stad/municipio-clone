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

- `MUNICIPIO_CLONE_CACHE_TTL`
- `MUNICIPIO_CLONE_FORCE_WINDOW`
- `MUNICIPIO_CLONE_STORAGE_PATH`
- `MUNICIPIO_CLONE_ENCRYPTION_KEY`
- `MUNICIPIO_CLONE_PLACEHOLDER_URL`

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
