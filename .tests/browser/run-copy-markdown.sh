#!/usr/bin/env bash
# Copy Markdown survives a slow export (0.70.5). Own retained site tests-copy-markdown
# (override with SSPA_SCENARIO). Installs the test-only slow-export MU fixture, prepares two
# real spot runs and one real checkout run, then drives copy-markdown.e2e.cjs in Chromium.
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-copy-markdown}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then bash "$PLUGIN_DIR/.tests/setup-site.sh" || exit 1; fi
sspa_require_site || exit 1
sync_plugin || exit 1
export SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
export SSPA_FLEET_OUTPUT="${SSPA_FLEET_OUTPUT:-$PLUGIN_DIR/.data/copy-markdown/$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$SSPA_FLEET_OUTPUT"
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1
mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins"
ln -sfn "$PLUGIN_DIR/.tests/fixtures/slow-markdown-export.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-test-slow-markdown-export.php"
cli eval-file "$PLUGIN_DIR/.tests/fixtures/reset-retained-plugins.php" > "$SSPA_FLEET_OUTPUT/entry-reset.log" || exit 1
cli eval-file "$PLUGIN_DIR/.tests/fixtures/browser-prepare.php" > "$SSPA_FLEET_OUTPUT/fixtures.json" || exit 1
cli eval-file "$PLUGIN_DIR/.tests/browser/prepare-copy-markdown.php" > "$SSPA_FLEET_OUTPUT/copy-fixtures.json" || exit 1
echo "Test site: $SSPA_SITE_URL"
SSPA_COPY_FIXTURES="$SSPA_FLEET_OUTPUT/copy-fixtures.json" node "$PLUGIN_DIR/.tests/browser/copy-markdown.e2e.cjs" 2>&1 | tee "$SSPA_FLEET_OUTPUT/copy-markdown.log"
result=${PIPESTATUS[0]}
echo "Browser evidence: $SSPA_FLEET_OUTPUT"
exit "$result"
