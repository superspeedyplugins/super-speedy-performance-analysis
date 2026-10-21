#!/usr/bin/env bash
set -euo pipefail
export SSPA_SCENARIO="${SSPA_ISSUE46_SCENARIO:-tests-issue46}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
set -euo pipefail
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$PLUGIN_DIR/.tests/setup-site.sh"
fi
# Keep only this runner's own default site; a branch suite's override copy stays temporary.
case "$SSPA_SCENARIO" in
    tests-issue46) "$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" 'SSPA case 101 connection-provider regression' ;;
esac
timeout 420 bash "$PLUGIN_DIR/.tests/run-tests.sh" 101-foreign
