#!/bin/sh

set -eu

source_path="${WP_SOURCE_PATH:-/srv/source}"
target_path="${WP_TARGET_PATH:-/srv/target}"
source_url="${SOURCE_URL:-http://source-wordpress}"
target_url="${TARGET_URL:-http://target-wordpress}"
admin_user="${WP_ADMIN_USER:-admin}"
admin_password="${WP_ADMIN_PASSWORD:-password}"
admin_email="${WP_ADMIN_EMAIL:-admin@example.com}"
expected_title="Municipio Clone E2E $(date +%s)"

log() {
    printf '[e2e] %s\n' "$1"
}

wp_at() {
    path="$1"
    shift

    wp --allow-root --path="$path" "$@"
}

retry() {
    label="$1"
    shift
    attempts=0

    until "$@"; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            log "Timed out while waiting for ${label}."
            return 1
        fi

        sleep 2
    done
}

wait_for_wordpress_files() {
    path="$1"
    label="$2"

    retry "${label} WordPress files" test -f "${path}/wp-config.php"
}

wait_for_http() {
    url="$1"
    label="$2"

    retry "${label} HTTP endpoint" wget -q -O /dev/null "${url}/wp-login.php"
}

ensure_site_installed() {
    path="$1"
    url="$2"
    title="$3"

    if wp_at "$path" core is-installed >/dev/null 2>&1; then
        return 0
    fi

    wp_at "$path" core install \
        --url="$url" \
        --title="$title" \
        --admin_user="$admin_user" \
        --admin_password="$admin_password" \
        --admin_email="$admin_email" \
        --skip-email >/dev/null
}

activate_plugin() {
    path="$1"

    if wp_at "$path" plugin is-active municipio-clone >/dev/null 2>&1; then
        return 0
    fi

    wp_at "$path" plugin activate municipio-clone >/dev/null
}

main() {
    log "Waiting for WordPress volumes to be initialized."
    wait_for_wordpress_files "$source_path" "source"
    wait_for_wordpress_files "$target_path" "target"

    log "Waiting for WordPress HTTP endpoints."
    wait_for_http "$source_url" "source"
    wait_for_http "$target_url" "target"

    log "Installing source and target sites."
    retry "source installation" ensure_site_installed "$source_path" "$source_url" "Municipio Clone Source"
    retry "target installation" ensure_site_installed "$target_path" "$target_url" "Municipio Clone Target"

    log "Activating the plugin on both sites."
    retry "source plugin activation" activate_plugin "$source_path"
    retry "target plugin activation" activate_plugin "$target_path"

    log "Creating source content."
    wp_at "$source_path" post create \
        --post_type=post \
        --post_status=publish \
        --post_title="$expected_title" \
        --post_content="Copied by the municipio-clone e2e smoke test." >/dev/null

    log "Creating an application password for the source site."
    application_password="$(wp_at "$source_path" user application-password create "$admin_user" municipio-clone-e2e --porcelain | tail -n 1 | tr -d '\r')"
    if [ -z "$application_password" ]; then
        log "Failed to create an application password for the source site."
        exit 1
    fi

    log "Running the clone command against the target site."
    wp_at "$target_path" municipio clone \
        --source-url="$source_url" \
        --target="$target_url" \
        --username="$admin_user" \
        --application-password="$application_password" \
        --force

    log "Verifying that the cloned content exists on the target site."
    if [ -z "$(wp_at "$target_path" post list --post_type=post --s="$expected_title" --format=ids | tr -d '\r')" ]; then
        log "The expected source post was not imported into the target site."
        exit 1
    fi

    if [ "$(wp_at "$target_path" option get home | tr -d '\r')" != "$target_url" ]; then
        log "The target home URL was not normalized after import."
        exit 1
    fi

    log "E2E clone smoke test passed."
}

main "$@"
