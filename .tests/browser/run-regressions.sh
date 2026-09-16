#!/usr/bin/env bash
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
sync_plugin || exit 1
export SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
export SSPA_FLEET_OUTPUT="${SSPA_FLEET_OUTPUT:-$PLUGIN_DIR/.data/feature-browser/$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$SSPA_FLEET_OUTPUT"
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1
failed=0
matched=0
if [ -z "${1:-}" ]; then
    bash "$PLUGIN_DIR/.tests/browser/run-history-chart.sh" > "$SSPA_FLEET_OUTPUT/history-chart.log" 2>&1 || failed=$((failed + 1))
fi
for check in browser-transport loopback-fallback quick-comparison admin-journey measurement measurement-error report-panels report-mobile history-mobile admin-mobile tab-retry shared-link checkout-flow traffic-workflow sharing; do
    if [ -n "${1:-}" ] && [[ "$check" != *"$1"* ]]; then continue; fi
    matched=$((matched + 1))
    if [ "$check" = traffic-workflow ]; then cli plugin activate scalability-pro --quiet || exit 1; fi
    if [ "$check" = traffic-workflow ] && [ ! -f "$SSPA_SITE_DIR/wp-content/plugins/zz-ajax-owner/fixture.php" ]; then
        bash "$PLUGIN_DIR/.tests/run-tests.sh" 71-fast-ajax-profile > "$SSPA_FLEET_OUTPUT/ajax-prerequisite.log" 2>&1 || exit 1
        bash "$PLUGIN_DIR/.tests/run-tests.sh" 72-fast-ajax-spro >> "$SSPA_FLEET_OUTPUT/ajax-prerequisite.log" 2>&1 || exit 1
    fi
    cli eval-file "$PLUGIN_DIR/.tests/fixtures/browser-prepare.php" > "$SSPA_FLEET_OUTPUT/$check-fixtures.json" || exit 1
    echo "=== $check browser ==="
    node "$PLUGIN_DIR/.tests/browser/$check.e2e.cjs" 2>&1 | tee "$SSPA_FLEET_OUTPUT/$check.log"
    result=${PIPESTATUS[0]}
    if [ "$result" -ne 0 ]; then failed=$((failed + 1)); fi
done
if [ "$matched" -eq 0 ]; then echo "No browser journeys match: ${1:-}" >&2; exit 1; fi
echo "Browser evidence: $SSPA_FLEET_OUTPUT; $failed failed"
exit "$failed"
