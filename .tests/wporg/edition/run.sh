#!/usr/bin/env bash
#
# The wordpress.org edition, installed from its built zip, used the way an administrator uses it.
#
#   .tests/wporg/edition/run.sh [path/to/super-speedy-performance-analysis-wporg.zip] [pro.zip]
#
# Own site: tests-wporg-edition.super-speedy-performance-analysis.localhost:8081 (created by
# .tests/setup-site.sh with SSPA_SCENARIO=tests-wporg-edition). The checkout symlink is replaced
# by the extracted wp.org zip, so web requests run exactly the code wordpress.org receives.
#
# The administrator is logged in with curl against wp-login.php, and every action goes through
# the same admin-ajax endpoints the Performance Analysis screen calls, carrying that real login:
#   1. the zip has no pro code, and the only abilities are read-only;
#   2. a run started from the screen measures wp-admin pages as the administrator (200, not a
#      login redirect) using the administrator's own session, no minted login;
#   3. a logged-in REST endpoint measured as the administrator answers 200 (the REST nonce);
#   4. a run started from WP-CLI, with no browser session, leaves the admin pages out and says so;
#   5. the cache check leaves logged-in customers out, with its reason, and sends no request;
#   6. the checkout flow measures order management as the administrator, and with guest
#      checkout off leaves WooCommerce's account in place with a working Delete link;
#   7. installing and activating Pro beside it hands over without losing data; with both active
#      (the free copy loading first) Pro runs, the free copy stands down and asks to be deleted,
#      the profiler loader is repointed to Pro and analyses work; deleting the wp.org copy while
#      Pro stays leaves Pro's tables, options and helper files alone.
#
# Nothing is torn down. The site is left as the last step leaves it.

set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../../.." && pwd)"
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-wporg-edition}"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/.tests/env.sh"
set +e

FAILS=0
pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; FAILS=$((FAILS + 1)); }
check() { if [ "$1" = "$2" ]; then pass "$3"; else fail "$3 (expected '$2', got '$1')"; fi; }

DATA="$PLUGIN_DIR/.data/wporg-edition"
mkdir -p "$DATA"
ZIP="${1:-}"
PRO_ZIP="${2:-}"
if [ -z "$ZIP" ]; then
    ZIP=$(bash "$PLUGIN_DIR/.build/build-wporg.sh" "$DATA" | tail -1) || { echo "FAIL: wp.org build"; exit 1; }
fi
if [ -z "$PRO_ZIP" ]; then
    PRO_ZIP=$(bash "$PLUGIN_DIR/.build/build-pro.sh" "$DATA" | tail -1) || { echo "FAIL: Pro build"; exit 1; }
fi
[ -f "$ZIP" ] && [ -f "$PRO_ZIP" ] || { echo "FAIL: zips missing ($ZIP, $PRO_ZIP)"; exit 1; }

SITE="$SSPA_SITE_DIR"; URL="$SSPA_SITE_URL"
PLUGINS="$SITE/wp-content/plugins"
SLUG=super-speedy-performance-analysis
echo "=== wp.org edition on $URL ==="
echo "    zip: $ZIP"

opcache_reset() {
    printf '<?php echo json_encode(function_exists("opcache_reset") ? opcache_reset() : null);\n' > "$SITE/zz-opcache-reset.php"
    curl -s -m 10 "$URL/zz-opcache-reset.php" >/dev/null
}

install_zip() { # <zip> <folder>
    cli plugin deactivate "$SLUG" "$SLUG-pro" >/dev/null 2>&1
    rm -rf "${PLUGINS:?}/$2"
    unzip -qq -o "$1" -d "$PLUGINS" || return 1
    opcache_reset
}

# ------------------------------------------------------------------ install the wp.org zip
[ -e "$PLUGINS/$SLUG-pro" ] && { cli plugin deactivate "$SLUG-pro" >/dev/null 2>&1; rm -rf "${PLUGINS:?}/$SLUG-pro"; }
install_zip "$ZIP" "$SLUG" || { echo "FAIL: unzip"; exit 1; }
cli plugin activate "$SLUG" >/dev/null 2>&1
check "$(cli plugin get "$SLUG" --field=status 2>/dev/null)" "active" "the wp.org zip installs and activates"
[ ! -L "$PLUGINS/$SLUG" ] && [ ! -e "$PLUGINS/$SLUG/super-speedy-settings" ] && pass "the site runs the extracted zip, not a checkout" || fail "the plugin folder is not the extracted zip"
cp "$PLUGIN_DIR/.tests/fixtures/wporg-edition-adhoc.php" "$SITE/wp-content/mu-plugins/zz-sspa-test-admin-adhoc.php"
cli option update woocommerce_coming_soon no >/dev/null 2>&1
cli option update woocommerce_store_pages_only no >/dev/null 2>&1

# ------------------------------------------------------------------ 1. no pro code, read-only abilities
HITS=$(grep -rnE 'wp_(insert|update|delete|create)_user\s*\(|wp_set_current_user\s*\(|wp_generate_auth_cookie\s*\(|[^_a-z]error_reporting\s*\(' "$PLUGINS/$SLUG" --include='*.php' | head -5)
[ -z "$HITS" ] && pass "no user creation, user switching, minted login or error_reporting() in the installed code" || fail "pro-only calls in the zip: $HITS"
ABIL=$(cli eval '
$names = array(); $writes = array();
foreach (wp_get_abilities() as $a) {
    if (0 !== strpos($a->get_name(), "sspa/")) { continue; }
    $names[] = $a->get_name();
    $ann = $a->get_meta_item("annotations", array());
    if (empty($ann["readonly"])) { $writes[] = $a->get_name(); }
}
echo count($names) . " " . count($writes) . " " . (in_array("sspa/set-profiler", $names, true) ? "set-profiler" : "-") . " " . implode(",", $writes);' 2>&1 | tail -1)
read -r ABIL_N ABIL_W ABIL_SP _ <<<"$ABIL"
[ "${ABIL_N:-0}" -ge 30 ] && pass "$ABIL_N abilities registered" || fail "abilities registered: $ABIL"
check "$ABIL_W" "0" "every registered ability is read-only"
check "$ABIL_SP" "-" "sspa/set-profiler is not registered"
CAP=$(cli eval '$c = SSPA_Abilities_Control::exec_capabilities(array()); $all = wp_json_encode(array($c["workflows"], $c["notes"], $c["prerequisites"]["wp_cron"])); echo (false === strpos($all, "advance-run") && false === strpos($all, "set-profiler") && false === strpos($all, "run-analysis")) ? "clean" : $all;' 2>&1 | tail -1)
check "$CAP" "clean" "get-capabilities names no ability this edition does not have"

# ------------------------------------------------------------------ log in as the administrator
JAR="$DATA/cookies.txt"; rm -f "$JAR"
curl -s -c "$JAR" -b "$JAR" "$URL/wp-login.php" -o /dev/null
printf '%s' "$ADMIN_PASS" | curl -s -c "$JAR" -b "$JAR" -o /dev/null \
    --data-urlencode "log=$ADMIN_USER" --data-urlencode "pwd@-" \
    -d "testcookie=1&rememberme=forever&redirect_to=$URL/wp-admin/" "$URL/wp-login.php"
ADMIN_ID=$(cli user get "$ADMIN_USER" --field=ID 2>/dev/null)
PAGE=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=sspa")
NONCE=$(printf '%s' "$PAGE" | grep -o 'var sspa_admin = {[^;]*' | grep -o '"nonce":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
[ -n "$NONCE" ] && pass "logged in as $ADMIN_USER and loaded Performance Analysis" || { fail "could not log in or read the screen's nonce"; exit 1; }
ajax() { curl -s -b "$JAR" -c "$JAR" "$URL/wp-admin/admin-ajax.php" -d "nonce=$NONCE" "$@"; }

# Enable profiler, as the Tools button does.
EN=$(ajax -d "action=sspa_profiler_toggle&enabled=1")
case "$EN" in *'"success":true'*) pass "Enable profiler through the Tools action" ;; *) fail "enable profiler: $EN" ;; esac

# Pump a run the way the screen does: sspa_process_batch until it is no longer working.
pump() { # <run_id>
    local i out status
    for i in $(seq 1 120); do
        out=$(ajax -d "action=sspa_process_batch&run_id=$1")
        status=$(printf '%s' "$out" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["data"]["status"] ?? "?";')
        case "$status" in crawling|analysing|queued) continue ;; *) echo "$status"; return 0 ;; esac
    done
    echo "timeout"
}
run_id_of() { php -r '$d = json_decode(stream_get_contents(STDIN), true); echo (int) ($d["data"]["run_id"] ?? $d["data"]["id"] ?? 0);'; }
profile() { # <run_id> <page_key> -> "code blocked variant"
    cli eval 'global $wpdb; $r = $wpdb->get_row($wpdb->prepare("SELECT response_code, blocked_by, variant FROM %i WHERE run_id = %d AND page_key = %s", SSPA_Schema::table("profiles"), '"$1"', "'"$2"'"), ARRAY_A); echo $r ? ((int) $r["response_code"] . " " . ($r["blocked_by"] ?: "-") . " " . $r["variant"]) : "missing";' 2>&1 | tail -1
}

# ------------------------------------------------------------------ 2. admin pages measured from the screen
# A Pro run earlier on this retained site leaves Pro's synthetic customer behind; count from here.
ACCOUNTS_BEFORE=$(cli user list --meta_key=sspa_test_account --meta_value=1 --format=count 2>/dev/null)
START=$(ajax -d "action=sspa_start_run" -d "page_keys[]=home" -d "page_keys[]=admin-dashboard" -d "page_keys[]=admin-plugins" -d "page_keys[]=admin-orders")
RUN=$(printf '%s' "$START" | run_id_of)
[ "$RUN" -gt 0 ] 2>/dev/null && pass "spot analysis started from the screen (run $RUN)" || fail "start run: $START"
check "$(pump "$RUN")" "done" "the run finishes when the screen drives it"
check "$(profile "$RUN" admin-dashboard)" "200 - admin" "wp-admin Dashboard measured as the administrator"
check "$(profile "$RUN" admin-plugins)" "200 - admin" "wp-admin Plugins measured as the administrator"
check "$(profile "$RUN" admin-orders)" "200 - admin" "WooCommerce Orders screen measured as the administrator"
check "$(profile "$RUN" home)" "200 - anon" "home measured as a logged-out visitor"
check "$(cli user list --meta_key=sspa_test_account --meta_value=1 --format=count 2>/dev/null)" "$ACCOUNTS_BEFORE" "the analysis created no synthetic account"

# ------------------------------------------------------------------ 3. logged-in REST endpoint as the administrator
REST_URL="$URL/wp-json/wp/v2/users/me?context=edit"
START=$(ajax -d "action=sspa_test_admin_adhoc" --data-urlencode "url=$REST_URL")
RUN=$(printf '%s' "$START" | run_id_of)
check "$(pump "$RUN")" "done" "admin REST measurement finishes"
KEY=$(cli eval 'echo SSPA_Adhoc::job_for("'"$REST_URL"'", "admin")["page_key"];' 2>&1 | tail -1)
check "$(profile "$RUN" "$KEY")" "200 - admin" "wp/v2/users/me?context=edit answers 200 as the administrator (REST nonce from their own session)"

# ------------------------------------------------------------------ 4. WP-CLI start has no session
CLIRUN=$(cli sspa run --type=spot --pages=home,admin-dashboard 2>&1 | tail -3)
CLI_ID=$(cli eval 'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM %i", SSPA_Schema::table("runs")));' 2>&1 | tail -1)
check "$(profile "$CLI_ID" admin-dashboard)" "missing" "a WP-CLI run leaves the admin page out"
check "$(profile "$CLI_ID" home | cut -d' ' -f1)" "200" "a WP-CLI run still measures home"
NOTE=$(cli eval '$r = SSPA_Run_Controller::status('"$CLI_ID"'); echo $r["customer_pages_skipped"] ?? "";' 2>&1 | tail -1)
case "$NOTE" in *"Administrator pages are measured with your own login"*) pass "the run says why the admin page was left out" ;; *) fail "no skip reason on the WP-CLI run: $NOTE / $CLIRUN" ;; esac

# ------------------------------------------------------------------ 5. cache check in a real browser
export SSPA_E2E_URL="$URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS" SSPA_FLEET_OUTPUT="$DATA/browser"
mkdir -p "$SSPA_FLEET_OUTPUT"
# A worktree has no node_modules of its own: use the main checkout's, read-only (never npm ci here).
if [ ! -d "$PLUGIN_DIR/.tests/node_modules" ]; then
    MAIN_TREE=$(cd "$(git -C "$PLUGIN_DIR" rev-parse --git-common-dir)/.." && pwd)
    export NODE_PATH="$MAIN_TREE/.tests/node_modules"
fi
BROWSER=$(cd "$PLUGIN_DIR/.tests" && timeout 600 node "$HERE/cache-check.e2e.cjs" 2>&1)
echo "$BROWSER" | grep -E '^(PASS|FAIL):' | sed 's/^/    /'
echo "$BROWSER" | grep -q '^PASS: cache check in the browser' && pass "cache check pressed in the browser leaves logged-in customers out, and says why" || fail "cache check browser journey: $(echo "$BROWSER" | tail -5)"

# ------------------------------------------------------------------ 6. checkout flow
cli option update woocommerce_enable_guest_checkout no >/dev/null 2>&1
PRE=$(ajax -d "action=sspa_checkout_preflight")
case "$PRE" in *'"creates_account":true'*'"deletes_account":false'*|*'"deletes_account":false'*'"creates_account":true'*) pass "preflight says the purchase creates an account this edition will not delete" ;; *) fail "preflight: ${PRE:0:300}" ;; esac
USERS_BEFORE=$(cli user list --format=count 2>/dev/null)
START=$(ajax -d "action=sspa_checkout_start")
RUN=$(printf '%s' "$START" | run_id_of)
check "$(pump "$RUN")" "done" "checkout flow (guest checkout off) finishes"
RESULT=$(ajax -d "action=sspa_checkout_result" -d "run_id=$RUN" -d "pa_screen=1")
CK=$(printf '%s' "$RESULT" | php -r '
$d = json_decode(stream_get_contents(STDIN), true)["data"] ?? array();
$m = array(); foreach ((array) ($d["management"] ?? array()) as $s) { $m[$s["page_key"]] = (int) ($s["code"] ?? 0); }
$s = $d["notes"]["safety"] ?? array();
echo "view=" . ($m["flow-view-order"] ?? 0) . " complete=" . ($m["flow-complete-order"] ?? 0) . " left=" . (int) ($s["users_left"] ?? -1) . " deleted=" . (int) ($s["users_deleted"] ?? -1) . " ids=" . implode(",", (array) ($s["users_left_ids"] ?? array())) . " url=" . ($s["users_delete_url"] ?? "");')
echo "    checkout: ${CK%% url=*}"
case "$CK" in "view=200 complete=200 left=1 deleted=0 ids="[0-9]*" url=http"*) pass "order management measured as the administrator (200); WooCommerce's account kept, with a Delete link" ;; *) fail "checkout result: $CK" ;; esac
LEFT_ID=$(printf '%s' "$CK" | sed -E 's/.* ids=([0-9]+).*/\1/')
check "$(cli user get "$LEFT_ID" --field=ID 2>/dev/null)" "$LEFT_ID" "the account WooCommerce created still exists"
DEL_URL=${CK##* url=}
DEL_PAGE=$(curl -s -b "$JAR" "$DEL_URL")
case "$DEL_PAGE" in *'id="updateusers"'*|*'name="action" value="dodelete"'*) pass "the Delete link opens WordPress's own Delete Users confirmation for that account" ;; *) fail "Delete link did not open the confirmation: $DEL_URL" ;; esac
cli option update woocommerce_enable_guest_checkout yes >/dev/null 2>&1
START=$(ajax -d "action=sspa_checkout_start")
RUN=$(printf '%s' "$START" | run_id_of)
check "$(pump "$RUN")" "done" "checkout flow (guest checkout on) finishes"
check "$(cli user list --format=count 2>/dev/null)" "$((USERS_BEFORE + 1))" "a guest purchase creates no account"

# ------------------------------------------------------------------ 7. moving to Pro and back
TABLES_BEFORE=$(cli db query "SHOW TABLES LIKE '%sspa_profiles'" --skip-column-names 2>/dev/null | wc -l)
RUNS_BEFORE=$(cli eval 'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", SSPA_Schema::table("runs")));' 2>&1 | tail -1)
rm -rf "${PLUGINS:?}/$SLUG-pro"; unzip -qq -o "$PRO_ZIP" -d "$PLUGINS"; opcache_reset
# Activate Pro from the Plugins screen, with the wp.org copy still active: the link WordPress prints.
ACT_URL=$(curl -s -b "$JAR" "$URL/wp-admin/plugins.php" | grep -o 'href="plugins.php?action=activate[^"]*super-speedy-performance-analysis-pro[^"]*"' | head -1 | sed -E 's/^href="//; s/"$//; s/&amp;/\&/g')
[ -n "$ACT_URL" ] || fail "no Activate link for Pro on the Plugins screen"
curl -s -b "$JAR" -o /dev/null "$URL/wp-admin/$ACT_URL"
opcache_reset
check "$(cli plugin get "$SLUG-pro" --field=status 2>/dev/null)" "active" "Pro activates from the Plugins screen"
FREE_STATUS=$(cli plugin get "$SLUG" --field=status 2>/dev/null)
check "$FREE_STATUS" "inactive" "activating Pro deactivated the wp.org copy"
if [ "$FREE_STATUS" != inactive ]; then
    echo "    diagnostic: active_plugins in the database: $(cli db query "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'" --skip-column-names 2>/dev/null)"
    echo "    diagnostic: active_plugins through the object cache: $(cli option get active_plugins --format=json 2>/dev/null)"
    echo "    diagnostic: cron events due: $(cli cron event list --fields=hook,next_run_relative --format=csv 2>/dev/null | grep -i sspa | tr '\n' ' ')"
fi
NAME=$(cli eval 'echo get_plugin_data(WP_PLUGIN_DIR . "/super-speedy-performance-analysis-pro/super-speedy-performance-analysis.php")["Name"] . "|" . SSPA_PLUGIN_FILE;' 2>&1 | tail -1)
case "$NAME" in "Super Speedy Performance Analysis Pro|"*"-pro/"*) pass "Pro is the copy running ($NAME)" ;; *) fail "running copy: $NAME" ;; esac
check "$(cli eval 'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", SSPA_Schema::table("runs")));' 2>&1 | tail -1)" "$RUNS_BEFORE" "Pro sees every run the wp.org edition recorded"
# Pro, with the free copy installed but switched off, asks for it to be deleted.
PL=$(curl -s -b "$JAR" "$URL/wp-admin/plugins.php")
case "$PL" in *"The free Super Speedy Performance Analysis plugin is still installed"*) pass "Pro's Plugins screen asks for the free plugin to be deleted" ;; *) fail "no delete-the-free-plugin notice from Pro" ;; esac
# The profiler loader the wp.org copy installed now names Pro's folder (that admin request repointed it).
case "$(cat "$SITE/wp-content/mu-plugins/sspa-loader.php")" in *"super-speedy-performance-analysis-pro/profiler/bootstrap.php"*) pass "the profiler loader was repointed to Pro" ;; *) fail "the profiler loader still names the wp.org copy" ;; esac

# Both active, the free copy FIRST in active_plugins: Pro must still be the one running.
cli eval '$a = array_values(array_diff((array) get_option("active_plugins"), array("super-speedy-performance-analysis/super-speedy-performance-analysis.php"))); array_unshift($a, "super-speedy-performance-analysis/super-speedy-performance-analysis.php"); update_option("active_plugins", $a);' >/dev/null 2>&1
opcache_reset
check "$(cli plugin get "$SLUG" --field=status 2>/dev/null)/$(cli plugin get "$SLUG-pro" --field=status 2>/dev/null)" "active/active" "both copies active, the free copy loading first"
check "$(cli eval 'echo basename(dirname(SSPA_PLUGIN_FILE));' 2>&1 | tail -1)" "$SLUG-pro" "Pro is the copy running (WP-CLI)"
PL=$(curl -s -b "$JAR" -w '\nHTTP %{http_code}' "$URL/wp-admin/plugins.php")
case "$PL" in *"critical error"*|*"Fatal error"*) fail "the Plugins screen fatals with both active" ;; *"HTTP 200") pass "the Plugins screen loads with both active" ;; *) fail "Plugins screen: ${PL: -20}" ;; esac
case "$PL" in *"Super Speedy Performance Analysis Pro is active, so the free Super Speedy Performance Analysis plugin is switched off"*) pass "the free copy says it is switched off and asks to be deleted" ;; *) fail "no stand-down notice from the free copy" ;; esac
case "$PL" in *"is still installed"*) fail "both notices shown at once" ;; *) pass "only one notice" ;; esac
SP=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=sspa")
case "$SP" in *"plugins/super-speedy-performance-analysis-pro/includes/admin"*) pass "Performance Analysis screen served by Pro" ;; *) fail "screen not served by Pro" ;; esac
case "$SP" in *"plugins/super-speedy-performance-analysis/includes/admin"*) fail "the free copy's assets load too" ;; *) pass "no assets from the free copy" ;; esac
case "$SP" in *"is switched off and does nothing"*) pass "the notice shows on the Performance Analysis screen" ;; *) fail "no notice on the Performance Analysis screen" ;; esac
NONCE=$(printf '%s' "$SP" | grep -o 'var sspa_admin = {[^;]*' | grep -o '"nonce":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
RUN=$(ajax -d "action=sspa_start_run" -d "page_keys[]=home" -d "page_keys[]=admin-dashboard" | run_id_of)
check "$(pump "$RUN")" "done" "an analysis runs under Pro with the free copy still active"
check "$(profile "$RUN" admin-dashboard)" "200 - admin" "and measures wp-admin as the administrator"
check "$(profile "$RUN" home | cut -d' ' -f1)" "200" "and measures home"
cli plugin deactivate "$SLUG" >/dev/null 2>&1
RUNS_BEFORE=$((RUNS_BEFORE + 1))

cli eval 'sspa_update_option("remove_data_on_uninstall", true);' >/dev/null 2>&1
cli plugin uninstall "$SLUG" >/dev/null 2>&1
check "$( [ -d "$PLUGINS/$SLUG" ] && echo present || echo deleted)" "deleted" "the wp.org copy is deleted through WordPress (its uninstall.php ran)"
check "$(cli db query "SHOW TABLES LIKE '%sspa_profiles'" --skip-column-names 2>/dev/null | wc -l)" "$TABLES_BEFORE" "deleting it while Pro is installed keeps the tables, even with remove-data on"
check "$( [ -f "$SITE/wp-content/mu-plugins/sspa-loader.php" ] && echo present || echo missing)" "present" "and keeps the profiler loader Pro is using"
cli eval 'sspa_update_option("remove_data_on_uninstall", false);' >/dev/null 2>&1

# Back to the wp.org edition, so the site keeps showing what wordpress.org receives.
cli plugin deactivate "$SLUG-pro" >/dev/null 2>&1
install_zip "$ZIP" "$SLUG"
cli plugin activate "$SLUG" >/dev/null 2>&1
check "$(cli plugin get "$SLUG" --field=status 2>/dev/null)" "active" "the wp.org edition is active again"
check "$(cli eval 'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", SSPA_Schema::table("runs")));' 2>&1 | tail -1)" "$RUNS_BEFORE" "with every run still there"

rm -f "$SITE/zz-opcache-reset.php"
echo
echo "Site left in place: $URL/wp-admin/admin.php?page=sspa (Pro is installed but inactive beside it)"
if [ "$FAILS" -gt 0 ]; then echo "FAILED: $FAILS"; exit 1; fi
echo "All passed"
