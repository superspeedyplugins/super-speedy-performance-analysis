#!/usr/bin/env bash
# Install the excimer PECL extension into the RUNNING sspa-wp container (idempotent,
# cheap when already installed). The wordpress:php8.3-apache image runs mod_php, so the
# extension must live in the apache container - the throwaway CLI container never gets
# it, which is why test 18 decides based on the CAPTURE contents, not extension_loaded().
#
# Container recreation loses the install; run-tests.sh calls this on every run so the
# environment self-heals, same policy as sync_plugin.
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

if docker exec "$SSPA_WP" php -m 2>/dev/null | grep -qi '^excimer$'; then
    exit 0
fi

echo "installing excimer into $SSPA_WP (one-off, ~30s)..."
# No set -e in the block: apache2ctl warns about ServerName on this image and pecl
# grumbles when re-run - only the final verification decides success.
docker exec -u root "$SSPA_WP" bash -c '
    apt-get update -qq >/dev/null 2>&1
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq $PHPIZE_DEPS >/dev/null 2>&1
    pecl install excimer >/dev/null 2>&1
    docker-php-ext-enable excimer >/dev/null 2>&1
    apache2ctl -k graceful >/dev/null 2>&1
    true
'

if docker exec "$SSPA_WP" php -m 2>/dev/null | grep -qi '^excimer$'; then
    echo "excimer installed"
else
    echo "excimer install failed - profiler tests will be skipped"
    exit 1
fi
