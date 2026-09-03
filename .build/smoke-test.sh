#!/usr/bin/env bash
#
# Publish gate for a built zip.
#
#   .build/smoke-test.sh <zip> [full|wporg]
#
# Two layers:
#   1. Static  - unzip, php -l everything, assert the edition's file list is right.
#   2. Runtime - a genuinely empty WordPress from the shared parallel-dev smoke harness;
#                install the zip, activate, assert no fatal, assert the admin menu exists,
#                and run a real analysis. The site is LEFT BEHIND at
#                smoke-<tag>.super-speedy-performance-analysis.localhost:8081 (the usual
#                parallel-dev login, from workspace.env)
#                so a failure can be inspected rather than guessed at. It is cleared on the
#                way in by the next run.
#
# NOT Docker. The workspace rule is "no Docker for WordPress, including smoke tests" -
# tools/parallel-dev/bin/smoke.sh gives the same guarantees natively in about 5 seconds.

set -uo pipefail

ZIP="${1:?usage: smoke-test.sh <zip> [full|wporg]}"
EDITION="${2:-wporg}"
SLUG="super-speedy-performance-analysis"

pass() { echo "PASS  $*"; }
fail() { echo "FAIL  $*"; FAILED=1; }
FAILED=0

[ -f "$ZIP" ] || { echo "FAIL  no such zip: $ZIP"; exit 1; }
echo "=== smoke: $EDITION edition - $(basename "$ZIP") ==="

# ---------------------------------------------------------------- static
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
unzip -qq "$ZIP" -d "$WORK" || { echo "FAIL  unzip"; exit 1; }
ROOT="$WORK/$SLUG"
[ -d "$ROOT" ] || { echo "FAIL  zip does not contain a $SLUG/ folder"; exit 1; }

# Every PHP file must parse.
LINT=$(find "$ROOT" -name '*.php' -exec php -l {} \; 2>&1 | grep -v '^No syntax errors' || true)
[ -z "$LINT" ] && pass "php -l clean ($(find "$ROOT" -name '*.php' | wc -l | tr -d ' ') files)" \
                || fail "php -l:"$'\n'"$LINT"

# No dot-folder may reach a customer.
DOTS=$(find "$ROOT" -name '.*' -not -name '.' | head -5)
[ -z "$DOTS" ] && pass "no dot-files in the zip" || fail "dot-files present:"$'\n'"$DOTS"

[ ! -e "$ROOT/wp-cli.local.yml" ] && pass "no machine-local wp-cli config" \
                                      || fail "machine-local wp-cli.local.yml present"

# Things every edition needs.
for f in "$SLUG.php" readme.txt includes/class-sspa-schema.php profiler/bootstrap.php \
         mu/sspa-loader.php dropins/db.php uninstall.php \
         includes/admin/vendor/echarts-history.min.js \
         includes/admin/vendor/LICENSE-echarts.txt \
         includes/admin/vendor/NOTICE-echarts.txt \
         includes/admin/vendor/LICENSE-zrender.txt; do
    [ -e "$ROOT/$f" ] && pass "present: $f" || fail "MISSING: $f"
done

# Stable tag must equal header Version or PCP errors.
HV=$(grep -m1 -E '^\s*\*\s*Version:' "$ROOT/$SLUG.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')
SV=$(grep -m1 -E '^Stable tag:' "$ROOT/readme.txt" | sed -E 's/^Stable tag:[[:space:]]*//' | tr -d '\r')
[ "$HV" = "$SV" ] && pass "version $HV == stable tag $SV" || fail "version $HV != stable tag $SV"

if [ "$EDITION" = "wporg" ]; then
    [ -e "$ROOT/super-speedy-settings" ] && fail "super-speedy-settings present in the wp.org zip" \
                                         || pass "super-speedy-settings absent"
    HIT=$(grep -rlE 'PucFactory|plugin-update-checker' "$ROOT" 2>/dev/null | grep -v "/$SLUG.php\$" || true)
    [ -z "$HIT" ] && pass "no bundled updater" || fail "updater references:"$'\n'"$HIT"
    grep -q 'add_menu_page' "$ROOT/includes/admin/class-sspa-admin-page.php" \
        && pass "top-level admin menu fallback present" \
        || fail "no add_menu_page() fallback - wp.org zip would have no admin page"
else
    [ -f "$ROOT/super-speedy-settings/super-speedy-settings.php" ] \
        && pass "super-speedy-settings bundled" || fail "super-speedy-settings missing from the full zip"
fi

# ---------------------------------------------------------------- runtime
SMOKE_LIB="$HOME/dev/super-speedy/tools/parallel-dev/bin/smoke.sh"
if [ ! -f "$SMOKE_LIB" ]; then
    echo "WARN  parallel-dev smoke harness not found - skipping the runtime layer"
    exit $FAILED
fi

# shellcheck source=/dev/null
source "$SMOKE_LIB"
# lib.sh sets -e; these assertions rely on non-zero being a normal outcome.
set +e
set -uo pipefail

pd_smoke_start "sspa-$EDITION" "$SLUG" "$ZIP" || { echo "FAIL  could not start the smoke site"; exit 1; }
pass "smoke WordPress at $PD_SMOKE_URL (left in place)"

wpc plugin list --status=active --field=name 2>/dev/null | grep -q "^$SLUG$" \
    && pass "activates" || fail "did not activate"

# A fatal on activation shows up as output on any wp call.
OUT=$(wpc eval 'echo defined("SSPA_VERSION") ? SSPA_VERSION : "UNDEFINED";' 2>&1)
case "$OUT" in
    *Fatal*|*"critical error"*) fail "fatal after activation: $OUT" ;;
    UNDEFINED)                  fail "SSPA_VERSION not defined - bootstrap did not run" ;;
    *)                          pass "bootstrapped, SSPA_VERSION=$OUT" ;;
esac

# The admin menu must exist, otherwise the wp.org edition installs with no UI.
#
# addmenu() is called directly rather than via do_action("admin_menu"): the hook is added
# inside an is_admin() branch, which is false under wp-cli, so firing the action proves
# nothing. Calling it directly exercises the real branch choice - shared "superspeedy"
# submenu when the settings submodule registered one, own top-level menu when it did not.
MENU=$(wpc eval '
wp_set_current_user(1);
require_once ABSPATH . "wp-admin/includes/plugin.php";
global $admin_page_hooks, $menu, $submenu;
$branch = isset($admin_page_hooks["superspeedy"]) ? "shared-submenu" : "own-top-level";
SSPA_Admin_Page::addmenu();
$found = "none";
foreach ((array) $menu as $m) { if (isset($m[2]) && "sspa" === $m[2]) { $found = "top-level:" . $m[2]; break; } }
if ($found === "none") { foreach ((array) $submenu as $parent => $items) { foreach ($items as $i) { if (isset($i[2]) && "sspa" === $i[2]) { $found = "submenu:" . $parent . ">" . $i[2]; break 2; } } } }
echo $branch . " / " . $found;' 2>&1)
case "$MENU" in
    *none*) fail "addmenu() registered no page: $MENU" ;;
    *)      pass "admin menu: $MENU" ;;
esac
if [ "$EDITION" = "wporg" ]; then
    case "$MENU" in
        own-top-level*) pass "wp.org edition falls back to its own top-level menu" ;;
        *)              fail "wp.org edition did not take the fallback branch: $MENU" ;;
    esac
fi

# The updater must be inert in the wp.org edition and live in the full one. WP-CLI is not an
# admin request, so the full-edition check temporarily installs a smoke-only MU bootstrap that
# defines WP_ADMIN before normal plugins load. This exercises the real admin-gated settings and
# updater path instead of asserting an admin global in a non-admin process.
if [ "$EDITION" = "full" ]; then
    wpc eval 'wp_mkdir_p(WPMU_PLUGIN_DIR); file_put_contents(WPMU_PLUGIN_DIR . "/sspa-smoke-admin.php", "<?php if (!defined(\"WP_ADMIN\")) define(\"WP_ADMIN\", true);");' >/dev/null 2>&1
fi
UPD=$(wpc eval '
if (!class_exists("SuperSpeedySettings")) {
    echo "absent";
    return;
}
$instance_property = new ReflectionProperty("SuperSpeedySettings", "instance");
if (PHP_VERSION_ID < 80100) {
    $instance_property->setAccessible(true);
}
$instance = $instance_property->getValue();
if (!$instance || !property_exists($instance, "update_checkers")) {
    echo "absent";
    return;
}
$checker_property = new ReflectionProperty(get_class($instance), "update_checkers");
if (PHP_VERSION_ID < 80100) {
    $checker_property->setAccessible(true);
}
$checkers = $checker_property->getValue($instance);
echo isset($checkers["super-speedy-performance-analysis"]) ? "present" : "absent";
' 2>&1)
if [ "$EDITION" = "full" ]; then
    wpc eval 'unlink(WPMU_PLUGIN_DIR . "/sspa-smoke-admin.php");' >/dev/null 2>&1
fi
case "$EDITION:$UPD" in
    wporg:absent) pass "update checker absent (correct for wp.org)" ;;
    full:present) pass "update checker built (correct for the self-hosted edition)" ;;
    wporg:*)      fail "wp.org edition must have no update checker, got: $UPD" ;;
    full:*)       fail "full edition should build an update checker, got: $UPD" ;;
esac

# The real thing: a profiling run end to end against a real site.
RUN=$(wpc sspa run --type=spot --pages=home 2>&1 | tail -2)
echo "$RUN" | grep -q 'Success' && pass "analysis run completed: $(echo "$RUN" | tail -1)" \
                                || fail "analysis run failed:"$'\n'"$RUN"

# The opt-in uninstall path must remove tables and every other owned object, not row-delete
# large tables or leave identities/test fixtures behind.
OWNED=$(wpc eval '
$p = SSPA_Checkout_Flow::default_product();
$u = SSPA_Auth::test_customer_id();
sspa_update_option("remove_data_on_uninstall", true);
update_option("sspa_uninstall_probe", "present", false);
echo (int) $p->get_id() . ":" . (int) $u;' 2>&1)
wpc plugin uninstall "$SLUG" --deactivate >/dev/null 2>&1
UNINSTALL=$(wpc eval '
global $wpdb;
$tables = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s", $wpdb->esc_like($wpdb->prefix . "sspa_") . "%"));
$options = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \"sspa\\_%\"");
list($pid, $uid) = array_map("intval", explode(":", "'"$OWNED"'"));
echo $tables . ":" . $options . ":" . (get_post($pid) ? 1 : 0) . ":" . (get_userdata($uid) ? 1 : 0);' 2>&1)
[ "$UNINSTALL" = "0:0:0:0" ] && pass "opt-in uninstall drops tables and removes options, product and user" \
                                  || fail "opt-in uninstall left owned data: $UNINSTALL"

echo
[ "$FAILED" -eq 0 ] && echo "=== SMOKE PASSED ($EDITION) ===" || echo "=== SMOKE FAILED ($EDITION) ==="
exit $FAILED
