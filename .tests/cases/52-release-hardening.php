<?php
defined('ABSPATH') || exit;

$GLOBALS['sspa_hardening_fails'] = 0;
function sspa_hardening_t($ok, $message) {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $message . "\n";
    if (!$ok) {
        $GLOBALS['sspa_hardening_fails']++;
    }
}

// Checkout uses one reusable plugin-owned product, never a catalogue product or its stock.
$sample_ids = wc_get_products(array('status' => 'publish', 'limit' => 20, 'return' => 'ids'));
$sample_ids = array_values(array_filter($sample_ids, function ($id) {
    return 'checkout_product' !== get_post_meta((int) $id, '_sspa_temp', true);
}));
$product = SSPA_Checkout_Flow::default_product($sample_ids ? (int) $sample_ids[0] : 0);
$again = SSPA_Checkout_Flow::default_product();
sspa_hardening_t($product && $again && $product->get_id() === $again->get_id(), 'checkout reuses one dedicated test product');
sspa_hardening_t($product && !in_array($product->get_id(), array_map('intval', $sample_ids), true), 'checkout ignores real catalogue product ids');
sspa_hardening_t($product && 'hidden' === $product->get_catalog_visibility(), 'test product is hidden from catalogue and search');
sspa_hardening_t($product && !$product->managing_stock() && 'checkout_product' === $product->get_meta('_sspa_temp', true), 'test product is marked and cannot alter real stock');

// Drive the real HTTP wrapper while intercepting immediately before transport.
$seen_args = null;
$intercept = function ($preempt, $args) use (&$seen_args) {
    $seen_args = $args;
    return array(
        'headers' => array(),
        'body' => '{}',
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('pre_http_request', $intercept, 10, 2);
$crawler = new SSPA_Crawler();
$crawler->send_profiled(home_url('/'), array('cookies' => SSPA_Auth::cookies_for('admin', get_current_user_id())));
remove_filter('pre_http_request', $intercept, 10);
sspa_hardening_t(is_array($seen_args) && true === $seen_args['sslverify'], 'profile transport requires TLS verification');
sspa_hardening_t(is_array($seen_args) && 120 === (int) $seen_args['timeout'], 'profile transport has a finite 120-second deadline');

$cross_origin_requests = 0;
$redirect_intercept = function ($preempt, $args, $url) use (&$cross_origin_requests) {
    if ('attacker.invalid' === wp_parse_url($url, PHP_URL_HOST)) {
        $cross_origin_requests++;
    }
    return array(
        'headers' => array('location' => 'https://attacker.invalid/catcher'),
        'body' => '',
        'response' => array('code' => 302, 'message' => 'Found'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('pre_http_request', $redirect_intercept, 10, 3);
$crawler->profile_job(array(
    'page_key' => 'baseline',
    'url' => home_url('/'),
    'variant' => 'admin',
    'samples' => 1,
), get_current_user_id());
remove_filter('pre_http_request', $redirect_intercept, 10);
sspa_hardening_t(0 === $cross_origin_requests, 'profile redirects never forward credentials cross-origin');

// The unique option index is the real arbitration boundary used by run and worker claims.
$claim_key = 'sspa_test_atomic_claim';
SSPA_Atomic_Claim::force_release($claim_key);
$owner_a = SSPA_Atomic_Claim::acquire($claim_key, 60, 'owner-a');
$owner_b = SSPA_Atomic_Claim::acquire($claim_key, 60, 'owner-b');
sspa_hardening_t('owner-a' === $owner_a && false === $owner_b, 'atomic lease has exactly one owner');
sspa_hardening_t(!SSPA_Atomic_Claim::release($claim_key, 'owner-b'), 'non-owner cannot release an atomic lease');
sspa_hardening_t(SSPA_Atomic_Claim::release($claim_key, 'owner-a'), 'owner releases its lease');

// A real anonymous HTTP request reports which heavy classes were loaded in that process.
$fixture_dir = WPMU_PLUGIN_DIR;
wp_mkdir_p($fixture_dir);
$fixture_file = $fixture_dir . '/sspa-bootstrap-probe.php';
file_put_contents($fixture_file, <<<'PHP'
<?php
$GLOBALS['sspa_bootstrap_queries'] = array();
add_filter('query', function ($sql) {
    if (false !== stripos($sql, 'sspa_')) {
        $GLOBALS['sspa_bootstrap_queries'][] = $sql;
    }
    return $sql;
});
add_action('template_redirect', function () {
    if (isset($_GET['sspa_bootstrap_probe'])) {
        wp_send_json(array(
            'run' => class_exists('SSPA_Run_Controller', false),
            'install' => class_exists('SSPA_Install', false),
            'schema' => class_exists('SSPA_Schema', false),
            'checkout' => class_exists('SSPA_Checkout_Flow', false),
            'traffic' => class_exists('SSPA_Traffic_Collection', false),
            'abilities' => class_exists('SSPA_Abilities', false),
            'queries' => $GLOBALS['sspa_bootstrap_queries'],
        ));
    }
}, PHP_INT_MAX);
PHP
);
$response = wp_remote_get(add_query_arg('sspa_bootstrap_probe', '1', home_url('/')), array('timeout' => 20));
$loaded = !is_wp_error($response) ? json_decode(wp_remote_retrieve_body($response), true) : null;
$loaded_debug = is_wp_error($response)
    ? $response->get_error_message()
    : wp_remote_retrieve_response_code($response) . ':' . substr(wp_remote_retrieve_body($response), 0, 300);
unlink($fixture_file);
sspa_hardening_t(
    is_array($loaded) && empty($loaded['run']) && empty($loaded['install']) && empty($loaded['schema'])
        && empty($loaded['checkout']) && empty($loaded['traffic'])
        && empty($loaded['abilities']) && empty($loaded['queries']),
    'ordinary anonymous request does not load profiling engines (' . wp_json_encode($loaded) . '; ' . $loaded_debug . ')'
);

// History exposes the persisted uninstall choice in the requested location.
ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/history.php';
$history = ob_get_clean();
sspa_hardening_t(false !== strpos($history, 'sspa-remove-data-on-uninstall'), 'History renders the delete-on-uninstall toggle');

if ($GLOBALS['sspa_hardening_fails']) {
    echo 'FAIL  release hardening failures: ' . $GLOBALS['sspa_hardening_fails'] . "\n";
} else {
    echo "PASS  all six release-hardening behaviours hold\n";
}
