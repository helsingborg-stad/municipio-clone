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

## How cloning works

`wp municipio clone` copies a **sanitized database snapshot** from the source site into the WordPress installation where the command is run. It does not copy uploads, themes, plugins, or other filesystem content.

1. The command verifies that the target environment is safe, then resolves the target URL. On multisite installations, it creates the target subsite when needed; importing into an existing subsite requires confirmation.
2. It authenticates against the source REST API with the supplied Application Password. The source checks that the user has the `municipio_clone_export` capability.
3. The source generates a SQL export, applies the configured washer rules to mask supported personal and form data, and replaces source URLs with the neutral placeholder URL. The encrypted artifact is cached until its TTL expires. `--force` requests a new export, subject to the forced-regeneration window.
4. The target downloads the artifact, remaps its database table prefixes to the target site, and imports the SQL.
5. The command replaces the placeholder URL with `--target` in the imported tables, then normalizes the target site's `home` and `siteurl` options.

## Testing

```bash
composer dump-autoload
phpunit --configuration phpunit.xml
```
