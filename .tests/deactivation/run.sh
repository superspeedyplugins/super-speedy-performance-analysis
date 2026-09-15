#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
export SSPA_SCENARIO=tests-deactivation
source "$repo/.tests/env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$repo/.tests/setup-site.sh" || exit 1
fi
sspa_require_site || exit 1
cli plugin activate super-speedy-performance-analysis || exit 1
cli eval-file "$repo/.tests/deactivation/run.php" || exit 1
bash "$repo/.tests/deactivation/reinstall.sh" || exit 1
bash "$repo/.tests/deactivation/readonly.sh"
