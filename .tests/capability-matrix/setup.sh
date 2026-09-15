#!/usr/bin/env bash
# Requires Dave's recorded approval for system-dependent Docker tests.
# Stable containers/databases are retained; re-entry refreshes code, not host configuration.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER="${SSPA_MATRIX_DOCKER:-docker}"
CORE="${SSPA_MATRIX_CORE:?Set SSPA_MATRIX_CORE to pristine WordPress}"
WOO="${SSPA_MATRIX_WOO:?Set SSPA_MATRIX_WOO to WooCommerce source}"
OUT="$ROOT/.data/capability-matrix"
mkdir -p "$OUT"
if [ ! -f "$OUT/password" ]; then (umask 077; openssl rand -hex 24 > "$OUT/password"); fi
password=$(cat "$OUT/password")
if ! "$DOCKER" network inspect sspa-capability >/dev/null 2>&1; then "$DOCKER" network create sspa-capability >/dev/null; fi
for mode in on off; do
    db="sspa-capability-db-$mode"
    if ! "$DOCKER" container inspect "$db" >/dev/null 2>&1; then
        "$DOCKER" run -d --name "$db" --network sspa-capability --label sspa.test=capability-matrix \
            -e "MARIADB_ROOT_PASSWORD=$password" mariadb:10.11@sha256:07c0aaff7396b74cb7975cba78257178d188e30f531a5db2b617c48beef13c41 --performance-schema="$mode" --innodb-buffer-pool-size=64M >/dev/null
    else "$DOCKER" start "$db" >/dev/null; fi
    ready=0
    for attempt in {1..60}; do
        if "$DOCKER" exec -e "MYSQL_PWD=$password" "$db" mariadb -uroot -e "SELECT 1" >/dev/null 2>&1; then ready=1; break; fi
        sleep 1
    done
    [ "$ready" = 1 ] || { echo 'Database failed to become ready' >&2; exit 1; }
    # Accounts are confined to these synthetic databases; credentials never enter git/logs.
    printf "CREATE DATABASE IF NOT EXISTS sspa_matrix; CREATE USER IF NOT EXISTS 'matrix'@'%%' IDENTIFIED BY '%s'; GRANT ALL ON sspa_matrix.* TO 'matrix'@'%%';\n" "$password" |
        "$DOCKER" exec -i -e "MYSQL_PWD=$password" "$db" mariadb -uroot
    if [ "$mode" = on ]; then
        printf "CREATE USER IF NOT EXISTS 'matrix_readable'@'%%' IDENTIFIED BY '%s'; GRANT ALL ON sspa_matrix.* TO 'matrix_readable'@'%%'; GRANT SELECT ON performance_schema.* TO 'matrix_readable'@'%%';\n" "$password" |
            "$DOCKER" exec -i -e "MYSQL_PWD=$password" "$db" mariadb -uroot
    fi
done
for row in absent blocked available; do
    container="sspa-capability-$row"; db=sspa-capability-db-on; user=matrix; enabled=0; status=blocked
    if [ "$row" = absent ]; then db=sspa-capability-db-off; status=missing; fi
    if [ "$row" = available ]; then user=matrix_readable; enabled=1; status=active; fi
    if ! "$DOCKER" container inspect "$container" >/dev/null 2>&1; then
        "$DOCKER" run -d --name "$container" --network sspa-capability --label sspa.test=capability-matrix \
            -e "SSPA_MATRIX_EXTENSIONS=$enabled" -e "SSPA_MATRIX_PS=$status" \
            -e "SSPA_MATRIX_DB=$db" -e "SSPA_MATRIX_USER=$user" -e "SSPA_MATRIX_PASSWORD=$password" \
            sspa-capability-matrix:20260915 >/dev/null
    else "$DOCKER" start "$container" >/dev/null; fi
    tar -C "$CORE" -cf - . | "$DOCKER" exec -i "$container" tar -xf - -C /var/www/html
    "$DOCKER" exec "$container" mkdir -p /var/www/html/wp-content/plugins/super-speedy-performance-analysis /var/www/html/wp-content/plugins/woocommerce /matrix /evidence
    tar -C "$ROOT" --exclude='.git' --exclude='.data' -cf - super-speedy-performance-analysis.php defines.php includes dropins mu profiler rules traffic-observer uninstall.php readme.txt LICENSE super-speedy-settings |
        "$DOCKER" exec -i "$container" tar -xf - -C /var/www/html/wp-content/plugins/super-speedy-performance-analysis
    tar -C "$WOO" -cf - . | "$DOCKER" exec -i "$container" tar -xf - -C /var/www/html/wp-content/plugins/woocommerce
    tar -C "$ROOT/.tests/capability-matrix" -cf - probe.php bootstrap.php | "$DOCKER" exec -i "$container" tar -xf - -C /matrix
    "$DOCKER" exec "$container" php /matrix/bootstrap.php
    if [ "$enabled" = 1 ]; then
        printf 'extension=xhprof.so\nextension=spx.so\nextension=opentelemetry.so\n' |
            "$DOCKER" exec -i "$container" sh -c 'cat > /usr/local/etc/php/conf.d/matrix.ini'
    fi
    if ! "$DOCKER" exec "$container" wp --allow-root core is-installed >/dev/null 2>&1; then
        "$DOCKER" exec "$container" wp --allow-root core install --url="http://$container" --title='Capability matrix' --admin_user=matrix --admin_password="$password" --admin_email=matrix@example.invalid --skip-email >/dev/null
    fi
    "$DOCKER" exec "$container" wp --allow-root plugin activate woocommerce super-speedy-performance-analysis >/dev/null
    "$DOCKER" exec "$container" wp --allow-root option update woocommerce_custom_orders_table_enabled yes >/dev/null
    echo "Retained: $container ($status, extensions=$enabled)"
done
