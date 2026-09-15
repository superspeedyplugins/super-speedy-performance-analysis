#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
export SSPA_SCENARIO=tests-deactivation-readonly
source "$repo/.tests/env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$(dirname "$PD_LIB")/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" || exit 1
fi
sspa_require_site || exit 1
copy="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
# This dedicated site uses an ordinary copy: uninstall must never target canonical source.
if [ -L "$copy" ]; then unlink "$copy"; fi
mkdir -p "$copy"
rsync -a --exclude='.*' --exclude=node_modules "$PLUGIN_DIR/" "$copy/" || exit 1
mkdir -p "$copy/.tests/lib"
rsync -a "$PLUGIN_DIR/.tests/lib/" "$copy/.tests/lib/" || exit 1
# Reset this permissions fixture at entry; it remains readonly after the test.
chmod 0755 "$SSPA_SITE_DIR/wp-content/mu-plugins"
cli plugin activate super-speedy-performance-analysis || exit 1
export SSPA_DEACTIVATION_TEST_REPO="$repo"
cli eval-file "$repo/.tests/deactivation/readonly.php"
