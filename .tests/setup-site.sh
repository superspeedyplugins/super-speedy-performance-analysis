#!/usr/bin/env bash
#
# Build (or rebuild) the e2e test site. Native nginx + php-fpm + mariadb via parallel-dev -
# no Docker. Replaces .tests/docker/up.sh.
#
#   .tests/setup-site.sh            # create if missing, top up what is missing
#   .tests/setup-site.sh --reset    # destroy and rebuild from scratch
#
# What the suite needs, and why:
#   WooCommerce + 18 sample products  - 19/33/38 (checkout flow, orders, turnstile) and the
#                                       sector detection in 34; several cases silently
#                                       degrade rather than fail when the store is empty
#   3 processing orders               - order-management and checkout-flow evidence
#   30 posts in a News category       - gives the crawler real archives to profile
#   HPOS enabled                      - live runs HPOS and the plugin queries wp_wc_orders
#                                       directly in raw SQL; with HPOS off, ownership
#                                       lookups return nothing and it looks like a bug
#   coming-soon off                   - WooCommerce ships it ON, which hides the store from
#                                       logged-out visitors, so the crawler profiles nothing

set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/env.sh"

PD="$HOME/dev/super-speedy/tools/parallel-dev/bin"
RESET="${1:-}"

if [ "$RESET" = "--reset" ] || [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    if [ -f "$SSPA_SITE_DIR/wp-config.php" ]; then
        echo "==> resetting $SSPA_SITE_URL"
        "$PD/reset-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" || exit 1
    else
        echo "==> creating $SSPA_SITE_URL"
        "$PD/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" || exit 1
    fi
fi

echo "==> plugins"
cli plugin install woocommerce --activate --quiet 2>/dev/null
cli plugin install wordpress-importer --activate --quiet 2>/dev/null
cli plugin activate "$PLUGIN_SLUG" --quiet 2>/dev/null

# A persistent object cache. Case 10 needs one, and case 09 measures extra cache modes when
# one is present, so without it two cases cover less than they claim to. The old Docker
# environment ran a redis container for exactly this.
#
# parallel-dev already gives each site its own WP_REDIS_DATABASE and WP_CACHE_KEY_SALT, so
# sites cannot share a keyspace.
if php -m 2>/dev/null | grep -qi '^redis$' && redis-cli ping >/dev/null 2>&1; then
    cli plugin install redis-cache --activate --quiet 2>/dev/null
    cli redis enable --quiet 2>/dev/null
else
    echo "  ! no redis PHP extension or no redis-server - cases 10 and 09 will cover less"
    echo "    fix with: pecl install redis && brew services restart php && brew services start redis"
fi

echo "==> WooCommerce settings (HPOS on, coming-soon off)"
cli option update woocommerce_custom_orders_table_enabled yes --quiet
cli option update woocommerce_feature_custom_order_tables_enabled yes --quiet
cli option update woocommerce_coming_soon no --quiet
cli option update woocommerce_store_pages_only no --quiet
cli wc tool run install_pages --user=1 --quiet 2>/dev/null

PRODUCTS=$(cli post list --post_type=product --post_status=publish --format=count 2>/dev/null | tr -dc '0-9')
if [ "${PRODUCTS:-0}" -lt 5 ]; then
    echo "==> importing WooCommerce sample products (have ${PRODUCTS:-0})"
    cli import "$SSPA_SITE_DIR/wp-content/plugins/woocommerce/sample-data/sample_products.xml" \
        --authors=create --quiet >/dev/null 2>&1
fi

echo "==> orders and posts"
cli eval '
if (count(wc_get_orders(array("limit" => -1, "return" => "ids"))) < 3) {
    foreach (get_posts(array("post_type" => "product", "numberposts" => 3)) as $prod) {
        $order = wc_create_order();
        $order->add_product(wc_get_product($prod->ID), 2);
        $order->set_address(array("first_name" => "Test", "last_name" => "Customer", "email" => "test@example.com"), "billing");
        $order->calculate_totals();
        $order->update_status("processing");
    }
}
$cat = get_term_by("name", "News", "category");
$cat_id = $cat ? $cat->term_id : (int) wp_insert_term("News", "category")["term_id"];
for ($i = (int) wp_count_posts("post")->publish; $i < 30; $i++) {
    wp_insert_post(array("post_title" => "Test post $i", "post_content" => str_repeat("Lorem ipsum dolor sit amet. ", 40), "post_status" => "publish", "post_category" => array($cat_id)));
}' >/dev/null 2>&1

echo "==> helper files"
cli eval 'SSPA_Helper_Files::ensure_installed();' >/dev/null 2>&1

echo
echo "site      $SSPA_SITE_URL"
echo "products  $(cli post list --post_type=product --post_status=publish --format=count 2>/dev/null)"
echo "orders    $(cli eval 'echo count(wc_get_orders(array("limit" => -1, "return" => "ids")));' 2>/dev/null)"
echo "posts     $(cli post list --post_type=post --post_status=publish --format=count 2>/dev/null)"
echo "HPOS      $(cli eval 'echo Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "on" : "OFF";' 2>/dev/null)"
echo "excimer   $(cli eval 'echo extension_loaded("excimer") ? "yes (WARNING: it segfaults php-fpm here - see .tests/README.md)" : "no - case 18 will FAIL; deliberate, see .tests/README.md";' 2>/dev/null)"
echo "obj cache $(cli eval 'echo wp_using_ext_object_cache() ? "persistent" : "NONE - case 10 cannot pass; see the redis note above";' 2>/dev/null)"
echo
echo "run the suite with: .tests/run-tests.sh"
