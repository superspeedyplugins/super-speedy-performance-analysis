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
sspa_assets_t(sspa_asset_version($js) !== $css_version, 'each asset gets its own cache key');
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
