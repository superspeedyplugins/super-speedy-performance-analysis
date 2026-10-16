#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
export SSPA_SCENARIO="${SSPA_CACHE_SCOPE_SCENARIO:-tests-w3-cache-scope}"
source "$repo/.tests/env.sh"
set -e
case "$SSPA_SCENARIO" in tests-*) ;; *) echo 'Refusing a non-test cache-scope site' >&2; exit 1 ;; esac
if [ -z "${SSPA_CACHE_SCOPE_ZIP:-}" ]; then
    bash "$repo/.build/diagnose-wporg.sh"
    SSPA_CACHE_SCOPE_ZIP="$repo/.data/wporg-review/diagnostic/diagnostic-wporg.zip"
fi
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then bash "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO"; fi
sspa_require_site
plugin_path="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
if [ -L "$plugin_path" ]; then
    linked=$(realpath "$plugin_path")
    [ "$linked" = "$repo" ] || [ "$linked" = "$(realpath "$WORKSPACE/$PLUGIN_SLUG")" ] || { echo 'Unexpected source link' >&2; exit 1; }
    rm "$plugin_path"
fi
mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins"
cp "$repo/.tests/fixtures/local-delivery.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-regression-delivery.php"
evidence="$repo/.data/w3/cache-scope-$SSPA_SCENARIO"
mkdir -p "$evidence"
cli config set SSPA_CACHE_SCOPE_EVIDENCE "$evidence" --anchor='if ( ! defined(' --placement=before
cp "$repo/.tests/cache-scope/audit.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-cache-scope-audit.php"
cli plugin install "$SSPA_CACHE_SCOPE_ZIP" --force --activate
# Explicit package replacement boundary, matching the suite’s existing FPM revalidation precondition.
sleep 3
cli eval-file "$repo/.tests/cache-scope/assertions.php"
