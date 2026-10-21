#!/usr/bin/env bash
# Lead-generation offers against real measured evidence, each kind on its own retained
# parallel-dev site.
#
#   .tests/lead-gen/run.sh slow     # 120,000 products and 250,000 HPOS orders, no object cache:
#                                   # S1/S3/S5, Q1-Q3, volume, dismissal, suppression, exports,
#                                   # then F1/F3 with HUSKY from wordpress.org
#   .tests/lead-gen/run.sh wpai     # small fast store, WP All Import from wordpress.org with a
#                                   # real import: silence below thresholds, I1, Imports tab I2,
#                                   # then R2 on a genuinely slow, mostly unattributed page
#   .tests/lead-gen/run.sh imports  # Imports tab states (none, traffic I3 + S8, Lite, Pro)
#
# Sites: tests-lead-gen, tests-lead-gen-wpai, tests-lead-gen-imports. State is cleared on the
# way IN and everything is left behind for inspection, with evidence under
# .data/lead-gen/<kind>/.
set -uo pipefail
KIND="${1:?usage: run.sh slow|wpai|imports}"
case "$KIND" in
    slow) export SSPA_SCENARIO="${SSPA_LEAD_GEN_SLOW_SCENARIO:-tests-lead-gen}" ;;
    wpai) export SSPA_SCENARIO="${SSPA_LEAD_GEN_WPAI_SCENARIO:-tests-lead-gen-wpai}" ;;
    imports) export SSPA_SCENARIO="${SSPA_LEAD_GEN_IMPORTS_SCENARIO:-tests-lead-gen-imports}" ;;
    *) echo "FAIL: unknown kind $KIND"; exit 1 ;;
esac
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/../env.sh"
OUT="$PLUGIN_DIR/.data/lead-gen/$KIND"
mkdir -p "$OUT"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$PLUGIN_DIR/.tests/setup-site.sh" > "$OUT/setup.log" 2>&1 || { echo "FAIL: could not create $SSPA_SCENARIO"; exit 1; }
fi
"$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" "SSPA lead-gen offers regression ($KIND, cases 131 to 133)" || exit 1
link="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
[ -L "$link" ] || { echo 'FAIL: expected an isolated source symlink'; exit 1; }
ln -sfn "$PLUGIN_DIR" "$link" || exit 1
sspa_require_site || exit 1
sync_plugin || exit 1
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1
cli plugin activate "$PLUGIN_SLUG" woocommerce --quiet || exit 1
# HPOS on and coming-soon off, re-asserted because a WooCommerce reactivation turns HPOS off.
cli option update woocommerce_custom_orders_table_enabled yes --quiet || exit 1
cli option update woocommerce_coming_soon no --quiet || exit 1
cli option update woocommerce_store_pages_only no --quiet || exit 1
cli user meta delete 1 sspa_dismissed_offers --quiet 2>/dev/null || true
export SSPA_FLEET_OUTPUT="$OUT" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"

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
deactivate_if_active() {
    for plugin in "$@"; do
        if cli plugin is-active "$plugin" >/dev/null 2>&1; then cli plugin deactivate "$plugin" --quiet || exit 1; fi
    done
}
assert() {
    cli eval-file "$HERE/assert-$1.php" "${@:2}" 2>&1 | tee "$OUT/assert-$1${2:+-$2}.log"
    if grep -q '^FAIL' "$OUT/assert-$1${2:+-$2}.log" || ! grep -q '^PASS' "$OUT/assert-$1${2:+-$2}.log"; then FAILED=1; fi
}
FAILED=0
PAGES="home shop product-cat product-single search-many search-zero admin-products admin-edit-product admin-new-product admin-orders admin-orders-search"

if [ "$KIND" = slow ]; then
    deactivate_if_active scalability-pro woocommerce-products-filter
    # Many real stores run without a persistent object cache; with one, WordPress serves the
    # catalogue queries from memory and the database work this case measures never runs.
    if [ -f "$SSPA_SITE_DIR/wp-content/object-cache.php" ]; then cli redis disable --quiet 2>/dev/null || rm -f "$SSPA_SITE_DIR/wp-content/object-cache.php"; fi
    deactivate_if_active redis-cache
    cli eval-file "$HERE/seed-volume.php" 120000 250000 || exit 1
    cli eval-file "$HERE/analyse.php" $PAGES | tee "$OUT/analyse.log" || exit 1
    grep -q '^FAIL' "$OUT/analyse.log" && exit 1
    assert slow
    # F1/F3: a real filter plugin from wordpress.org, and a fresh analysis with it active.
    fetch_wporg woocommerce-products-filter
    cli plugin activate woocommerce-products-filter --quiet || exit 1
    cli eval-file "$HERE/analyse.php" shop product-cat | tee "$OUT/analyse-filters.log" || exit 1
    grep -q '^FAIL' "$OUT/analyse-filters.log" && exit 1
    assert filters
    cli plugin deactivate woocommerce-products-filter --quiet || exit 1
fi

if [ "$KIND" = wpai ]; then
    fetch_wporg wp-all-import
    cli plugin activate wp-all-import --quiet || exit 1
    # Clear the previous run's import on the way in, then run a fresh one through the wizard.
    cli eval 'global $wpdb; foreach ($wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}pmxi_posts") as $id) { wp_delete_post((int) $id, true); } foreach (array("pmxi_posts", "pmxi_history", "pmxi_imports", "pmxi_files", "pmxi_hash") as $t) { $wpdb->query("DELETE FROM {$wpdb->prefix}$t"); }' || exit 1
    python3 - "$OUT/wpai-posts.csv" <<'PY'
import csv, sys
with open(sys.argv[1], 'w', newline='') as f:
    w = csv.writer(f); w.writerow(['title', 'content', 'ref'])
    for i in range(1, 301): w.writerow(['Imported item %d' % i, 'Imported body text. ' * 20, 'REF-%05d' % i])
PY
    node "$HERE/wpai-import.cjs" "$OUT/wpai-posts.csv" 2>&1 | tee "$OUT/wpai-import.log"
    [ "${PIPESTATUS[0]}" -eq 0 ] || { echo "FAIL: WP All Import journey"; exit 1; }
    cli eval-file "$HERE/analyse.php" $PAGES | tee "$OUT/analyse.log" || exit 1
    grep -q '^FAIL' "$OUT/analyse.log" && exit 1
    assert wpai
    # R2's positive case: a genuinely slow page whose generation is mostly unattributed.
    mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins" || exit 1
    ln -sfn "$PLUGIN_DIR/.tests/fixtures/lead-gen-slow-render.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-lead-gen-slow-render.php" || exit 1
    sleep 3 # php-fpm opcache revalidation
    assert slow-render
fi

if [ "$KIND" = imports ]; then
    deactivate_if_active super-speedy-imports super-speedy-imports-lite scalability-pro wp-all-import
    rm -f "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-lead-gen-slow-endpoint.php"
    # Clear the previous run's Traffic collections on the way in: "none" means nothing measured.
    cli eval '$a = SSPA_Traffic_Collection::active(); if ($a) { SSPA_Traffic_Collection::stop((int) $a["id"], true); } while ($l = SSPA_Traffic_Collection::latest()) { $r = SSPA_Traffic_Collection::delete((int) $l["id"]); if (is_wp_error($r)) { WP_CLI::error($r->get_error_message()); } }' || exit 1
    cli eval-file "$HERE/analyse.php" home | tee "$OUT/analyse.log" || exit 1
    grep -q '^FAIL' "$OUT/analyse.log" && exit 1
    assert imports none

    # I3 and S8: a Traffic collection measures a real WooCommerce product import and a real
    # slow admin-ajax endpoint.
    ln -sfn "$PLUGIN_DIR/.tests/fixtures/lead-gen-slow-endpoint.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-lead-gen-slow-endpoint.php" || exit 1
    sleep 3 # php-fpm opcache revalidation
    cli eval 'global $wpdb; foreach ($wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", "_sku", "LGWC-%")) as $id) { wp_delete_post((int) $id, true); }' || exit 1
    python3 - "$OUT/woocommerce-products.csv" <<'PY'
import csv, sys
with open(sys.argv[1], 'w', newline='') as f:
    w = csv.writer(f); w.writerow(['Type', 'SKU', 'Name', 'Published', 'Regular price', 'Description'])
    for i in range(1, 201): w.writerow(['simple', 'LGWC-%05d' % i, 'Imported product %d' % i, '1', '%d.00' % (10 + i % 50), 'Imported product body. ' * 10])
PY
    cli eval '$r = SSPA_Report::start_endpoint_evidence(); if (is_wp_error($r)) { WP_CLI::error($r->get_error_message()); } echo "collection " . $r["collection"]["id"] . "\n";' || exit 1
    node "$HERE/woocommerce-import.cjs" "$OUT/woocommerce-products.csv" 2>&1 | tee "$OUT/woocommerce-import.log"
    [ "${PIPESTATUS[0]}" -eq 0 ] || { echo "FAIL: WooCommerce import journey"; exit 1; }
    # Wait on these requests only: a bare `wait` also waits on the mail sink that
    # local-services.sh starts in the background of this shell, and never returns.
    requests=()
    for i in $(seq 1 60); do
        curl -s -o /dev/null --max-time 30 -d 'action=lead_gen_fixture_slow_endpoint' "$SSPA_SITE_URL/wp-admin/admin-ajax.php" &
        requests+=("$!")
        if [ $((i % 6)) -eq 0 ]; then wait "${requests[@]}"; requests=(); fi
    done
    [ "${#requests[@]}" -eq 0 ] || wait "${requests[@]}"
    cli eval '$a = SSPA_Traffic_Collection::active(); if ($a) { SSPA_Traffic_Collection::stop((int) $a["id"], true); }' || exit 1
    assert imports traffic

    fetch_wporg super-speedy-imports-lite
    cli plugin activate super-speedy-imports-lite --quiet || exit 1
    assert imports lite
    cli plugin deactivate super-speedy-imports-lite --quiet || exit 1

    # Pro: the current super-speedy-imports source, frozen at its commit like Scalability Pro.
    if [ ! -d "$SSPA_SITE_DIR/wp-content/plugins/super-speedy-imports" ]; then
        target="$SSPA_SITE_DIR/wp-content/plugins/super-speedy-imports"
        revision=$(git -C "$WORKSPACE/super-speedy-imports" rev-parse HEAD)
        mkdir -p "$target"
        git -C "$WORKSPACE/super-speedy-imports" archive "$revision" | tar -x -C "$target" || exit 1
        while read -r key subpath; do
            subrev=$(git -C "$WORKSPACE/super-speedy-imports" ls-tree "$revision" "$subpath" | awk '{print $3}')
            mkdir -p "$target/$subpath"
            git -C "$WORKSPACE/super-speedy-imports/$subpath" archive "$subrev" | tar -x -C "$target/$subpath" || exit 1
        done < <(git -C "$WORKSPACE/super-speedy-imports" config --file .gitmodules --get-regexp path 2>/dev/null)
        printf '%s\n' "$revision" > "$target/.regression-source-commit"
    fi
    cli plugin activate super-speedy-imports --quiet || exit 1
    assert imports pro
    cli plugin deactivate super-speedy-imports --quiet || exit 1
fi
exit $FAILED
