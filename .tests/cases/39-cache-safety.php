<?php
// Passive shared-cache safety reconnaissance: no response/customer values persist,
// existing mitigation markers survive, source evidence is ranked without claiming blame,
// and the assessment gives agents a stable shared-cache-status/difficulty result.

function sspa_cr_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

$html = <<<'HTML'
<html><body>
<span data-ssap-auth="out">Log in</span><span data-ssap-auth="in">Account</span>
<span data-ssap-visitor="account_name"></span>
<form class="wishlist-form"><input name="save_nonce" value="abcdef1234"></form>
<script>if (document.cookie.indexOf('woocommerce_items_in_cart') > -1) { localStorage.getItem('currency'); }</script>
</body></html>
HTML;
$scan = SSPA_Cache_Recon::scan_response(
    $html,
    array('set-cookie' => '__cf_bm=edge-value; Path=/; Secure, tracking_secret=customer-value; Path=/; HttpOnly', 'x-cache' => 'MISS'),
    array('boot' => array('render' => array('components' => array('example-membership' => 12.4))))
);

sspa_cr_t(in_array('tracking_secret', $scan['set_cookie_names'], true), 'Set-Cookie name retained');
sspa_cr_t(in_array('__cf_bm', $scan['infrastructure_cookie_names'], true) && !in_array('__cf_bm', $scan['set_cookie_names'], true), 'Cloudflare bot cookie classified as infrastructure, not an application hazard');
sspa_cr_t(isset($scan['nonce_names']['save_nonce']), 'nonce field name retained');
sspa_cr_t(in_array('input_field', $scan['nonce_evidence']['save_nonce']['contexts'], true), 'nonce provenance records its input-field context');
sspa_cr_t(1 === $scan['coverage']['type_a_in'] && 1 === $scan['coverage']['type_a_out'], 'Type A coverage markers counted');
sspa_cr_t(isset($scan['coverage']['type_b_regions']['account_name']), 'Type B region id retained');
sspa_cr_t(in_array('woocommerce_items_in_cart', $scan['legacy_cookie_reads'], true), 'hard-coded cart-cookie read found');
sspa_cr_t(in_array('wishlist', $scan['private_surface_hints'], true), 'private wishlist surface found from markup attributes');
sspa_cr_t(in_array('example-membership', $scan['render_components'], true), 'render component candidate retained');

$encoded = wp_json_encode($scan);
sspa_cr_t(false === strpos($encoded, 'customer-value') && false === strpos($encoded, 'abcdef1234'), 'cookie and nonce values never survive the scan');

$source = <<<'PHP'
<?php
// setcookie('comment_only', 'not evidence');
add_action('wp_head', function () {
    if (is_user_logged_in() && WC()->session) {
        wp_nonce_field('save_preferences', 'prefs_nonce');
        echo '<div class="member-price">member</div>';
    }
});
add_filter('woocommerce_get_price_html', function ($price) {
    return is_admin() || !is_shop() ? $price : $price;
});
PHP;
$signals = SSPA_Cache_Recon::scan_source_text($source, 'php');
sspa_cr_t(isset($signals['visitor_state'], $signals['commerce_state'], $signals['render_registration'], $signals['nonce_emitter'], $signals['html_output']), 'corroborated visitor-state/output signals found');
sspa_cr_t(isset($signals['request_context'], $signals['price_filter']), 'price-request context divergence candidate found');
sspa_cr_t(!isset($signals['cookie_setter']), 'commented cookie setter ignored by the PHP token scan');
sspa_cr_t(!SSPA_Cache_Recon::source_file_is_relevant('/plugins/gravityforms/entry_detail.php'), 'admin-oriented Gravity Forms file excluded from source candidates');
sspa_cr_t(!SSPA_Cache_Recon::source_file_is_relevant('/plugins/beaver-builder/classes/class-fl-builder-admin-notices.php'), 'admin-oriented Beaver Builder class excluded from source candidates');
sspa_cr_t(SSPA_Cache_Recon::source_file_is_relevant('/plugins/gravityforms/form_display.php'), 'front-end Gravity Forms renderer remains eligible');
sspa_cr_t(SSPA_Cache_Recon::source_file_is_relevant('/plugins/classic-monks/assets/js/classic-feedback.js'), 'front-end Classic Monks script remains eligible');

$profiles = array(array('id' => 17, 'page_key' => 'shop', 'url' => home_url('/shop/')));
$captures = array(17 => array('cache_recon' => $scan));
$inventory = array(
    'files_scanned' => 4,
    'bytes_scanned' => 12000,
    'truncated' => false,
    'stored_code_not_scanned' => false,
    'candidates' => array(array(
        'component' => 'example-membership',
        'review_priority' => 'high',
        'score' => 11,
        'observed_rendering' => true,
        'signals' => array('visitor_state', 'render_registration', 'nonce_emitter'),
        'evidence' => array(array('file' => 'includes/frontend.php', 'line' => 12, 'signals' => array('visitor_state'))),
    )),
);
$assessment = SSPA_Cache_Recon::build_assessment($profiles, $captures, $inventory);
sspa_cr_t('visitor_specific_content_review_recommended' === $assessment['shared_cache_status'], 'visitor-specific content hazards produce an explicit review status');
sspa_cr_t(in_array($assessment['difficulty'], array('moderate', 'high'), true), 'hazards raise the estimated difficulty (' . $assessment['difficulty'] . ')');
sspa_cr_t(1 === $assessment['pages_scanned'] && !empty($assessment['candidate_components']), 'assessment carries page count and ranked component evidence');
sspa_cr_t(home_url('/shop/') === $assessment['pages'][0]['url'], 'assessment retains the tested page URL, not its response content');
sspa_cr_t(in_array('type_a_vs_type_b_requires_controlled_identity_comparison', $assessment['limitations'], true), 'assessment does not pretend passive evidence proves Type A or B');
sspa_cr_t((bool) has_action('wp_ajax_sspa_cache_recon_export', array('SSPA_Cache_Recon', 'ajax_export')), 'download endpoint is registered for administrators');

$global_scan = SSPA_Cache_Recon::scan_response(
    '<script id="global-config">window.config={"nonce":"abcdef1234"};</script>',
    array('set-cookie' => '__cf_bm=edge-only; Path=/; Secure')
);
sspa_cr_t(in_array('script#global-config', $global_scan['nonce_evidence']['nonce']['containers'], true), 'generic nonce records its script container without retaining script content');
$global_profiles = array();
$global_captures = array();
for ($i = 1; $i <= 8; $i++) {
    $global_profiles[] = array('id' => $i, 'page_key' => 'page-' . $i, 'url' => home_url('/page-' . $i . '/'));
    $global_captures[$i] = array('cache_recon' => $global_scan);
}
$global_assessment = SSPA_Cache_Recon::build_assessment($global_profiles, $global_captures, array(
    'files_scanned' => 0,
    'bytes_scanned' => 0,
    'truncated' => false,
    'components_scanned' => 0,
    'components_truncated' => array(),
    'stored_code_not_scanned' => false,
    'candidates' => array(),
));
sspa_cr_t(0 === $global_assessment['totals']['set_cookie_pages'] && 8 === $global_assessment['totals']['infrastructure_cookie_pages'], 'repeated edge cookie never becomes an application-cookie hazard');
sspa_cr_t('low' === $global_assessment['difficulty'] && 1 === $global_assessment['difficulty_points'], 'one global nonce is scored once across eight pages (' . $global_assessment['difficulty_points'] . ' point)');

$product_scan = SSPA_Cache_Recon::scan_response(
    '<script id="product-config">window.config={"nonce":"abcdef1234","wp_rest_nonce":"bcdef12345","ajax_nonce":"cdef123456"};</script>',
    array('set-cookie' => '__cf_bm=edge-only; Path=/; Secure')
);
$client_captures = $global_captures;
$client_captures[8] = array('cache_recon' => $product_scan);
$client_assessment = SSPA_Cache_Recon::build_assessment($global_profiles, $client_captures, array(
    'files_scanned' => 1200,
    'bytes_scanned' => 20000000,
    'truncated' => true,
    'components_scanned' => 20,
    'components_truncated' => array('classic-monks', 'gravityforms', 'beaver-builder-lite-version'),
    'stored_code_not_scanned' => false,
    'candidates' => array(
        array('component' => 'classic-monks', 'review_priority' => 'medium'),
        array('component' => 'gravityforms', 'review_priority' => 'high'),
        array('component' => 'beaver-builder-lite-version', 'review_priority' => 'high'),
    ),
));
sspa_cr_t('moderate' === $client_assessment['difficulty'], 'repeated edge cookie, three nonce names and two high-priority candidates do not falsely become high difficulty');
sspa_cr_t(array('__cf_bm') === $client_assessment['unique_signals']['infrastructure_cookie_names'], 'report retains the unique infrastructure observation for context');

sspa_cr_t(SSPA_Cache_Recon::eligible_job(array('page_key' => 'shop', 'url' => home_url('/shop/'), 'variant' => 'anon')), 'shop page is a shared-cache candidate');
sspa_cr_t(!SSPA_Cache_Recon::eligible_job(array('page_key' => 'cpt-kadence_element-single', 'url' => home_url('/?kadence_element=404'), 'variant' => 'anon')), 'builder template pseudo-page is excluded');
sspa_cr_t(!SSPA_Cache_Recon::eligible_job(array('page_key' => 'wc-checkout', 'url' => home_url('/checkout/'), 'variant' => 'anon')), 'checkout is excluded as private');
sspa_cr_t(!SSPA_Cache_Recon::eligible_job(array('page_key' => 'admin-dashboard', 'url' => admin_url(), 'variant' => 'admin')), 'wp-admin is excluded');
