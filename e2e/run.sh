#!/bin/sh

set -eu

if [ ! -f vendor/autoload.php ]; then
    printf '%s\n' 'Composer dependencies are required before running the e2e stack. Run "composer install" from the repository root first.' >&2
    exit 1
fi

if [ -z "${MUNICIPIO_CLONE_E2E_ENCRYPTION_KEY:-}" ]; then
    MUNICIPIO_CLONE_E2E_ENCRYPTION_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
    export MUNICIPIO_CLONE_E2E_ENCRYPTION_KEY
fi

cleanup() {
    docker compose -f docker-compose.e2e.yml down -v --remove-orphans
}

trap cleanup EXIT

docker compose -f docker-compose.e2e.yml up --build --abort-on-container-exit --exit-code-from e2e-runner
