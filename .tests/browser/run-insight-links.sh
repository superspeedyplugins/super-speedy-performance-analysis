#!/usr/bin/env bash
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
sync_plugin || exit 1
case "$SSPA_SITE_DIR" in "$SITES_ROOT"/*) ;; *) echo 'Refusing non-isolated site' >&2; exit 1;; esac
mkdir -p "$PLUGIN_DIR/.data/insight-links"
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS" SSPA_E2E_OUT="$PLUGIN_DIR/.data/insight-links"
echo "Overview insight links browser regression: $SSPA_SITE_URL"
node "$PLUGIN_DIR/.tests/browser/insight-links.e2e.cjs"
