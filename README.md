# municipio-clone

WordPress plugin that adds a sanitized export REST API and a `wp municipio clone` WP-CLI command for pulling masked database snapshots from another WordPress site.

## Features

- `wp municipio clone --source-url=<source> --target=<target> --username=<username> --application-password=<password> [--force] [--keep-remote-media-urls]`
- `wp municipio clone-batch --config=<path>` for synchronizing several configured source-to-target mappings
- Add `--target-siteurl=<installation-url>` when the WordPress installation URL differs from the public `--target` URL.
- REST export endpoint at `/wp-json/municipio-clone/v1/export`
- WordPress Application Password authentication with the `municipio_clone_export` capability
- Encrypted artifact cache with configurable TTL
- Built-in washer rules for comments, WooCommerce customer data, and common form-entry tables
- Serialization-aware source URL placeholder replacement

## Configuration

- `MUNICIPIO_CLONE_CACHE_TTL` - Cache lifetime for generated export artifacts in seconds. Defaults to `3600` (1 hour).
- `MUNICIPIO_CLONE_ARTIFACT_RETENTION` - Additional time in seconds to keep an artifact downloadable after its cache expires. This allows clones that already received its manifest to finish downloading while a newer export is generated. Defaults to `3600` (1 hour).
- `MUNICIPIO_CLONE_FORCE_WINDOW` - Minimum number of seconds between forced regenerations for the same source site. Defaults to `600` (10 minutes).
- `MUNICIPIO_CLONE_STORAGE_PATH` - Absolute directory path where encrypted export artifacts are stored. Defaults to a directory outside the web root when `ABSPATH` is available.
- `MUNICIPIO_CLONE_ENCRYPTION_KEY` - Encryption key used to protect cached export artifacts at rest. If not set, the plugin falls back to `AUTH_KEY`; one of these must be available.
- `MUNICIPIO_CLONE_PLACEHOLDER_URL` - Neutral placeholder URL written into exported data before import. Defaults to `https://municipio-clone-placeholder.invalid`.
- `MUNICIPIO_CLONE_TARGET_LOCK_TTL` - Maximum number of seconds a batch mapping may lock its target. Defaults to `3600`.

## Clone usage

Use a WordPress Application Password belonging to a user with the `municipio_clone_export` capability. Create it under that user’s profile in the source site.

```bash
wp municipio clone \
  --source-url=https://source.example.se/site-a \
  --target=https://local.example.test/site-b \
  --username=<username> \
  --application-password=<application-password> \
  --force
```

## How cloning works

`wp municipio clone` copies a **sanitized database snapshot** from the source site into the WordPress installation where the command is run. It does not copy uploads, themes, plugins, or other filesystem content.

1. The command verifies that the target environment is safe, then resolves the target URL. On multisite installations, it creates the target subsite when needed; importing into an existing subsite requires confirmation.
2. It authenticates against the source REST API with the supplied Application Password. The source checks that the user has the `municipio_clone_export` capability.
3. The source generates a SQL export, applies the configured washer rules to mask supported personal and form data, and replaces source URLs with the neutral placeholder URL, except in `guid` columns (which retain their original identifiers). It groups rows into size-limited multi-row `INSERT` statements to reduce the number of statements the target database executes. Each encrypted artifact has its own immutable ID. The source caches a pointer to the current artifact until its TTL expires, while keeping older artifacts available through their retention period so concurrent clones can finish downloading. `--force` requests a new export, subject to the forced-regeneration window.
4. The target downloads the artifact, remaps its database table prefixes, and imports the SQL. For an older artifact without the explicit GUID-preservation marker, it first restores source identifiers in `guid` columns (without changing other columns). New marked artifacts skip that extra file rewrite.
5. The command replaces the placeholder URL with `--target` in the imported tables, then stores and verifies the target site's `home` and `siteurl` options independently.
6. After a successful import, the command boots the target site in a fresh WP-CLI process and performs a soft rewrite flush. This refreshes pretty permalinks for the cloned site without rewriting web-server configuration. Batch cloning does this once per completed mapping. If flushing fails, the completed clone remains in place and WP-CLI reports a warning with the failure; rerun `wp rewrite flush --url=<target>` after fixing the cause.

Large clones report artifact bytes processed during table-prefix remapping and, for older unmarked artifacts, legacy GUID restoration. An interactive terminal shows progress bars; non-interactive runs (including batch logs) get progress messages no more often than every five seconds. Database import reports its stage start and periodic waiting messages, not a percentage of rows committed. The importer passes the existing SQL file to WP-CLI so its file-import optimizations (including disabling autocommit and key checks during import) apply. Batches contain at most 256 rows or approximately 1 MiB of SQL per statement, except for an individual row larger than that limit. Only a successful importer exit advances the clone to the next stage.
The importer inherits the current WP-CLI site's `--path` and `--url` and forwards `--allow-root` only when it was enabled for the parent command. Prefer running WP-CLI as the WordPress installation's user rather than as root.

`--target` is the public `home` URL. For an existing site, the pre-import stored installation URL's path relative to its stored `home` is preserved even when the target scheme, domain, port, or path changes. For a new site, `siteurl` defaults to `--target`; use `--target-siteurl=https://department.example.test/wp` if WordPress is installed under `/wp`. Supply the override when the existing URL relationship cannot be inferred safely. Filtered options or `WP_HOME` / `WP_SITEURL` settings that disagree with the requested URLs cause the import to fail rather than report success.

The immutable-artifact behavior is provided by the source site's plugin; existing clone clients remain compatible because the manifest and download URL contract is unchanged apart from an optional GUID-preservation marker.

An older source plugin may still export expanded placeholder URLs in `guid` values. The target restores these GUIDs before import when the marker is absent, including for a cached older artifact, without forcing a new export on the source. Update the source plugin to benefit from batched inserts and the skipped rewrite; force a new export if the cached artifact predates the marker. Existing imported data from a failed attempt should be treated as incomplete until the clone succeeds.

Pass `--keep-remote-media-urls` to retain source-site URLs for files under `wp-content/uploads`. On multisite, attachment URLs retain the source site's uploads path; other source URLs are replaced with `--target` except `guid` values, which retain their source identifiers.
The clone preserves the target site's stored `upload_path` and `upload_url_path` settings instead of importing the source's upload configuration. It also checks that their effective (possibly filtered) values remain unchanged after import. Ensure these target settings resolve to writable local storage and a URL served by the target. Imported attachments continue to use source media URLs, while attachments uploaded after the clone use the target's upload URL. On an existing clone with a production-valued `upload_url_path`, correct that option on the target site before uploading new files; changing the plugin alone does not repair previously generated attachment URLs.

If cloning fails, WP-CLI prints one error describing the failed stage and exits with a nonzero status instead of printing an exception stack trace. To inspect exception details and stack traces when troubleshooting, rerun the command with `--debug=municipio-clone`.

## Batch cloning

Use `wp municipio clone-batch` to keep several staging or local sites synchronized from separate production sources. The command processes mappings serially, running each mapping as a new `wp municipio clone` WP-CLI process. This isolates WordPress/PHP state between mappings and retains the single-clone rewrite flush. Credentials are passed to the child process through temporary environment variables rather than command-line arguments. After each successful clone, the batch runs `wp cache flush --url=<target>` in a fresh process; a cache-flush failure produces a warning without failing that mapping. It does not flush after an unsuccessful clone. The target URL selects the site, but some persistent object-cache backends flush a cache shared across the entire installation or network, not only the selected site.

Each failed mapping is reported once and does not stop remaining mappings; after all mappings have been attempted, the command prints a failure count and exits with a nonzero status. Use `--debug=municipio-clone` to see exception details and stack traces.

Store only URLs, flags, and credential references in the JSON configuration. Do not put usernames or application passwords in the file. The `username_env` and `application_password_env` values can name either a PHP constant or an environment variable. A defined PHP constant takes precedence; if it is not defined, the matching environment variable is read.

```json
{
  "mappings": [
    {
      "source_url": "https://production-one.example.se",
      "target": "https://staging.example.se/site-one",
      "target_siteurl": "https://staging.example.se/site-one/wp",
      "username_env": "SITE_ONE_CLONE_USERNAME",
      "application_password_env": "SITE_ONE_CLONE_APPLICATION_PASSWORD",
      "keep_remote_media_urls": true
    },
    {
      "source_url": "https://production-two.example.se",
      "target": "https://staging.example.se/site-two",
      "username_env": "SITE_TWO_CLONE_USERNAME",
      "application_password_env": "SITE_TWO_CLONE_APPLICATION_PASSWORD",
      "force": true
    }
  ]
}
```

Export the named variables in the process environment, then run the command from an external scheduler such as system cron, a CI job, or the deployment platform scheduler:

```bash
wp municipio clone-batch --config=/etc/municipio-clone/sites.json
```

The `--config` value may also be the name of a PHP constant containing the batch configuration as a JSON string. This lets you keep the configuration and credential constants in the WordPress bootstrap instead of using a JSON file.

Each target is protected by an atomic WordPress option lock for the duration of its import. A second batch attempting to synchronize the same target fails that mapping instead of importing concurrently. Maintain a database backup of each target before scheduled imports; batch cloning does not create an automatic rollback snapshot.

## Testing

```bash
composer dump-autoload
phpunit --configuration phpunit.xml
```
