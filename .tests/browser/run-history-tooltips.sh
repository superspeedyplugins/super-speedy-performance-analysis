#!/usr/bin/env bash
set -uo pipefail
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "$PLUGIN_DIR/.tests/env.sh"
sspa_require_site || exit 1
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
node "$PLUGIN_DIR/.tests/browser/history-tooltips.e2e.cjs"
