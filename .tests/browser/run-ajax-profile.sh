#!/usr/bin/env bash
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
sync_plugin || exit 1
case "$SSPA_SITE_DIR" in "$SITES_ROOT"/*) ;; *) echo 'Refusing non-isolated site' >&2; exit 1;; esac
cli eval-file "$PLUGIN_DIR/.tests/browser/prepare-ajax-history.php" || exit 1
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
export SSPA_AJAX_ARTIFACT_DIR="$PLUGIN_DIR/.data/ajax-profile-browser"
echo "AJAX browser regression: $SSPA_SITE_URL"
node "$PLUGIN_DIR/.tests/browser/ajax-profile.e2e.cjs"
