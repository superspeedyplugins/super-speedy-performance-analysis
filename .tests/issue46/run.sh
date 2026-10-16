#!/usr/bin/env bash
set -euo pipefail
export SSPA_SCENARIO=tests-issue46
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
set -euo pipefail
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$PLUGIN_DIR/.tests/setup-site.sh"
fi
"$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" 'SSPA case 101 connection-provider regression'
timeout 420 bash "$PLUGIN_DIR/.tests/run-tests.sh" 101-foreign
