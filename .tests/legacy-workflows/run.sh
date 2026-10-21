#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export SSPA_SCENARIO="${SSPA_LEGACY_SCENARIO:-tests-legacy-workflows}"
source "$root/.tests/env.sh"
set -euo pipefail
bash "$root/.tests/setup-site.sh"
"$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" 'SSPA case 117 legacy (non-HPOS) workflows regression'
sspa_require_site
sync_plugin
cli wc hpos sync --batch-size=100
cli option update woocommerce_custom_orders_table_enabled no --quiet
cli option update woocommerce_custom_orders_table_data_sync_enabled no --quiet
cli eval 'if (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) { WP_CLI::error("Legacy fixture still uses HPOS."); } echo "PASS: legacy CPT storage is active\n";' 
export SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
export SSPA_FLEET_OUTPUT="${SSPA_LEGACY_OUTPUT:-$root/.data/legacy-workflow-browser}"
mkdir -p "$SSPA_FLEET_OUTPUT"
node "$root/.tests/browser/workflows-legacy.e2e.cjs"
