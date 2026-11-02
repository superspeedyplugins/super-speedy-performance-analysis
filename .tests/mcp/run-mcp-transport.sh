#!/usr/bin/env bash
# The real MCP transport (official MCP Adapter endpoint, as Super Speedy > MCP setup
# configures clients), on a site where Performance Analysis is the only Super Speedy plugin.
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-mcp}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
sync_plugin || exit 1
case "$SSPA_SITE_DIR" in "$SITES_ROOT"/*) ;; *) echo 'Refusing non-isolated site' >&2; exit 1;; esac
out="$PLUGIN_DIR/.data/mcp-transport/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$out"
export SSPA_MCP_SECRET_FILE="$out/.app-password"
cli eval-file "$PLUGIN_DIR/.tests/mcp/transport-prepare.php" | tee "$out/prepare.log"
[ "${PIPESTATUS[0]}" -eq 0 ] || exit 1
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_OUT="$out"
echo "MCP transport: $SSPA_SITE_URL"
timeout 300 node "$PLUGIN_DIR/.tests/mcp/mcp-transport.cjs" 2>&1 | tee "$out/transport.log"
result=${PIPESTATUS[0]}
rm -f "$SSPA_MCP_SECRET_FILE"
echo "Evidence: $out"
exit "$result"
