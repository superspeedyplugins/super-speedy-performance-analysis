#!/usr/bin/env bash
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SG_SCENARIO:-tests-siteground-cache}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" || exit 1
fi
"$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" 'SSPA SiteGround cache (sg-cachepress) regression' || exit 1
link="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
[ -L "$link" ] || { echo 'FAIL: expected isolated source symlink'; exit 1; }
ln -sfn "$PLUGIN_DIR" "$link" || exit 1
sspa_require_site || exit 1
sync_plugin || exit 1
cli config set WP_HTTP_BLOCK_EXTERNAL true --raw --anchor='if ( ! defined(' --placement=before --quiet || exit 1
cli option update show_avatars 0 --quiet || exit 1
cli theme activate twentytwentyfive --quiet || exit 1
mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins"
# Local icons and explicit external HTTP boundary, no mail is sent in this journey.
cat > "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-sg-icons.php" <<'PHP'
<?php
add_action('init', static function() { remove_action('admin_print_scripts', 'print_emoji_detection_script'); remove_action('wp_head', 'print_emoji_detection_script', 7); });
PHP
cli plugin activate "$PLUGIN_SLUG" --quiet || exit 1
if ! cli plugin is-installed sg-cachepress; then
    cli plugin install "${SSPA_SG_ZIP:?Set SSPA_SG_ZIP to the official SiteGround package for this vendor integration regression}" --quiet || exit 1
fi
cli plugin activate sg-cachepress --quiet || exit 1
cli plugin get sg-cachepress --field=version || exit 1
export SSPA_FLEET_OUTPUT="${SSPA_SG_OUTPUT:-$PLUGIN_DIR/.data/siteground-cache}"
mkdir -p "$SSPA_FLEET_OUTPUT"
export SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
node "$PLUGIN_DIR/.tests/browser/siteground-cache.e2e.cjs"
