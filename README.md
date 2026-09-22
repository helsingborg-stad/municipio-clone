# municipio-clone

WordPress plugin that adds a sanitized export REST API and a `wp municipio clone` WP-CLI command for pulling masked database snapshots from another WordPress site.

## Features

- `wp municipio clone --source-url=<source> --target=<target> --username=<username> --application-password=<password> [--force]`
- REST export endpoint at `/wp-json/municipio-clone/v1/export`
- WordPress Application Password authentication with the `municipio_clone_export` capability
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

Use a WordPress Application Password belonging to a user with the `municipio_clone_export` capability. Create it under that user’s profile in the source site.

```bash
wp municipio clone \
  --source-url=https://source.example.se/site-a \
  --target=https://local.example.test/site-b \
  --username=thbr1001 \
  --application-password=<application-password> \
  --force
```

## Testing

```bash
composer dump-autoload
phpunit --configuration phpunit.xml
```

### End-to-end testing

Install the plugin dependencies once from the repository root:

```bash
composer install
```

Then run the Docker Compose smoke test:

```bash
./e2e/run.sh
```

This starts two local WordPress services:

- source site at `http://localhost:8081`
- target site at `http://localhost:8082`

The smoke test installs both sites, activates the plugin, creates source content, runs `wp municipio clone`, and verifies that the target site received the cloned content.
