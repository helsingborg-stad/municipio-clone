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
expected_content="Copied by the municipio-clone e2e smoke test."

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
        --post_content="$expected_content" >/dev/null

    log "Checking source application password support."
    application_password_support="$(wp_at "$source_path" eval 'echo wp_is_application_passwords_available() ? "yes" : "no";')"
    application_password_support="$(printf '%s' "$application_password_support" | tr -d '\r')"
    if [ "$application_password_support" != "yes" ]; then
        log "Application passwords are not available on the source site. Ensure the site runs with HTTPS or a local environment type before running the e2e smoke test."
        exit 1
    fi

    log "Creating an application password for the source site."
    application_password="$(wp_at "$source_path" user application-password create "$admin_user" municipio-clone-e2e --porcelain)"
    application_password="$(printf '%s' "$application_password" | tr -d '\r')"
    if [ -z "$application_password" ]; then
        log "Failed to create an application password for the source site."
        exit 1
    fi

    log "Verifying that the source export endpoint accepts the application password."
    export_status="$(
        php -r '
            $url = $argv[1];
            $username = $argv[2];
            $password = $argv[3];
            $context = stream_context_create([
                "http" => [
                    "method" => "POST",
                    "header" => implode("\r\n", [
                        "Content-Type: application/json",
                        "Authorization: Basic " . base64_encode($username . ":" . $password),
                    ]),
                    "content" => json_encode(["force" => true], JSON_THROW_ON_ERROR),
                    "ignore_errors" => true,
                    "timeout" => 60,
                ],
            ]);
            @file_get_contents($url, false, $context);
            $statusLine = "";
            foreach ($http_response_header ?? [] as $header) {
                if (str_starts_with($header, "HTTP/")) {
                    $statusLine = $header;
                }
            }
            if (preg_match("/\s(\d{3})\s/", $statusLine, $matches) === 1) {
                echo $matches[1];
            }
        ' \
        "${source_url}/wp-json/municipio-clone/v1/export" \
        "$admin_user" \
        "$application_password"
    )"
    if [ "$export_status" != "200" ]; then
        log "The source export endpoint rejected the generated application password with HTTP status ${export_status:-unknown}."
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
    imported_post_id="$(
        MUNICIPIO_CLONE_E2E_EXPECTED_TITLE="$expected_title" \
            wp_at "$target_path" eval 'global $wpdb; $title = (string) getenv("MUNICIPIO_CLONE_E2E_EXPECTED_TITLE"); $postId = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1", "post", $title)); echo $postId ?: "";'
    )"
    imported_post_id="$(printf '%s' "$imported_post_id" | tr -d '\r')"
    if [ -z "$imported_post_id" ]; then
        log "The expected source post was not imported into the target site."
        exit 1
    fi

    expected_content_base64="$(php -r 'echo base64_encode($argv[1]);' "$expected_content")"
    imported_post_content_base64="$(wp_at "$target_path" eval "echo base64_encode((string) get_post((int) ${imported_post_id})->post_content);")"
    imported_post_content_base64="$(printf '%s' "$imported_post_content_base64" | tr -d '\r')"
    if [ "$imported_post_content_base64" != "$expected_content_base64" ]; then
        log "The imported post content did not match the source content."
        exit 1
    fi

    if [ "$(wp_at "$target_path" option get home | tr -d '\r')" != "$target_url" ]; then
        log "The target home URL was not normalized after import."
        exit 1
    fi

    log "E2E clone smoke test passed."
}

main "$@"
