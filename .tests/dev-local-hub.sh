#!/usr/bin/env bash
# Point the docker analysis site at the hub running on the HOST's local WordPress
# (http://localhost:8081) and fire a test submission - proves cross-site reception.
#
# One-time local setup (already done on Dave's machine; re-do after a `wp reset`):
#   ln -sfn <this-repo>/hub /opt/homebrew/var/www/superspeedy/wp-content/plugins/super-speedy-performance-hub
#   cd /opt/homebrew/var/www/superspeedy && wp plugin activate super-speedy-performance-hub
#
# NOTE: .tests/run-tests.sh case 11 re-points the docker site at itself; re-run this
# script afterwards if you want the docker site talking to the local hub again.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/docker/env.sh"

LOCAL_WP=/opt/homebrew/var/www/superspeedy
HUB_URL=http://host.docker.internal:8081

if ! (cd "$LOCAL_WP" && wp plugin is-active super-speedy-performance-hub 2>/dev/null); then
    echo "hub plugin is not active on the local install - see the one-time setup in this script"
    exit 1
fi

B64=$(cd "$LOCAL_WP" && wp option get ssph_pubkey | base64)

sync_plugin
cli option update sspa_hub_url "$HUB_URL" >/dev/null
cli option update sspa_share_optin 1 >/dev/null
cli eval "update_option('sspa_rules_pubkey', base64_decode('$B64'), false);" >/dev/null
cli transient delete sspa_rules_feed >/dev/null 2>&1 || true

# Fresh registration every run: clear this install's secrets for the local hub and its
# row at the hub (a dev hub gets reset often; identity continuity doesn't matter here).
UUID=$(cli option get sspa_install_uuid 2>/dev/null | tr -d '[:space:]' || true)
# Raw SQL + cache flush: with Redis, delete_option can no-op on a stale notoptions
# entry while the DB row survives (and vice versa). Nuking both layers converges.
cli eval "global \$wpdb; \$wpdb->query(\"DELETE FROM {\$wpdb->options} WHERE option_name LIKE 'sspa\\_install\\_secret%'\"); wp_cache_flush();" >/dev/null
if [ -n "$UUID" ]; then
    (cd "$LOCAL_WP" && wp db query "DELETE FROM wp_ssph_installs WHERE install_uuid = '$UUID'")
fi

echo "submitting from docker -> $HUB_URL ..."
cli eval '
$r = SSPA_Submitter::submit();
echo is_wp_error($r) ? "SUBMIT FAILED: " . $r->get_error_message() . "\n" : "SUBMIT OK\n";
$f = SSPA_Rules_Feed::refresh();
echo is_wp_error($f) ? "FEED FAILED: " . $f->get_error_message() . "\n" : "FEED VERIFIED OK\n";
'

echo "--- local hub state ---"
(cd "$LOCAL_WP" && wp eval '
global $wpdb;
echo "installs: " . $wpdb->get_var("SELECT COUNT(*) FROM wp_ssph_installs") . "\n";
echo "submissions: " . $wpdb->get_var("SELECT COUNT(*) FROM wp_ssph_submissions") . "\n";
echo "flattened impacts: " . $wpdb->get_var("SELECT COUNT(*) FROM wp_ssph_plugin_impacts") . "\n";
')
