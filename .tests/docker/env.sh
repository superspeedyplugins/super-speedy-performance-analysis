#!/usr/bin/env bash
# Shared config for the SSPA docker test environment. No docker-compose needed.

# Container names and the host port are overridable so a second checkout (a parallel
# session on a feature branch) can run its own environment alongside the default one:
#   SSPA_ENV_PREFIX=sspack SSPA_PORT=8092 .tests/run-tests.sh
SSPA_ENV_PREFIX="${SSPA_ENV_PREFIX:-sspa}"
SSPA_NET="${SSPA_ENV_PREFIX}-net"
SSPA_DB="${SSPA_ENV_PREFIX}-db"
SSPA_WP="${SSPA_ENV_PREFIX}-wp"
SSPA_REDIS="${SSPA_ENV_PREFIX}-redis"
SSPA_PORT="${SSPA_PORT:-8090}"
REDIS_IMAGE=redis:7-alpine

DB_IMAGE=mariadb:10.11
WP_IMAGE=wordpress:php8.3-apache
CLI_IMAGE=wordpress:cli-php8.3

PLUGIN_SLUG=super-speedy-performance-analysis
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONTAINER_PLUGIN_DIR=/var/www/html/wp-content/plugins/$PLUGIN_SLUG

# Run wp-cli against the WP container's files + network. --user 33:33 = www-data so any
# files wp-cli writes stay owned by apache.
cli() {
    # The wordpress image's wp-config.php reads DB settings from env at RUNTIME, so the
    # CLI container needs the same WORDPRESS_* vars as the apache container.
    docker run --rm --volumes-from $SSPA_WP --network $SSPA_NET --user 33:33 -e HOME=/tmp \
        -e WORDPRESS_DB_HOST=$SSPA_DB -e WORDPRESS_DB_USER=wp \
        -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wordpress \
        $CLI_IMAGE wp "$@"
}

# Copy the plugin into the container volume. A bind mount would be nicer but /opt/homebrew
# is not in Docker Desktop's shared paths, which yields a silently-empty mount. Cheap: the
# plugin is small; run before every test invocation.
sync_plugin() {
    docker exec $SSPA_WP mkdir -p "$CONTAINER_PLUGIN_DIR"
    COPYFILE_DISABLE=1 tar --no-xattrs -C "$PLUGIN_DIR" --exclude=.git -cf - . \
        | docker exec -i $SSPA_WP tar -C "$CONTAINER_PLUGIN_DIR" -xf - 2>/dev/null
    docker exec $SSPA_WP chown -R www-data:www-data "$CONTAINER_PLUGIN_DIR"
}
