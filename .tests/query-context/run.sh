#!/usr/bin/env bash
# Where each slow query came from (plan .docs/2026-11-24-slowest-query-context.md). Own retained
# site tests-query-context (override with SSPA_SCENARIO): the fixture classic theme and MU plugin
# in .tests/fixtures/query-context, one real spot analysis of Home, then the PHP case (capture,
# panel HTML, Markdown, get-page-profile ability, Analyse rows over admin-ajax) and the browser
# journey (expand the row, real Analyse rows click). `case` or `journey` runs one part.
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-query-context}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then bash "$PLUGIN_DIR/.tests/setup-site.sh" || exit 1; fi
sspa_require_site || exit 1
sync_plugin || exit 1
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1
part="${1:-all}"
export SSPA_FLEET_OUTPUT="${SSPA_FLEET_OUTPUT:-$PLUGIN_DIR/.data/query-context/$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$SSPA_FLEET_OUTPUT"
fixtures="$PLUGIN_DIR/.tests/fixtures/query-context"
mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins" "$SSPA_SITE_DIR/wp-content/themes"
# Copied, not linked: PHP reports frames by the resolved path, and a link into this checkout would
# put the fixture's frames inside PA's own directory, which attribution and frames skip.
rm -rf "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-qc-fixture.php" "$SSPA_SITE_DIR/wp-content/themes/sspa-qc-theme"
cp "$fixtures/sspa-qc-fixture.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-qc-fixture.php"
cp -r "$fixtures/sspa-qc-theme" "$SSPA_SITE_DIR/wp-content/themes/sspa-qc-theme"
echo "Test site: $SSPA_SITE_URL"
result=0
if [ "$part" = all ] || [ "$part" = case ]; then
    cli eval-file "$PLUGIN_DIR/.tests/fixtures/reset-retained-plugins.php" > "$SSPA_FLEET_OUTPUT/entry-reset.log" || exit 1
    output=$(cli eval-file "$PLUGIN_DIR/.tests/query-context/assert-query-context.php" 2>&1)
    code=$?
    echo "$output" | tee "$SSPA_FLEET_OUTPUT/case.log"
    if [ "$code" -ne 0 ] || echo "$output" | grep -q '^FAIL' || ! echo "$output" | grep -q '^PASS'; then result=1; fi
fi
if [ "$part" = all ] || [ "$part" = journey ]; then
    export SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
    node "$PLUGIN_DIR/.tests/query-context/journey.e2e.cjs" 2>&1 | tee "$SSPA_FLEET_OUTPUT/journey.log"
    [ "${PIPESTATUS[0]}" -eq 0 ] || result=1
fi
echo "Evidence: $SSPA_FLEET_OUTPUT"
exit "$result"
