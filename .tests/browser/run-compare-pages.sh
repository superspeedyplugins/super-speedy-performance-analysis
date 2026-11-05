#!/usr/bin/env bash
# Case 159 (real spot and ad hoc runs), then the Compare chart in a browser: title with the plugin
# version, the custom-URL page in the chart and table, and the page chooser.
set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "$PLUGIN_DIR/.tests/env.sh"

SSPA_SCENARIO="$SSPA_SCENARIO" "$PLUGIN_DIR/.tests/run-tests.sh" 159-compare-adhoc-pages || exit 1

export SSPA_E2E_BEFORE="$(cli eval 'global $wpdb; echo $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sspa_runs WHERE run_type = \"spot\" ORDER BY id DESC LIMIT 1 OFFSET 1");')"
export SSPA_E2E_AFTER="$(cli eval 'global $wpdb; echo $wpdb->get_var("SELECT id FROM {$wpdb->prefix}sspa_runs WHERE run_type = \"spot\" ORDER BY id DESC LIMIT 1");')"
export SSPA_E2E_VERSION="$(cli eval 'echo SSPA_VERSION;')"
export SSPA_E2E_URL="$SSPA_SITE_URL"
export SSPA_E2E_USER="$ADMIN_USER"
export SSPA_E2E_PASSWORD="$ADMIN_PASS"
export SSPA_E2E_SCREENSHOT="${SSPA_E2E_SCREENSHOT:-$PLUGIN_DIR/.data/history-browser/$SSPA_SCENARIO/compare-pages.png}"
mkdir -p "$(dirname "$SSPA_E2E_SCREENSHOT")"

node "$PLUGIN_DIR/.tests/browser/compare-pages.e2e.cjs"
