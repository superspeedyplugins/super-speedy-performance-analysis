#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
export SSPA_SCENARIO="${SSPA_MULTISITE_SCENARIO:-tests-multisite}"
source "$repo/.tests/env.sh"
set -e
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO"
fi
# Keep only this runner's own default site; a branch suite's override copy stays temporary.
case "$SSPA_SCENARIO" in
    tests-multisite|tests-w3-multisite) "$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" 'SSPA Multisite regression (case 111)' || exit 1 ;;
esac
plugin_path="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
if [ -n "${SSPA_MULTISITE_ZIP:-}" ]; then
    if [ -L "$plugin_path" ]; then rm "$plugin_path"; fi
    if [ ! -e "$plugin_path" ]; then cli plugin install "$SSPA_MULTISITE_ZIP"; fi
else
    if [ -L "$plugin_path" ]; then ln -sfn "$PLUGIN_DIR" "$plugin_path"; fi
    sync_plugin
fi
sspa_require_site
multisite_enabled=$(cli config get MULTISITE --type=constant 2>/dev/null || true)
if [ "$multisite_enabled" != true ] && [ "$multisite_enabled" != 1 ]; then
    cli core multisite-convert --title='Performance Analysis network ownership regression'
    cli config set MULTISITE true --raw --anchor='if ( ! defined(' --placement=before
    cli config set SUBDOMAIN_INSTALL false --raw --anchor='if ( ! defined(' --placement=before
    cli config set DOMAIN_CURRENT_SITE "${SSPA_SITE_URL#http://}" --anchor='if ( ! defined(' --placement=before
    cli config set PATH_CURRENT_SITE / --anchor='if ( ! defined(' --placement=before
    cli config set SITE_ID_CURRENT_SITE 1 --raw --anchor='if ( ! defined(' --placement=before
    cli config set BLOG_ID_CURRENT_SITE 1 --raw --anchor='if ( ! defined(' --placement=before
fi
cli --user=1 plugin activate "$PLUGIN_SLUG" --network
cli --user=1 eval-file "$repo/.tests/multisite/assertions.php"
