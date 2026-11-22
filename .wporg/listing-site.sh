#!/usr/bin/env bash
# Prepare the retained listing site for the WordPress.org screenshots from the EXACT wp.org zip.
#
#   .wporg/listing-site.sh <super-speedy-performance-analysis-X.Y.Z-wporg.zip>
#
# Resets wporg-listing.super-speedy-performance-analysis.localhost:8081 on the way IN, installs
# WooCommerce (HPOS on, coming-soon off) with its sample products, then the wp.org zip. Nothing is
# torn down: the site is left for inspection.
set -euo pipefail
ZIP="$(realpath "${1:?usage: listing-site.sh <wporg zip>}")"
PD="$HOME/dev/super-speedy/tools/parallel-dev/bin"
. "$PD/lib.sh"
P=super-speedy-performance-analysis
SCENARIO=wporg-listing
SITE="$SITES_ROOT/$P/$SCENARIO"
SITE_URL="http://$SCENARIO.$P.localhost:$PORT"
if [ -d "$SITE" ]; then "$PD/reset-site.sh" "$P" "$SCENARIO" >/dev/null
else "$PD/create-site.sh" "$P" "$SCENARIO" >/dev/null; fi
cd "$SITE"
# create-site links the source checkout; this site must run only the wp.org archive.
rm -f "wp-content/plugins/$P"
ln -sfn "$MAIN_SITE/wp-content/plugins/woocommerce" wp-content/plugins/woocommerce
wp plugin activate woocommerce --quiet
wp option update woocommerce_custom_orders_table_enabled yes --quiet 2>/dev/null || true
wp option update woocommerce_coming_soon no --quiet 2>/dev/null || true
wp option update woocommerce_store_pages_only no --quiet 2>/dev/null || true
wp option update blogname "Demo Store" --quiet 2>/dev/null || true
wp plugin install wordpress-importer --activate --quiet
wp import wp-content/plugins/woocommerce/sample-data/sample_products.xml --authors=skip --quiet >/dev/null 2>&1 || true
# The parallel-dev request recorder would show up in the profiles as a must-use plugin.
rm -f wp-content/mu-plugins/sspa-e2e-observatory*.php
wp plugin install "$ZIP" --activate --quiet
echo "site: $SITE_URL"
echo "hpos: $(wp option get woocommerce_custom_orders_table_enabled)"
echo "products: $(wp post list --post_type=product --format=count)"
