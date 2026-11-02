#!/usr/bin/env bash
# MCP on a loopback-blocked site (site tests-mcp-blocked, served at :8082 behind
# blocked-proxy.cjs): an agent with only an Application Password and the Abilities REST endpoints
# finds that PA reaches the site direct to its origin server when the edge refuses its own
# requests, stores basic-auth credentials when the origin demands them, and profiles editor saves
# (block editor REST and classic form) with profile-admin-save.
#
# The two listeners are started here, tracked by PID and stopped on every exit path. The site is
# left at its :8082 address with whatever the run produced; open it with
#   node .tests/mcp/blocked-proxy.cjs
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-mcp-blocked}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
# This site lives at :8082, in front of nginx's :8081, so every URL the suite uses says :8082.
SSPA_SITE_URL="${SSPA_SITE_URL/:8081/:8082}"
sspa_require_site || exit 1
sync_plugin || exit 1
case "$SSPA_SITE_DIR" in "$SITES_ROOT"/*) ;; *) echo 'Refusing non-isolated site' >&2; exit 1;; esac
out="$PLUGIN_DIR/.data/mcp-blocked/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$out"

proxy_pid=''
stop_proxy() {
    if [ -n "$proxy_pid" ] && kill -0 "$proxy_pid" 2>/dev/null; then
        kill "$proxy_pid" 2>/dev/null
        wait "$proxy_pid" 2>/dev/null
        echo "Stopped blocked-proxy (pid $proxy_pid)"
    fi
    rm -f "${SSPA_MCP_SECRET_FILE:-}"
}
trap stop_proxy EXIT

# Entry state: the site at :8082, WP-Cron off, the fixture cleared.
cli option update home "$SSPA_SITE_URL" --quiet || exit 1
cli option update siteurl "$SSPA_SITE_URL" --quiet || exit 1
cli config set DISABLE_WP_CRON true --raw --type=constant --anchor="define('WP_ENVIRONMENT_TYPE'" --placement=before --quiet || exit 1
export SSPA_MCP_SECRET_FILE="$out/.app-password"
cli eval-file "$PLUGIN_DIR/.tests/mcp/blocked-prepare.php" | tee "$out/prepare.log"
[ "${PIPESTATUS[0]}" -eq 0 ] || exit 1

export SSPA_PROXY_AUTH_FILE="$out/origin-auth" SSPA_PROXY_LOG="$out/proxy.log"
rm -f "$SSPA_PROXY_AUTH_FILE"
node "$PLUGIN_DIR/.tests/mcp/blocked-proxy.cjs" > "$out/proxy.out" 2>&1 &
proxy_pid=$!
echo "blocked-proxy pid $proxy_pid"
wait-for --timeout 15 --pid "$proxy_pid" --file "$out/proxy.out" --marker '^READY' || exit 1

export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_OUT="$out"
echo "MCP blocked: $SSPA_SITE_URL"
timeout 900 node "$PLUGIN_DIR/.tests/mcp/mcp-blocked.cjs" 2>&1 | tee "$out/blocked.log"
result=${PIPESTATUS[0]}

# Independent of the agent: no save-profiling object may remain in the database.
leftover=$(cli post list --post_type=any --post_status=any --meta_key=_sspa_workflow --meta_value=1 --format=count 2>/dev/null)
echo "Leftover save-profiling objects: ${leftover:-unknown}" | tee -a "$out/blocked.log"
if [ "${leftover:-x}" != "0" ]; then result=1; fi
echo "Evidence: $out"
exit "$result"
