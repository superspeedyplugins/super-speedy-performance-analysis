#!/usr/bin/env bash
# Plugins tab journey: Measure button, the Plugin Impact Analysis summary and the explorer
# controls, on its own site (tests-plugins-tab). Runs one real Plugin Impact Analysis.
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-plugins-tab}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
sync_plugin || exit 1
case "$SSPA_SITE_DIR" in "$SITES_ROOT"/*) ;; *) echo 'Refusing non-isolated site' >&2; exit 1;; esac
out="$PLUGIN_DIR/.data/plugins-tab/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$out"
cli eval-file "$PLUGIN_DIR/.tests/browser/prepare-plugins-tab.php" | tee "$out/prepare.log"
[ "${PIPESTATUS[0]}" -eq 0 ] || exit 1
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS" SSPA_E2E_OUT="$out"
echo "Plugins tab browser journey: $SSPA_SITE_URL"
node "$PLUGIN_DIR/.tests/browser/plugins-tab.e2e.cjs" 2>&1 | tee "$out/journey.log"
result=${PIPESTATUS[0]}
echo "Evidence: $out"
exit "$result"
