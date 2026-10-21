#!/usr/bin/env bash
# Cache performance tab against a real cache on its own native site.
#
#   .tests/cache-performance/run.sh enabler   # Cache Enabler from wordpress.org
#   .tests/cache-performance/run.sh nocache   # no page cache, a really slow shop page
#   .tests/cache-performance/run.sh nowoo     # a plain WordPress site without WooCommerce
#
# Each kind owns a retained parallel-dev site (tests-cache-enabler, tests-cache-nocache,
# tests-cache-nowoo).
# State is cleared on the way IN (the page cache, the previous check) and everything is
# left behind for inspection: the site, its cache files, the saved check and the browser
# evidence under .data/cache-performance/<kind>/.
set -uo pipefail
KIND="${1:?usage: run.sh enabler|nocache|nowoo}"
case "$KIND" in
    enabler) export SSPA_SCENARIO="${SSPA_CACHE_ENABLER_SCENARIO:-tests-cache-enabler}" ;;
    nocache) export SSPA_SCENARIO="${SSPA_CACHE_NOCACHE_SCENARIO:-tests-cache-nocache}" ;;
    nowoo) export SSPA_SCENARIO="${SSPA_CACHE_NOWOO_SCENARIO:-tests-cache-nowoo}" ;;
    *) echo "FAIL: unknown kind $KIND"; exit 1 ;;
esac
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/../env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    if [ "$KIND" = nowoo ]; then
        # A plain WordPress site: parallel-dev's own site, never WooCommerce.
        bash "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" > "$PLUGIN_DIR/.data/setup-$SSPA_SCENARIO.log" 2>&1 || { echo "FAIL: could not create $SSPA_SCENARIO"; exit 1; }
    else
        bash "$PLUGIN_DIR/.tests/setup-site.sh" > "$PLUGIN_DIR/.data/setup-$SSPA_SCENARIO.log" 2>&1 || { echo "FAIL: could not create $SSPA_SCENARIO"; exit 1; }
    fi
fi
link="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
[ -L "$link" ] || { echo 'FAIL: expected an isolated source symlink'; exit 1; }
ln -sfn "$PLUGIN_DIR" "$link" || exit 1
sspa_require_site || exit 1
sync_plugin || exit 1
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1
cli plugin activate "$PLUGIN_SLUG" --quiet || exit 1
if [ "$KIND" = nowoo ]; then
    if cli plugin is-active woocommerce >/dev/null 2>&1; then echo "FAIL: WooCommerce is active on $SSPA_SCENARIO"; exit 1; fi
else
    # HPOS on and coming-soon off: setup-site.sh sets both; re-assert them because a
    # WooCommerce reactivation turns HPOS off again.
    cli option update woocommerce_custom_orders_table_enabled yes --quiet || exit 1
    cli option update woocommerce_coming_soon no --quiet || exit 1
    cli option update woocommerce_store_pages_only no --quiet || exit 1
fi

# Genuine wordpress.org packages, downloaded once into the gitignored .data folder.
fetch_wporg() {
    local slug="$1" zip="$PLUGIN_DIR/.data/downloads/$1.zip"
    mkdir -p "$PLUGIN_DIR/.data/downloads"
    if [ ! -s "$zip" ]; then
        curl -sfL --max-time 120 -o "$zip" "https://downloads.wordpress.org/plugin/$slug.latest-stable.zip" || { echo "FAIL: could not download $slug from wordpress.org"; exit 1; }
    fi
    if ! cli plugin is-installed "$slug"; then
        cli plugin install "$zip" --quiet || { echo "FAIL: could not install $slug"; exit 1; }
    fi
}

cli option delete sspa_cache_delivery_report --quiet 2>/dev/null || true
cli option delete sspa_cache_delivery_pending --quiet 2>/dev/null || true

if [ "$KIND" = enabler ]; then
    # The first pass needs a theme that prints no basket refresh mechanism: a new site's
    # Twenty Twenty-Four puts the Mini-Cart block in its header, which correctly withholds
    # the empty-basket warning that assert-enabler.php expects. Neve prints none.
    theme_zip="$PLUGIN_DIR/.data/downloads/theme-neve.zip"
    mkdir -p "$PLUGIN_DIR/.data/downloads"
    [ -s "$theme_zip" ] || curl -sfL --max-time 120 -o "$theme_zip" "https://downloads.wordpress.org/theme/neve.latest-stable.zip" || { echo "FAIL: could not download neve from wordpress.org"; exit 1; }
    cli theme is-installed neve || cli theme install "$theme_zip" --quiet || { echo "FAIL: could not install neve"; exit 1; }
    cli theme activate neve --quiet || exit 1
    fetch_wporg cache-enabler
    cli plugin activate cache-enabler --quiet || exit 1
    cli config set WP_CACHE true --raw --anchor='if ( ! defined(' --placement=before --quiet || exit 1
    # php-fpm runs as another user; the cache directory must be writable by it.
    mkdir -p "$SSPA_SITE_DIR/wp-content/cache" && chmod 777 "$SSPA_SITE_DIR/wp-content/cache"
    cli cache-enabler clear --quiet || exit 1
    rm -f "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-slow-shop.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-cart-fragments.php"
    sleep 3 # php-fpm opcache revalidation
    expect='{"anonymous":"cached"}'
elif [ "$KIND" = nowoo ]; then
    cli option update show_avatars 0 --quiet || exit 1
    expect='{"anonymous":"not_cached","basket":"unknown","customer":"not_cached"}'
else
    if cli plugin is-active cache-enabler >/dev/null 2>&1; then cli plugin deactivate cache-enabler --quiet || exit 1; fi
    # The detection assertions activate these two; start each run without them.
    fetch_wporg advanced-dynamic-pricing-for-woocommerce
    if [ ! -d "$SSPA_SITE_DIR/wp-content/plugins/super-speedy-ajax-prices" ]; then
        target="$SSPA_SITE_DIR/wp-content/plugins/super-speedy-ajax-prices"
        mkdir -p "$target"
        revision=$(git -C "$WORKSPACE/super-speedy-ajax-prices" rev-parse HEAD)
        git -C "$WORKSPACE/super-speedy-ajax-prices" archive "$revision" | tar -x -C "$target" || exit 1
        while read -r key subpath; do
            subrev=$(git -C "$WORKSPACE/super-speedy-ajax-prices" ls-tree "$revision" "$subpath" | awk '{print $3}')
            mkdir -p "$target/$subpath"
            git -C "$WORKSPACE/super-speedy-ajax-prices/$subpath" archive "$subrev" | tar -x -C "$target/$subpath" || exit 1
        done < <(git -C "$WORKSPACE/super-speedy-ajax-prices" config --file .gitmodules --get-regexp path)
        git -C "$WORKSPACE/super-speedy-ajax-prices" rev-parse HEAD > "$target/.regression-source-commit"
    fi
    for plugin in super-speedy-ajax-prices advanced-dynamic-pricing-for-woocommerce; do
        if cli plugin is-active "$plugin" >/dev/null 2>&1; then cli plugin deactivate "$plugin" --quiet || exit 1; fi
    done
    cli option update woocommerce_default_customer_address base --quiet || exit 1
    # A real slow page: the shop template really waits, the profiler really measures it.
    ln -sfn "$PLUGIN_DIR/.tests/fixtures/slow-shop.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-slow-shop.php" || exit 1
    sleep 3 # php-fpm opcache revalidation
    cli eval-file "$HERE/analyse.php" || exit 1
    expect='{"anonymous":"not_cached","basket":"not_cached","customer":"not_cached"}'
fi

export SSPA_FLEET_OUTPUT="$PLUGIN_DIR/.data/cache-performance/$KIND"
mkdir -p "$SSPA_FLEET_OUTPUT"
export SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS" SSPA_CACHE_EXPECT="$expect"
node "$PLUGIN_DIR/.tests/browser/cache-performance.e2e.cjs" 2>&1 | tee "$SSPA_FLEET_OUTPUT/browser.log"
[ "${PIPESTATUS[0]}" -eq 0 ] || { echo "FAIL: cache performance browser journey on $SSPA_SCENARIO"; exit 1; }
cli eval-file "$HERE/assert-$KIND.php" 2>&1 | tee "$SSPA_FLEET_OUTPUT/assert.log"
if grep -q '^FAIL' "$SSPA_FLEET_OUTPUT/assert.log" || ! grep -q '^PASS' "$SSPA_FLEET_OUTPUT/assert.log"; then exit 1; fi
if [ "$KIND" = enabler ]; then
    # Second pass: WooCommerce's cart-fragments script on every page, a fresh page cache.
    ln -sfn "$PLUGIN_DIR/.tests/fixtures/cart-fragments.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-cart-fragments.php" || exit 1
    sleep 3 # php-fpm opcache revalidation
    cli cache-enabler clear --quiet || exit 1
    export SSPA_FLEET_OUTPUT="$PLUGIN_DIR/.data/cache-performance/enabler-fragments" SSPA_CACHE_EXPECT='{"anonymous":"cached","basket":"cached"}'
    mkdir -p "$SSPA_FLEET_OUTPUT"
    node "$PLUGIN_DIR/.tests/browser/cache-performance.e2e.cjs" 2>&1 | tee "$SSPA_FLEET_OUTPUT/browser.log"
    [ "${PIPESTATUS[0]}" -eq 0 ] || { echo "FAIL: cache performance browser journey with cart fragments on $SSPA_SCENARIO"; exit 1; }
    cli eval-file "$HERE/assert-enabler-fragments.php" 2>&1 | tee "$SSPA_FLEET_OUTPUT/assert.log"
    if grep -q '^FAIL' "$SSPA_FLEET_OUTPUT/assert.log" || ! grep -q '^PASS' "$SSPA_FLEET_OUTPUT/assert.log"; then exit 1; fi
fi
echo "Evidence: $SSPA_FLEET_OUTPUT (site $SSPA_SITE_URL)"
