#!/usr/bin/env bash
#
# The update watchdog (Pro), end to end, with no browser.
#
#   .tests/watchdog/run.sh
#
# Own site: tests-update-watchdog.super-speedy-performance-analysis.localhost:8081 (override with
# SSPA_SCENARIO). Created by .tests/setup-site.sh; this script clears the previous run's watchdog
# state and recorded mail on the way IN and leaves everything in place at the end.
#
# A fixture plugin is built into zips of two versions here. Each journey takes a real analysis,
# updates the fixture with WordPress's own Plugin_Upgrader, then lets WP-Cron (WP-CLI's cron
# runner, no browser) run the watchdog to the end. Mail is recorded by a test MU plugin hooked on
# pre_wp_mail and still delivered to the site's local SMTP sink; nothing leaves the machine.
#
#   1. slow:  1.1.0 makes the shop page 300 ms slower -> one event, one complete watchdog check
#             (front end, wp-admin unattended, logged-in customer, checkout test) and exactly one
#             email naming the plugin, both versions, the shop page and a difference >= 250 ms.
#   2. fast:  the same update without the slowdown -> no email.
#   3. batch: two plugin updates -> one event, one check; with the checkout setting off the check
#             runs no checkout test.
#   3b. run-watchdog-now looped by an agent finishes a check without WP-Cron.
#   4. noisy: only the first measurement of the shop page is slow -> re-measured, no email.
#   1b. the slow update's alert in a browser: the Compare link, the History badge, the Markdown
#       link and the settings.
#   5. theme and core: a Theme_Upgrader update and a core version change are recorded.

set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../.." && pwd)"
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-update-watchdog}"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/.tests/env.sh"
set +e
sspa_require_site || exit 1
sync_plugin || exit 1
# shellcheck source=/dev/null
source "$PLUGIN_DIR/.tests/local-services.sh" || exit 1

FAILS=0
PASSES=0
pass() { echo "PASS: $*"; PASSES=$((PASSES + 1)); }
fail() { echo "FAIL: $*"; FAILS=$((FAILS + 1)); }
check() { if [ "$1" = "$2" ]; then pass "$3"; else fail "$3 (expected '$2', got '$1')"; fi; }
h() { cli eval-file "$HERE/helper.php" "$@" 2>/dev/null | tail -1; }
json() { php -r '$d = json_decode(stream_get_contents(STDIN), true); '"$1"; }

SITE="$SSPA_SITE_DIR"
DATA="$PLUGIN_DIR/.data/watchdog"
rm -rf "$DATA"; mkdir -p "$DATA/zips"
echo "=== update watchdog on $SSPA_SITE_URL ==="

# ------------------------------------------------------------------ fixtures
build_zip() { # <fixture> <version> <mode> <zip>
    local stage="$DATA/stage/$1-$2-$3"
    rm -rf "$stage"; mkdir -p "$stage"
    cp -r "$HERE/fixtures/$1" "$stage/$1"
    find "$stage/$1" -type f -exec sed -i "s/__VERSION__/$2/; s/__MODE__/$3/" {} +
    (cd "$stage" && zip -qr "$4" "$1")
}
for v in "1.0.0 fast" "1.1.0 slow" "1.1.0 fast" "1.1.0 noisy"; do
    set -- $v
    build_zip sspa-wd-fixture "$1" "$2" "$DATA/zips/sspa-wd-fixture-$1-$2.zip"
done
build_zip sspa-wd-second 1.0.0 fast "$DATA/zips/sspa-wd-second-1.0.0.zip"
build_zip sspa-wd-second 1.1.0 fast "$DATA/zips/sspa-wd-second-1.1.0.zip"
build_zip sspa-wd-theme 1.0.0 fast "$DATA/zips/sspa-wd-theme-1.0.0.zip"
build_zip sspa-wd-theme 1.1.0 fast "$DATA/zips/sspa-wd-theme-1.1.0.zip"
# The upgrader deletes the package after unpacking it: hand it a copy.
pkg() { cp "$DATA/zips/$1" "$DATA/pkg-$1"; echo "$DATA/pkg-$1"; }

mkdir -p "$SITE/wp-content/mu-plugins"
cp "$PLUGIN_DIR/.tests/fixtures/watchdog-mail-recorder.php" "$SITE/wp-content/mu-plugins/zz-sspa-test-watchdog-mail.php"
cli option update woocommerce_coming_soon no >/dev/null 2>&1
cli option update woocommerce_store_pages_only no >/dev/null 2>&1
cli sspa profiler enable >/dev/null 2>&1
# Other journeys on a shared or reused site leave the checkout page as they last needed it (the
# checkout-flow journey ends on an unsupported page). The checkout test needs WooCommerce's block.
cli eval "wp_update_post(array('ID' => (int) get_option('woocommerce_checkout_page_id'), 'post_content' => '<!-- wp:woocommerce/checkout /-->')); wp_cache_flush();" >/dev/null 2>&1
sleep 3 # php-fpm revalidates changed files every 2 s

PAGES="baseline,home,shop,admin-dashboard,customer-shop"

# Run the watchdog the way WP-Cron does, with no browser: the scheduled event, then every due
# event (the run controller's batches and the watchdog's own steps) until the check is over.
cron_watchdog() {
    local i
    cli cron event run sspa_watchdog_run >/dev/null 2>&1
    for i in $(seq 1 240); do
        [ "$(h busy)" = "idle" ] && return 0
        cli cron event run --due-now >/dev/null 2>&1
        sleep 3
    done
    echo "    (watchdog still running after the time limit)"
    return 1
}

fresh() { # <checkout on|off> - clear on the way in, v1 of the fixture in place
    h reset >/dev/null
    h settings "{\"enabled\":true,\"checkout\":$1,\"threshold_percent\":25,\"threshold_ms\":150}" >/dev/null
    h place-plugin sspa-wd-fixture "$DATA/zips/sspa-wd-fixture-1.0.0-fast.zip" >/dev/null
    h place-plugin sspa-wd-second "$DATA/zips/sspa-wd-second-1.0.0.zip" >/dev/null
    cli option update sspa_wd_noisy_remaining 0 >/dev/null 2>&1
    sleep 3
}

alerts() { h mail "X-SSPA-Watchdog:"; }

# ------------------------------------------------------------------ 1. slow update
echo "--- 1. an update that slows the shop page"
fresh true
BASE=$(h analysis baseline "$PAGES")
check "${BASE#* }" "done" "pre-update full analysis of $PAGES completes (run ${BASE%% *})"
CBASE=$(h analysis checkout)
check "${CBASE#* }" "done" "pre-update checkout test completes (run ${CBASE%% *})"
h reset >/dev/null
h settings '{"enabled":true,"checkout":true}' >/dev/null
BEFORE_MAX=$(h max-run)
check "$(h update-plugin sspa-wd-fixture 1.1.0 "$(pkg sspa-wd-fixture-1.1.0-slow.zip)")" "updated 1.1.0" "Plugin_Upgrader updates the fixture 1.0.0 -> 1.1.0"
read -r N NEXT <<<"$(h events sspa_watchdog_run)"
check "$N" "1" "the update schedules exactly one watchdog event"
[ "${NEXT:--1}" -ge 540 ] && [ "${NEXT:--1}" -le 600 ] && pass "about 10 minutes after the update (${NEXT}s)" || fail "event due in ${NEXT}s, expected about 600"
cron_watchdog && pass "WP-Cron completes the watchdog check with no browser" || fail "the watchdog check did not complete"
RUNS=$(h runs-since "$BEFORE_MAX")
echo "    runs: $RUNS"
PAGES_RUN=$(printf '%s' "$RUNS" | json 'foreach ($d as $r) { if ("spot" === $r["run_type"] && "watchdog" === $r["trigger_source"]) { echo $r["id"]; break; } }')
CK_RUNS=$(printf '%s' "$RUNS" | json 'echo count(array_filter($d, function ($r) { return "checkout" === $r["run_type"] && "watchdog" === $r["trigger_source"] && "done" === $r["status"]; }));')
SPOTS=$(printf '%s' "$RUNS" | json 'echo count(array_filter($d, function ($r) { return "spot" === $r["run_type"] && "watchdog" === $r["trigger_source"]; }));')
[ -n "$PAGES_RUN" ] && pass "the watchdog measured the pages (run $PAGES_RUN, trigger watchdog)" || fail "no watchdog page run"
check "$SPOTS" "2" "the slower page was measured a second time (one page run, one re-measurement)"
[ "${CK_RUNS:-0}" -ge 1 ] && pass "the checkout test ran in the watchdog check ($CK_RUNS run(s))" || fail "no completed watchdog checkout test"
if [ -n "$PAGES_RUN" ]; then
    check "$(h profile "$PAGES_RUN" admin-dashboard | cut -d' ' -f1,2)" "200 admin" "wp-admin measured unattended as the administrator"
    check "$(h profile "$PAGES_RUN" customer-shop | cut -d' ' -f1,2)" "200 customer" "the shop measured as a logged-in customer"
    check "$(h profile "$PAGES_RUN" home | cut -d' ' -f1,2)" "200 anon" "the home page measured as a visitor"
fi
MAIL=$(alerts)
check "$(printf '%s' "$MAIL" | json 'echo count((array) $d);')" "1" "exactly one alert email"
SUBJECT=$(printf '%s' "$MAIL" | json 'echo $d[0]["subject"] ?? "";')
BODY=$(printf '%s' "$MAIL" | json 'echo $d[0]["message"] ?? "";')
echo "    subject: $SUBJECT"
printf '%s\n' "$BODY" > "$DATA/alert-email.txt"
case "$BODY" in *"SSPA Watchdog Fixture 1.0.0 -> 1.1.0"*) pass "the email names the plugin and both versions" ;; *) fail "plugin and versions missing from the email" ;; esac
case "$SUBJECT" in *"Shop page"*"ms slower after SSPA Watchdog Fixture 1.1.0"*) pass "the subject names the worst finding" ;; *) fail "subject: $SUBJECT" ;; esac
DELTA=$(printf '%s' "$BODY" | grep -E '^- Shop page:' | head -1 | sed -E 's/.* ([0-9]+) ms slower.*/\1/')
[ "${DELTA:-0}" -ge 250 ] 2>/dev/null && pass "the shop page is reported $DELTA ms slower (>= 250 ms)" || fail "shop page difference: '${DELTA}' in: $(printf '%s' "$BODY" | head -20)"
case "$BODY" in *"sspa_compare_before="*"sspa_compare_after=$PAGES_RUN"*) pass "the email links to Compare with both runs" ;; *) fail "no Compare link for the two runs" ;; esac
case "$BODY" in *"action=sspa_watchdog_markdown"*) pass "the email links to the Markdown report" ;; *) fail "no Markdown link" ;; esac
RECIP=$(printf '%s' "$MAIL" | json 'echo implode(",", $d[0]["to"] ?? array());')
check "$RECIP" "$(cli option get admin_email 2>/dev/null)" "the email goes to the site administrator"
STATUS=$(h ability get-watchdog-status)
check "$(printf '%s' "$STATUS" | json 'echo ($d["last"]["verdict"] ?? "-") . " " . count($d["last"]["regressions"] ?? array()) . " " . (empty($d["running"]) ? "idle" : "running");')" "regressed 2 idle" "get-watchdog-status reports the verdict (both shop pages regressed)"

# ------------------------------------------------------------------ 1b. the screens, in a browser
echo "--- 1b. Compare link, History badge, Markdown link and settings in a browser"
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
if [ ! -d "$PLUGIN_DIR/.tests/node_modules" ]; then
    # A worktree has no node_modules of its own: use the main checkout's, read-only.
    export NODE_PATH="$(cd "$(git -C "$PLUGIN_DIR" rev-parse --git-common-dir)/.." && pwd)/.tests/node_modules"
fi
BROWSER=$(cd "$PLUGIN_DIR/.tests" && timeout 300 node "$HERE/settings.e2e.cjs" 2>&1)
echo "$BROWSER" | grep -E '^(PASS|FAIL):' | sed 's/^/    /'
echo "$BROWSER" | grep -q '^PASS: settings journey' && pass "the watchdog's screens work in a browser" || fail "browser journey: $(echo "$BROWSER" | tail -3)"


# ------------------------------------------------------------------ 2. same update, no slowdown
echo "--- 2. the same update without the slowdown"
fresh true
BASE=$(h analysis baseline "$PAGES")
check "${BASE#* }" "done" "pre-update full analysis completes (run ${BASE%% *})"
h reset >/dev/null
h settings '{"enabled":true,"checkout":true}' >/dev/null
check "$(h update-plugin sspa-wd-fixture 1.1.0 "$(pkg sspa-wd-fixture-1.1.0-fast.zip)")" "updated 1.1.0" "Plugin_Upgrader updates the fixture without the slowdown"
cron_watchdog && pass "the watchdog check completes" || fail "the watchdog check did not complete"
check "$(alerts | json 'echo count((array) $d);')" "0" "no email when nothing got slower"
check "$(h log | json 'echo $d[0]["verdict"] ?? "none";')" "clear" "the check is recorded with no regressions"

# ------------------------------------------------------------------ 3. two updates, checkout off
echo "--- 3. two updates in one batch, checkout test switched off"
fresh false
BASE=$(h analysis baseline "$PAGES")
check "${BASE#* }" "done" "pre-update full analysis completes"
h reset >/dev/null
h settings '{"enabled":true,"checkout":false}' >/dev/null
BEFORE_MAX=$(h max-run)
h update-plugin sspa-wd-fixture 1.1.0 "$(pkg sspa-wd-fixture-1.1.0-fast.zip)" >/dev/null
h update-plugin sspa-wd-second 1.1.0 "$(pkg sspa-wd-second-1.1.0.zip)" >/dev/null
check "$(h events sspa_watchdog_run | cut -d' ' -f1)" "1" "two updates leave exactly one watchdog event"
cron_watchdog && pass "the watchdog check completes" || fail "the watchdog check did not complete"
check "$(h log | json 'echo count((array) $d);')" "1" "one watchdog check for the batch"
check "$(h log | json 'echo count($d[0]["updates"] ?? array());')" "2" "the check covers both updates"
RUNS=$(h runs-since "$BEFORE_MAX")
check "$(printf '%s' "$RUNS" | json 'echo count(array_filter($d, function ($r) { return "checkout" === $r["run_type"]; }));')" "0" "with the checkout setting off, no checkout test runs"
check "$(printf '%s' "$RUNS" | json 'echo count(array_filter($d, function ($r) { return "spot" === $r["run_type"] && "watchdog" === $r["trigger_source"]; }));')" "1" "one page measurement for the batch"

# ------------------------------------------------------------------ 3b. an agent with no WP-Cron
echo "--- 3b. run-watchdog-now in a loop, as an agent does where WP-Cron never fires"
h reset >/dev/null
h settings '{"enabled":true,"checkout":false}' >/dev/null
BEFORE_MAX=$(h max-run)
FIRST=$(h ability run-watchdog-now)
check "$(printf '%s' "$FIRST" | json 'echo empty($d["running"]) ? ($d["error"] ?? "not running") : "running";')" "running" "run-watchdog-now starts a check"
for i in $(seq 1 120); do
    OUT=$(h ability run-watchdog-now)
    [ "$(printf '%s' "$OUT" | json 'echo empty($d["running"]) ? "idle" : "running";')" = "idle" ] && break
done
check "$(printf '%s' "$OUT" | json 'echo ($d["last"]["kind"] ?? "-") . " " . ($d["last"]["verdict"] ?? "-");')" "manual clear" "looping it finishes the check with no WP-Cron (manual check, no regressions)"
check "$(h runs-since "$BEFORE_MAX" | json 'echo count(array_filter($d, function ($r) { return "watchdog" === $r["trigger_source"] && "done" === $r["status"]; }));')" "1" "one watchdog page measurement"

# ------------------------------------------------------------------ 4. one noisy measurement
echo "--- 4. a slow first measurement that does not repeat"
fresh false
BASE=$(h analysis baseline "baseline,home,shop")
check "${BASE#* }" "done" "pre-update analysis of baseline,home,shop completes"
h reset >/dev/null
h settings '{"enabled":true,"checkout":false}' >/dev/null
BEFORE_MAX=$(h max-run)
h update-plugin sspa-wd-fixture 1.1.0 "$(pkg sspa-wd-fixture-1.1.0-noisy.zip)" >/dev/null
# Exactly the first measurement's shop requests are slow (1 with One run, 4 with Three runs).
cli option update sspa_wd_noisy_remaining "$(h requests-per-page)" >/dev/null 2>&1
cron_watchdog && pass "the watchdog check completes" || fail "the watchdog check did not complete"
RUNS=$(h runs-since "$BEFORE_MAX")
check "$(printf '%s' "$RUNS" | json 'echo count(array_filter($d, function ($r) { return "spot" === $r["run_type"] && "watchdog" === $r["trigger_source"]; }));')" "2" "the slow shop page was measured a second time"
check "$(h log | json 'echo count($d[0]["unconfirmed"] ?? array()) . " " . count($d[0]["regressions"] ?? array());')" "1 0" "the slowdown did not repeat, so it does not count"
check "$(alerts | json 'echo count((array) $d);')" "0" "no email for a single noisy measurement"

# ------------------------------------------------------------------ 5. theme and core
echo "--- 5. a theme update and a core version change"
h reset >/dev/null
h settings '{"enabled":true,"checkout":false}' >/dev/null
h place-theme sspa-wd-theme "$DATA/zips/sspa-wd-theme-1.0.0.zip" >/dev/null
check "$(h update-theme sspa-wd-theme 1.1.0 "$(pkg sspa-wd-theme-1.1.0.zip)")" "updated 1.1.0" "Theme_Upgrader updates the fixture theme 1.0.0 -> 1.1.0"
CORE=$(cli core version 2>/dev/null)
# The site last saw an older WordPress: the next request notices the version that is installed now.
cli option update sspa_watchdog_core_version 6.8.3 >/dev/null 2>&1
cli option get sspa_watchdog_core_version >/dev/null 2>&1
PENDING=$(h state | json 'foreach ((array) ($d["pending"]["updates"] ?? array()) as $u) { echo $u["type"] . ":" . $u["slug"] . ":" . $u["from"] . ">" . $u["to"] . " "; }')
case "$PENDING" in *"theme:sspa-wd-theme:1.0.0>1.1.0"*) pass "the theme update is recorded with both versions" ;; *) fail "theme update not recorded: $PENDING" ;; esac
case "$PENDING" in *"core:wordpress:6.8.3>$CORE"*) pass "the core version change 6.8.3 -> $CORE is recorded" ;; *) fail "core change not recorded: $PENDING" ;; esac
check "$(h events sspa_watchdog_run | cut -d' ' -f1)" "1" "and they schedule one watchdog event"

echo
echo "Sample alert email: $DATA/alert-email.txt"
echo "Site left in place: $SSPA_SITE_URL/wp-admin/admin.php?page=sspa#compare"
echo "Passed: $PASSES, failed: $FAILS"
[ "$FAILS" -eq 0 ] && echo "All passed" || exit 1
