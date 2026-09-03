#!/usr/bin/env bash
set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "$PLUGIN_DIR/.tests/env.sh"

SSPA_SCENARIO="$SSPA_SCENARIO" "$PLUGIN_DIR/.tests/run-tests.sh" 62-history-setup-series || exit 1

export SSPA_E2E_URL="$SSPA_SITE_URL"
export SSPA_E2E_USER="$ADMIN_USER"
export SSPA_E2E_PASSWORD="$ADMIN_PASS"
export SSPA_E2E_SCREENSHOT="$PLUGIN_DIR/.data/history-chart-e2e.png"

mkdir -p "$PLUGIN_DIR/.data"

NODE_BIN="/mnt/c/Users/dave/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe"
PLAYWRIGHT_DIR="/mnt/c/Users/dave/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright"
if [ -x "$NODE_BIN" ] && [ -d "$PLAYWRIGHT_DIR" ]; then
    export SSPA_PLAYWRIGHT_MODULE="$(wslpath -w "$PLAYWRIGHT_DIR")"
    export SSPA_E2E_SCREENSHOT="$(wslpath -w "$SSPA_E2E_SCREENSHOT")"
    export WSLENV="${WSLENV:+$WSLENV:}SSPA_E2E_URL:SSPA_E2E_USER:SSPA_E2E_PASSWORD:SSPA_E2E_SCREENSHOT:SSPA_PLAYWRIGHT_MODULE"
    "$NODE_BIN" "$(wslpath -w "$PLUGIN_DIR/.tests/browser/history-chart.e2e.cjs")"
else
    node "$PLUGIN_DIR/.tests/browser/history-chart.e2e.cjs"
fi
