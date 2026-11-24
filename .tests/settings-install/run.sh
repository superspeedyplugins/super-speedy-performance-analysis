#!/usr/bin/env bash
# The super-speedy-settings "Install Performance Analysis" button on a site with NO licence key.
#
#   .tests/settings-install/run.sh [settings-copy-dir]
#
# Own site: tests-settings-installs-wporg.super-speedy-performance-analysis.localhost:8081.
# The settings copy under test defaults to this repository's super-speedy-settings submodule.
#
# On the way in: both Performance Analysis folders are removed, the licence key is cleared and the
# settings copy is copied into a test host plugin (a Super Speedy plugin other than Performance
# Analysis, so the panel's Install button is what a visitor sees). Then the real
# wp_ajax_superspeedy_install_performance_analysis handler runs exactly as admin-ajax.php runs it,
# with a real nonce for a real administrator, and the outcome is checked on disk and in WordPress:
#   - the request succeeds
#   - the plain super-speedy-performance-analysis folder holds the wordpress.org build
#     (readme Stable tag = the version wordpress.org's latest-stable serves) and is active
#   - no super-speedy-performance-analysis-pro folder was created
#   - a keyless ssp/install-plugin for the -pro slug is refused before any download
#   - the latest-stable redirect is refused off the origin host, slug, scheme or with a query
# The site is left as the run finished, for inspection.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SSPA_SCENARIO=tests-settings-installs-wporg
# shellcheck source=/dev/null
source "$HERE/../env.sh"

SETTINGS_SRC="${1:-$PLUGIN_DIR/super-speedy-settings}"
SETTINGS_SRC="$(cd "$SETTINGS_SRC" && pwd)"
[ -f "$SETTINGS_SRC/super-speedy-settings-core.php" ] || { echo "FAIL: no settings copy at $SETTINGS_SRC" >&2; exit 1; }

case "$SSPA_SITE_DIR" in
    "$SITES_ROOT"/*) ;;
    *) echo "FAIL: refusing site outside $SITES_ROOT: $SSPA_SITE_DIR" >&2; exit 1 ;;
esac

if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    "$(dirname "$PD_LIB")/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" >/dev/null || { echo "FAIL: could not create the site" >&2; exit 1; }
fi
echo "Site: $SSPA_SITE_URL ($SSPA_SITE_DIR)"
echo "Settings copy: $SETTINGS_SRC ($(grep -o "SETTINGS_VERSION = '[^']*'" "$SETTINGS_SRC/super-speedy-settings-core.php"))"

PLUGINS="$SSPA_SITE_DIR/wp-content/plugins"
HOST="$PLUGINS/sspa-settings-test-host"

# --- clear the previous run on the way in -----------------------------------------------
cli plugin deactivate super-speedy-performance-analysis super-speedy-performance-analysis-pro --quiet >/dev/null 2>&1
for folder in super-speedy-performance-analysis super-speedy-performance-analysis-pro; do
    if [ -L "$PLUGINS/$folder" ]; then
        rm "$PLUGINS/$folder"          # a symlink to a checkout: remove the link only
    elif [ -d "$PLUGINS/$folder" ]; then
        rm -rf "${PLUGINS:?}/$folder"
    fi
done
cli option delete superspeedy_license_key >/dev/null 2>&1
cli option delete superspeedy_settings >/dev/null 2>&1
rm -rf "$HOST"
mkdir -p "$HOST"
cp -r "$SETTINGS_SRC" "$HOST/super-speedy-settings"
rm -rf "$HOST/super-speedy-settings/.git" "$HOST/super-speedy-settings/CLAUDE.md" "$HOST/super-speedy-settings/AGENTS.md"
cat > "$HOST/sspa-settings-test-host.php" <<'PHP'
<?php
/**
 * Plugin Name: SSPA settings install test host
 * Description: Test fixture. Bundles one super-speedy-settings copy the way a Super Speedy plugin does.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/super-speedy-settings/super-speedy-settings.php';
SuperSpeedySettings_1_0::init( array(
    'plugin_slug' => 'super-speedy-filters',
    'version'     => '1.0.0',
    'file'        => __FILE__,
) );
PHP
cli plugin activate sspa-settings-test-host --quiet >/dev/null || { echo "FAIL: could not activate the test host" >&2; exit 1; }

EXPECTED_VERSION="$(curl -sSI https://downloads.wordpress.org/plugin/super-speedy-performance-analysis.latest-stable.zip \
    | tr -d '\r' | sed -n 's#^location: .*/super-speedy-performance-analysis\.\(.*\)\.zip$#\1#Ip')"
echo "wordpress.org latest-stable: ${EXPECTED_VERSION:-unknown}"

# --- press the button: the registered admin-ajax handler, as admin-ajax.php dispatches it ----
DRIVER="$(mktemp -d)/press.php"
cat > "$DRIVER" <<'PHP'
<?php
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );
$_POST['nonce']   = wp_create_nonce( 'superspeedy_install_sspa' );
$_POST['action']  = $_REQUEST['action'] = 'superspeedy_install_performance_analysis';
echo 'HANDLER ' . ( has_action( 'wp_ajax_superspeedy_install_performance_analysis' ) ? 'registered' : 'missing' ) . "\n";
add_filter( 'wp_die_ajax_handler', function () {
    return function () { throw new RuntimeException( 'wp_die' ); };
} );
ob_start();
try {
    do_action( 'wp_ajax_superspeedy_install_performance_analysis' );
} catch ( RuntimeException $e ) {
}
$out = ob_get_clean();
echo 'RESPONSE ' . preg_replace( '/\s+/', ' ', $out ) . "\n";

// The paid edition must go through the licence gate now, never a keyless download.
$calls = 0;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$calls ) { $calls++; return $pre; }, 10, 3 );
$pro = SSP_MCP::exec_install_plugin( array( 'slug' => 'super-speedy-performance-analysis-pro', 'activate' => false, 'confirm' => true ) );
echo 'PRO ' . ( is_wp_error( $pro ) ? $pro->get_error_code() : 'installed' ) . ' http_requests=' . $calls . "\n";

// The latest-stable redirect may only land on the same slug's versioned zip on the same host.
$origin = 'https://downloads.wordpress.org/plugin/super-speedy-performance-analysis.latest-stable.zip';
$refused = 0;
foreach ( array(
    'https://example.com/plugin/super-speedy-performance-analysis.0.68.5.zip',
    'https://downloads.wordpress.org/plugin/another-plugin.1.0.zip',
    'https://downloads.wordpress.org/plugin/super-speedy-performance-analysis.0.68.5.zip?x=1',
    'http://downloads.wordpress.org/plugin/super-speedy-performance-analysis.0.68.5.zip',
) as $bad ) {
    $refused += is_wp_error( SSP_MCP::validate_public_package_redirect( $bad, $origin ) ) ? 1 : 0;
}
echo 'REDIRECTS refused=' . $refused . "/4\n";
PHP
OUT="$(cli --exec='define("WP_ADMIN", true); define("DOING_AJAX", true);' eval-file "$DRIVER" 2>&1)"
echo "$OUT"

# --- assert the user-visible outcome ---------------------------------------------------------
fails=0
pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; fails=$((fails + 1)); }

grep -q '^HANDLER registered' <<<"$OUT" && pass "the button's ajax handler is registered" || fail "the button's ajax handler is not registered"
grep -q '^RESPONSE .*"success":true' <<<"$OUT" && pass "the install request succeeded" || fail "the install request did not succeed"
if [ -f "$PLUGINS/super-speedy-performance-analysis/super-speedy-performance-analysis.php" ]; then
    pass "the plain super-speedy-performance-analysis folder was installed"
else
    fail "the plain super-speedy-performance-analysis folder was not installed"
fi
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//Ip' "$PLUGINS/super-speedy-performance-analysis/readme.txt" 2>/dev/null | tr -d '\r' | head -1)"
if [ -n "$EXPECTED_VERSION" ] && [ "$STABLE" = "$EXPECTED_VERSION" ]; then
    pass "the installed build is wordpress.org's $EXPECTED_VERSION"
else
    fail "the installed build's Stable tag '$STABLE' is not wordpress.org's '$EXPECTED_VERSION'"
fi
if cli plugin is-active super-speedy-performance-analysis 2>/dev/null; then
    pass "the plain plugin is active"
else
    fail "the plain plugin is not active"
fi
[ ! -e "$PLUGINS/super-speedy-performance-analysis-pro" ] && pass "no -pro folder was created" || fail "a -pro folder exists"
grep -q '^PRO ssp_no_licence http_requests=0' <<<"$OUT" && pass "keyless Pro install refused before any download" || fail "keyless Pro install was not refused by the licence gate"

grep -q '^REDIRECTS refused=4/4' <<<"$OUT" && pass "off-origin and off-slug redirects are refused" || fail "an off-origin or off-slug redirect was accepted"

echo "Site left for inspection: $SSPA_SITE_URL/wp-admin/admin.php?page=superspeedy"
if [ "$fails" -gt 0 ]; then
    echo "RESULT: $fails failed"
    exit 1
fi
echo "RESULT: all passed"
