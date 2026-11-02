#!/usr/bin/env bash
# New-visitor cache blockers on their own retained native site (tests-cache-blockers).
#
#   .tests/new-visitor-cache/run.sh attribution   # case 151: runtime Set-Cookie attribution and the finding
#   .tests/new-visitor-cache/run.sh check         # case 152: cache check, new visitor again and returning visitor
#   .tests/new-visitor-cache/run.sh parameters    # case 153: the ad-click and campaign parameter check
#   .tests/new-visitor-cache/run.sh journey       # case 154: the parameter check button in a real browser
#   .tests/new-visitor-cache/run.sh all
#
# Fixtures are installed on the way IN and left behind for inspection:
#   plugins zz-tracking-cookie (sets _fbp on init for cookie-less requests) and zz-click-cookie (sets _fbc
#   only when fbclid is present), and .tests/fixtures/zz-page-cache.php as wp-content/advanced-cache.php
#   with wp-content/zz-page-cache.json: utm_* ignored by the cache key, gclid/gbraid/wbraid bypass the
#   cache, every other parameter is part of the key, and a response carrying Set-Cookie is never stored.
# The page cache's stored pages and the previous checks are cleared on the way in, never at the end.
# Override the site with SSPA_NEW_VISITOR_SCENARIO. Evidence: .data/new-visitor-cache/<part>/.
set -uo pipefail
PART="${1:-all}"
export SSPA_SCENARIO="${SSPA_NEW_VISITOR_SCENARIO:-tests-cache-blockers}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/../env.sh"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ] || ! cli plugin is-active woocommerce >/dev/null 2>&1; then
    mkdir -p "$PLUGIN_DIR/.data"
    bash "$PLUGIN_DIR/.tests/setup-site.sh" > "$PLUGIN_DIR/.data/setup-$SSPA_SCENARIO.log" 2>&1 || { echo "FAIL: could not set up $SSPA_SCENARIO"; exit 1; }
fi
if [ "$SSPA_SCENARIO" = tests-cache-blockers ]; then
    "$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" "SSPA new-visitor cache blockers regression (cases 151 to 154)" || exit 1
fi
link="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
[ -L "$link" ] || { echo 'FAIL: expected an isolated source symlink'; exit 1; }
ln -sfn "$PLUGIN_DIR" "$link" || exit 1
sspa_require_site || exit 1
sync_plugin || exit 1
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1
cli plugin activate "$PLUGIN_SLUG" --quiet || exit 1
cli eval '$r = SSPA_Helper_Files::enable_profiler(); if (is_wp_error($r)) { WP_CLI::error($r->get_error_message()); }' || exit 1
cli option update woocommerce_custom_orders_table_enabled yes --quiet || exit 1
cli option update woocommerce_coming_soon no --quiet || exit 1
cli option update woocommerce_store_pages_only no --quiet || exit 1

# Fixtures, copied (not symlinked) so they classify exactly as installed third-party plugins do.
content="$SSPA_SITE_DIR/wp-content"
for fixture in zz-tracking-cookie zz-click-cookie; do
    rm -rf "${content:?}/plugins/$fixture"
    cp -r "$PLUGIN_DIR/.tests/fixtures/new-visitor-cookies/$fixture" "$content/plugins/$fixture" || exit 1
done
cli plugin activate zz-tracking-cookie zz-click-cookie --quiet || exit 1
cp "$PLUGIN_DIR/.tests/fixtures/zz-page-cache.php" "$content/advanced-cache.php" || exit 1
cat > "$content/zz-page-cache.json" <<'JSON'
{"enabled": true, "ignore_params": ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content"], "bypass_params": ["gclid", "gbraid", "wbraid"], "ttl": 600}
JSON
if ! grep -q "define( *'WP_CACHE', *true" "$SSPA_SITE_DIR/wp-config.php"; then
    cli config set WP_CACHE true --raw --anchor='if ( ! defined(' --placement=before --quiet || exit 1
fi
# php-fpm runs as another user; the cache directory must be writable by it. Cleared on the way in.
mkdir -p "$content/cache/zz-page-cache" && chmod 777 "$content/cache" "$content/cache/zz-page-cache"
rm -f "$content/cache/zz-page-cache/"*.html
cli option delete sspa_cache_delivery_report sspa_cache_delivery_pending sspa_parameter_check_report sspa_parameter_check_pending --quiet 2>/dev/null || true
sleep 3 # php-fpm opcache revalidation

run_part() {
    local part="$1" out="$PLUGIN_DIR/.data/new-visitor-cache/$1"
    mkdir -p "$out"
    rm -f "$content/cache/zz-page-cache/"*.html
    if [ "$part" = journey ]; then
        export SSPA_FLEET_OUTPUT="$out" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
        node "$PLUGIN_DIR/.tests/browser/parameter-check.e2e.cjs" 2>&1 | tee "$out/browser.log"
        [ "${PIPESTATUS[0]}" -eq 0 ] || { echo "FAIL: parameter check browser journey on $SSPA_SCENARIO"; return 1; }
        return 0
    fi
    SSPA_NV_OUTPUT="$out" cli eval-file "$HERE/assert-$part.php" 2>&1 | tee "$out/assert.log"
    if grep -q '^FAIL' "$out/assert.log" || ! grep -q '^PASS' "$out/assert.log"; then return 1; fi
    return 0
}

status=0
case "$PART" in
    all) for p in attribution check parameters journey; do run_part "$p" || status=1; done ;;
    attribution|check|parameters|journey) run_part "$PART" || status=1 ;;
    *) echo "FAIL: unknown part $PART"; exit 1 ;;
esac
echo "Evidence: $PLUGIN_DIR/.data/new-visitor-cache/ (site $SSPA_SITE_URL)"
exit $status
