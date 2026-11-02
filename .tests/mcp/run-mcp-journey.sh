#!/usr/bin/env bash
# Full MCP control journey (site tests-mcp): an agent with only an Application Password and
# the Abilities REST endpoints - no browser, no WP-Cron - discovers, enables the profiler,
# analyses, isolates a plugin, profiles a URL, runs the cache check and reads every result.
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-mcp}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
sync_plugin || exit 1
case "$SSPA_SITE_DIR" in "$SITES_ROOT"/*) ;; *) echo 'Refusing non-isolated site' >&2; exit 1;; esac
out="$PLUGIN_DIR/.data/mcp-journey/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$out"
# Entry state: WP-Cron off, so nothing but the agent's own calls can move a run.
cli config set DISABLE_WP_CRON true --raw --type=constant --anchor="define('WP_ENVIRONMENT_TYPE'" --placement=before --quiet || exit 1
export SSPA_MCP_SECRET_FILE="$out/.app-password"
cli eval-file "$PLUGIN_DIR/.tests/mcp/prepare.php" | tee "$out/prepare.log"
[ "${PIPESTATUS[0]}" -eq 0 ] || exit 1
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_OUT="$out"
echo "MCP journey: $SSPA_SITE_URL"
timeout 1500 node "$PLUGIN_DIR/.tests/mcp/mcp-journey.cjs" 2>&1 | tee "$out/journey.log"
result=${PIPESTATUS[0]}
rm -f "$SSPA_MCP_SECRET_FILE" "$SSPA_MCP_SECRET_FILE.subscriber"
echo "Evidence: $out"
exit "$result"
