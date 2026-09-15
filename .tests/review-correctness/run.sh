#!/usr/bin/env bash
set -uo pipefail
export SSPA_SCENARIO=tests-review-correctness
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" || exit 1
fi
sspa_require_site || exit 1
sync_plugin || exit 1
if cli plugin is-active woocommerce >/dev/null 2>&1; then
    echo 'FAIL: dedicated non-WooCommerce scenario has WooCommerce active' >&2
    exit 1
fi
mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins"
cat > "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-review-sampling.php" <<'PHP'
<?php
// Dedicated regression fixture: observe every anonymous request deterministically.
add_filter('sspa_traffic_origin_sample_modulus', function () { return 1; });
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
