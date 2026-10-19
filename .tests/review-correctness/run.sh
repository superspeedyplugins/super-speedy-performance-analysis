#!/usr/bin/env bash
set -uo pipefail
export SSPA_SCENARIO="${SSPA_REVIEW_SCENARIO:-tests-review-correctness}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" || exit 1
fi
# A newly created site links the canonical repository. Bind this dedicated harness
# to its invoking checkout before the realpath guard permits any fixture mutation.
review_plugin="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
if [ -L "$review_plugin" ]; then
    ln -sfn "$PLUGIN_DIR" "$review_plugin" || exit 1
elif [ ! -e "$review_plugin" ]; then
    ln -s "$PLUGIN_DIR" "$review_plugin" || exit 1
else
    echo "FAIL: refusing non-symlink review plugin directory: $review_plugin" >&2
    exit 1
fi
sspa_require_site || exit 1
sync_plugin || exit 1
# This isolated UI regression does not test external update servers or icon CDNs.
# Keep their failure explicit, and keep the real local HTTP/traffic paths enabled.
cli config set WP_HTTP_BLOCK_EXTERNAL true --raw --anchor='if ( ! defined(' --placement=before || exit 1
cli option update show_avatars 0 --quiet || exit 1
cli eval '$r=wp_remote_get("https://api.wordpress.org/core/version-check/1.7/"); if(!is_wp_error($r) || "http_request_not_executed" !== $r->get_error_code()) WP_CLI::error("Dedicated review site must reject external HTTP explicitly."); echo "PASS: dedicated non-WooCommerce browser fixture explicitly blocks external HTTP (http_request_not_executed)\n";' || exit 1
# Guest recording needs a real local page, independent of a fleet theme's font CDN.
cli theme is-installed twentytwentyfive || exit 1
cli theme activate twentytwentyfive --quiet || exit 1
cli eval 'if("twentytwentyfive" !== get_stylesheet()) WP_CLI::error("Dedicated review page requires the bundled local-asset theme."); echo "PASS: dedicated guest page uses bundled Twenty Twenty-Five (".wp_get_theme()->get("Version").")\n";' || exit 1
if cli plugin is-active woocommerce >/dev/null 2>&1; then
    echo 'FAIL: dedicated non-WooCommerce scenario has WooCommerce active' >&2
    exit 1
fi
mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins"
cat > "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-review-sampling.php" <<'PHP'
<?php
// Dedicated regression fixture: observe every anonymous request deterministically.
add_filter('sspa_traffic_origin_sample_modulus', function () { return 1; });
// Native Unicode icons avoid an unrelated s.w.org image download during page load.
$sspa_review_native_icons = static function() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('embed_head', 'print_emoji_detection_script');
};
add_action('init', $sspa_review_native_icons, 0);
add_action('admin_init', $sspa_review_native_icons, 0);
PHP
cli plugin activate "$PLUGIN_SLUG" || exit 1
cli eval '$active=SSPA_Traffic_Collection::active(); if($active && !SSPA_Traffic_Collection::stop($active["id"],true)) WP_CLI::error("Could not stop previous fixture collection");' || exit 1
cli eval-file "$PLUGIN_DIR/.tests/review-correctness/assertions.php" || exit 1
export SSPA_E2E_USER=sspa-review-browser
export SSPA_E2E_PASSWORD=Synthetic-review-browser-93!
cli eval '$id=username_exists(getenv("SSPA_E2E_USER")); if(!$id) $id=wp_create_user(getenv("SSPA_E2E_USER"),getenv("SSPA_E2E_PASSWORD"),"review-browser@example.invalid"); if(is_wp_error($id)) WP_CLI::error($id->get_error_message()); $u=new WP_User($id); $u->set_role("administrator"); wp_set_password(getenv("SSPA_E2E_PASSWORD"),$id);' || exit 1
export SSPA_FLEET_OUTPUT="$PLUGIN_DIR/.data/review-followup/browser"
mkdir -p "$SSPA_FLEET_OUTPUT"
node "$PLUGIN_DIR/.tests/review-correctness/nonwoo.cjs"
