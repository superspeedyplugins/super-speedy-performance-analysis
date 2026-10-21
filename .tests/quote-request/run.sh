#!/usr/bin/env bash
# "Request a fix quote" against the real receiver, on two retained parallel-dev sites.
#
#   bash .tests/quote-request/run.sh          # the server-path case, then the browser journey
#   bash .tests/quote-request/run.sh case     # assert-quote.php only
#   bash .tests/quote-request/run.sh journey  # the browser journey only
#
# Sites:
#   tests-quote           Performance Analysis (override with SSPA_QUOTE_SCENARIO)
#   tests-quote-receiver  the real Super Speedy Contact code, frozen at its commit with git archive
#                         (override with SSPA_QUOTE_RECEIVER_SCENARIO)
#
# PA reaches the receiver because its wp-config.php defines SSPA_QUOTE_REQUEST_TEST_ENDPOINT, a
# test-only constant read by SSPA_Quote_Request::endpoint(). Without it PA posts to
# superspeedyplugins.com; with it, nothing in a run can reach a live site, and the regression
# boundary refuses every external host anyway. State is cleared on the way IN and left behind;
# evidence goes to .data/quote-request/.
set -uo pipefail
PART="${1:-all}"
export SSPA_SCENARIO="${SSPA_QUOTE_SCENARIO:-tests-quote}"
RECEIVER_SCENARIO="${SSPA_QUOTE_RECEIVER_SCENARIO:-tests-quote-receiver}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/../env.sh"
OUT="$PLUGIN_DIR/.data/quote-request"
mkdir -p "$OUT/journey"
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    bash "$PLUGIN_DIR/.tests/setup-site.sh" > "$OUT/setup.log" 2>&1 || { echo "FAIL: could not create $SSPA_SCENARIO"; exit 1; }
fi

# The receiver: a plain WordPress with Super Speedy Contact, created once and kept.
RECEIVER_DIR=$(pd_site_dir "$PLUGIN_SLUG" "$RECEIVER_SCENARIO")
RECEIVER_URL=$(pd_site_url "$PLUGIN_SLUG" "$RECEIVER_SCENARIO")
case "$(realpath -m "$RECEIVER_DIR")" in "$(realpath -m "$SITES_ROOT")"/*) ;; *) echo "FAIL: refusing non-isolated receiver $RECEIVER_DIR"; exit 1 ;; esac
if [ ! -f "$RECEIVER_DIR/wp-config.php" ]; then
    # Inside run-tests.sh, PATH carries the suite's wp shim, which forces --path to the PA site.
    # create-site.sh must install the receiver with the real wp, or its `wp core install` lands on
    # the PA site and the new receiver is never installed.
    real_wp="${SSPA_TEST_REAL_WP:-$(type -P wp)}"
    PATH="$(dirname "$real_wp"):$PATH" "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" "$PLUGIN_SLUG" "$RECEIVER_SCENARIO" > "$OUT/receiver-setup.log" 2>&1 || { echo "FAIL: could not create $RECEIVER_SCENARIO"; exit 1; }
fi
# Keep only this runner's own default sites; a branch suite's override copy stays temporary.
case "$SSPA_SCENARIO" in
    tests-quote) "$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" "SSPA quote request regression (case 136, browser journey)" || exit 1 ;;
esac
case "$RECEIVER_SCENARIO" in
    tests-quote-receiver) "$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$RECEIVER_SCENARIO" "SSPA quote request receiver: real Super Speedy Contact for case 136" || exit 1 ;;
esac
# The real wp, never the PA site's shim (which adds the PA site's own --path).
rwp() { "${SSPA_TEST_REAL_WP:-$(type -P wp)}" "$@" --path="$RECEIVER_DIR" --url="$RECEIVER_URL"; }
CONTACT_REPO="$WORKSPACE/superspeedyplugins.com/super-speedy-contact"
CONTACT_DIR="$RECEIVER_DIR/wp-content/plugins/super-speedy-contact"
if [ ! -d "$CONTACT_DIR" ]; then
    revision=$(git -C "$CONTACT_REPO" rev-parse HEAD) || exit 1
    mkdir -p "$CONTACT_DIR"
    git -C "$CONTACT_REPO" archive "$revision" | tar -x -C "$CONTACT_DIR" || exit 1
    printf '%s\n' "$revision" > "$CONTACT_DIR/.regression-source-commit"
fi
echo "Receiver: $RECEIVER_URL (Super Speedy Contact $(sed -n 's/^ \* Version: //p' "$CONTACT_DIR/super-speedy-contact.php") at $(cat "$CONTACT_DIR/.regression-source-commit"))"
mkdir -p "$RECEIVER_DIR/wp-content/mu-plugins" || exit 1
ln -sfn "$PLUGIN_DIR/.tests/fixtures/quote-receiver.php" "$RECEIVER_DIR/wp-content/mu-plugins/sspa-quote-receiver.php" || exit 1
rwp plugin activate super-speedy-contact --quiet || exit 1
rwp rewrite structure '/%postname%/' --quiet || exit 1
# Cleared on the way in: no leads, no rate-limit windows, no captured mail, no test knobs.
rwp eval 'foreach (get_posts(array("post_type" => "ssp_contact_lead", "post_status" => "any", "numberposts" => -1, "fields" => "ids")) as $id) { wp_delete_post($id, true); } global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"\\_transient\\_%sspc\\_rl\\_%\" OR option_name LIKE \"\\_transient\\_timeout\\_%sspc\\_rl\\_%\""); wp_cache_flush(); delete_option("sspa_test_receiver_mail"); delete_option("sspa_test_receiver_limits"); delete_option("sspa_test_receiver_fail_storage");' || exit 1

link="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
[ -L "$link" ] || { echo 'FAIL: expected an isolated source symlink'; exit 1; }
ln -sfn "$PLUGIN_DIR" "$link" || exit 1
sspa_require_site || exit 1
sync_plugin || exit 1
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1
cli plugin activate "$PLUGIN_SLUG" woocommerce --quiet || exit 1
# A kept site is repointed between checkouts, which leaves the profiler helpers generated for
# the old path (health "stale", profiler off). Re-install them for this checkout, as setup does.
cli eval '$r = SSPA_Helper_Files::enable_profiler(); if (is_wp_error($r)) { WP_CLI::error($r->get_error_message()); }' || exit 1
cli option update woocommerce_custom_orders_table_enabled yes --quiet || exit 1
cli option update woocommerce_coming_soon no --quiet || exit 1
cli option update woocommerce_store_pages_only no --quiet || exit 1
export SSPA_QUOTE_ENDPOINT="$RECEIVER_URL/wp-json/ssp-contact/v1/quote-request"
cli config set SSPA_QUOTE_REQUEST_TEST_ENDPOINT "$SSPA_QUOTE_ENDPOINT" --type=constant --anchor="if ( ! defined(" --placement=before --quiet || exit 1
mkdir -p "$SSPA_SITE_DIR/wp-content/mu-plugins" || exit 1
ln -sfn "$PLUGIN_DIR/.tests/fixtures/quote-http-observer.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-quote-http-observer.php" || exit 1
ln -sfn "$PLUGIN_DIR/.tests/fixtures/quote-raw-stream.php" "$SSPA_SITE_DIR/wp-content/mu-plugins/sspa-quote-raw-stream.php" || exit 1
# The static file the raw-stream fixture reads. Without it every read is a WordPress 404 and the
# 1,700-read page times out (504) instead of producing a report over the receiver's limit.
raw_static="$SSPA_SITE_DIR/wp-content/uploads/quote-raw/customer@example.com/98765"
mkdir -p "$raw_static" && printf 'raw stream fixture body' > "$raw_static/report.txt" || exit 1
cli eval 'delete_option("sspa_test_quote_log"); delete_user_meta(1, "sspa_dismissed_offers");' || exit 1
sleep 3 # php-fpm opcache revalidation after a wp-config or MU fixture change
export SSPA_QUOTE_RECEIVER_DIR="$RECEIVER_DIR" SSPA_QUOTE_RECEIVER_URL="$RECEIVER_URL"
export SSPA_FLEET_OUTPUT="$OUT/journey" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
FAILED=0

if [ "$PART" = all ] || [ "$PART" = case ]; then
    cli eval-file "$PLUGIN_DIR/.tests/fixtures/reset-retained-plugins.php" > /dev/null || exit 1
    timeout 1800 "$SSPA_TEST_REAL_WP" eval-file "$HERE/assert-quote.php" --path="$SSPA_SITE_DIR" --url="$SSPA_SITE_URL" > "$OUT/case.log" 2>&1
    grep -E '^(PASS|FAIL|INFO)' "$OUT/case.log"
    if grep -q '^FAIL' "$OUT/case.log" || ! grep -q '^PASS' "$OUT/case.log"; then FAILED=1; fi
    # The transport-error step points the constant elsewhere; put the receiver back whatever happened.
    cli config set SSPA_QUOTE_REQUEST_TEST_ENDPOINT "$SSPA_QUOTE_ENDPOINT" --type=constant --anchor="if ( ! defined(" --placement=before --quiet || exit 1
    sleep 3
fi

if [ "$PART" = all ] || [ "$PART" = journey ]; then
    # The journey sends one request from a fresh analysis; give it fresh rate-limit windows.
    rwp eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"\\_transient\\_%sspc\\_rl\\_%\" OR option_name LIKE \"\\_transient\\_timeout\\_%sspc\\_rl\\_%\""); wp_cache_flush(); delete_option("sspa_test_receiver_limits"); delete_option("sspa_test_receiver_fail_storage");' || exit 1
    timeout 1800 node "$HERE/journey.e2e.cjs" > "$OUT/journey.log" 2>&1
    status=$?
    cat "$OUT/journey.log"
    [ "$status" -eq 0 ] || { echo "FAIL: browser journey exited $status"; FAILED=1; }
fi
exit $FAILED
