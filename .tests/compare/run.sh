#!/usr/bin/env bash
# Compare and the share panel: case 135 (server paths through real HTTP) and the browser journey
# from the admin menu, on their own retained parallel-dev site.
#
#   bash .tests/compare/run.sh          # case 135, then the browser journey
#   bash .tests/compare/run.sh case     # case 135 only
#   bash .tests/compare/run.sh journey  # the browser journey only
#   bash .tests/compare/run.sh live     # load the real superspeedy.org documents in the browser
#
# Site: tests-compare (override with SSPA_COMPARE_SCENARIO). State is cleared on the way IN and
# left behind for inspection; evidence goes to .data/compare/.
set -uo pipefail
PART="${1:-all}"
export SSPA_SCENARIO="${SSPA_COMPARE_SCENARIO:-tests-compare}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/../env.sh"
OUT="$PLUGIN_DIR/.data/compare"
mkdir -p "$OUT/journey"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$PLUGIN_DIR/.tests/setup-site.sh" > "$OUT/setup.log" 2>&1 || { echo "FAIL: could not create $SSPA_SCENARIO"; exit 1; }
fi
"$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" "SSPA Compare and share panel regression (case 135, browser journey)" || exit 1
link="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
[ -L "$link" ] || { echo 'FAIL: expected an isolated source symlink'; exit 1; }
ln -sfn "$PLUGIN_DIR" "$link" || exit 1
sspa_require_site || exit 1
sync_plugin || exit 1
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1
cli plugin activate "$PLUGIN_SLUG" woocommerce --quiet || exit 1
cli option update woocommerce_custom_orders_table_enabled yes --quiet || exit 1
cli option update woocommerce_coming_soon no --quiet || exit 1
cli option update woocommerce_store_pages_only no --quiet || exit 1
mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins" || exit 1
ln -sfn "$PLUGIN_DIR/.tests/fixtures/compare-http-observer.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-compare-http-observer.php" || exit 1
export SSPA_FLEET_OUTPUT="$OUT/journey" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
FAILED=0

if [ "$PART" = all ] || [ "$PART" = case ]; then
    cli eval-file "$PLUGIN_DIR/.tests/fixtures/reset-retained-plugins.php" > /dev/null || exit 1
    cli eval-file "$PLUGIN_DIR/.tests/cases/135-compare-share-panel.php" > "$OUT/case-135.log" 2>&1
    grep -E '^(PASS|FAIL|INFO)' "$OUT/case-135.log"
    if grep -q '^FAIL' "$OUT/case-135.log" || ! grep -q '^PASS' "$OUT/case-135.log"; then FAILED=1; fi
fi

if [ "$PART" = all ] || [ "$PART" = journey ]; then
    # Cleared on the way in: sharing off, no prompt choices, the build's documents served.
    cli eval 'update_option("sspa_share_optin", 0, false); delete_user_meta(1, "sspa_share_prompt_snoozed_until"); delete_user_meta(1, "sspa_share_prompt_never"); delete_option("sspa_test_ssorg_log"); update_option("sspa_test_ssorg_mode", "fixture", false);' || exit 1
    sleep 3 # php-fpm opcache revalidation after a new MU fixture link
    timeout 2700 node "$HERE/journey.e2e.cjs" > "$OUT/journey.log" 2>&1
    status=$?
    cat "$OUT/journey.log"
    [ "$status" -eq 0 ] || { echo "FAIL: browser journey exited $status"; FAILED=1; }
fi
if [ "$PART" = live ]; then
    cli eval 'update_option("sspa_test_ssorg_mode", "live", false); delete_option("sspa_test_ssorg_log");' || exit 1
    timeout 900 node "$HERE/journey.e2e.cjs" live > "$OUT/journey-live.log" 2>&1
    status=$?
    cat "$OUT/journey-live.log"
    [ "$status" -eq 0 ] || { echo "FAIL: live browser check exited $status"; FAILED=1; }
fi
exit $FAILED
