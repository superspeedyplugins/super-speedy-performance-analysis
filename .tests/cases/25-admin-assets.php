<?php
// Admin CSS/JS are enqueued with a cache key. Keyed on SSPA_VERSION alone, editing an asset
// without bumping the plugin version leaves browsers serving the previous file under the same
// URL - which presents as broken styling or dead JavaScript, not as a caching problem.

function sspa_assets_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$css = 'includes/admin/css/sspa-admin.css';
$js = 'includes/admin/js/sspa-admin.js';
$checkout_js = 'includes/admin/js/sspa-checkout.js';

$css_version = sspa_asset_version($css);
sspa_assets_t(
    0 === strpos($css_version, SSPA_VERSION . '.') && preg_match('/\.\d{9,}$/', $css_version),
    'the stylesheet cache key carries the file mtime (' . $css_version . ')'
);
$js_path = SSPA_PLUGIN_DIR . $js;
$js_original = filemtime($js_path);
$css_mtime = filemtime(SSPA_PLUGIN_DIR . $css);
if ($js_original === $css_mtime) {
    touch($js_path, $js_original + 1);
    clearstatcache(true, $js_path);
}
$js_version = sspa_asset_version($js);
touch($js_path, $js_original);
clearstatcache(true, $js_path);
sspa_assets_t($js_version !== $css_version, 'each asset uses its own file mtime for its cache key');
sspa_assets_t(
    'store.example-' . SSPA_VERSION . '-sspa-report.json' === sspa_download_filename('sspa-report.json', 'https://www.Store.Example:8443/path'),
    'download filenames group by canonical domain and plugin version before the existing name'
);
$download_sources = array(
    'includes/class-sspa-cache-recon.php',
    'includes/traffic/class-sspa-traffic-ajax.php',
    'includes/admin/class-sspa-profile-panel.php',
    'includes/class-sspa-run-controller.php',
);
$download_helpers = 0;
foreach ($download_sources as $download_source) {
    $download_helpers += false !== strpos((string) file_get_contents(SSPA_PLUGIN_DIR . $download_source), 'sspa_download_filename(') ? 1 : 0;
}
sspa_assets_t(count($download_sources) === $download_helpers, 'every generated JSON download uses the shared filename prefix');
sspa_assets_t(
    false !== strpos((string) file_get_contents(SSPA_PLUGIN_DIR . $checkout_js), 'Synthetic order details for fulfilment')
    && false !== strpos((string) file_get_contents(SSPA_PLUGIN_DIR . $checkout_js), 'Order number')
    && false !== strpos((string) file_get_contents(SSPA_PLUGIN_DIR . $checkout_js), 'Coupon')
    && false !== strpos((string) file_get_contents(SSPA_PLUGIN_DIR . $checkout_js), 'sspa-ck-order-details'),
    'checkout overlay renders the fulfilment identifiers'
);
sspa_assets_t(SSPA_VERSION === sspa_asset_version('includes/admin/css/does-not-exist.css'), 'a missing asset falls back to the plugin version');

// The point of the whole thing: touching the file must change the key.
$path = SSPA_PLUGIN_DIR . $css;
$original = filemtime($path);
touch($path, $original + 60);
clearstatcache(true, $path);
$bumped = sspa_asset_version($css);
touch($path, $original);
clearstatcache(true, $path);
sspa_assets_t($bumped !== $css_version, 'editing the file changes its cache key (' . $css_version . ' -> ' . $bumped . ')');
sspa_assets_t(sspa_asset_version($css) === $css_version, 'restoring the mtime restores the key');
