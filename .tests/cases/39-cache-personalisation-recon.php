<?php
// Passive cache-personalisation reconnaissance: no response/customer values persist,
// existing mitigation markers survive, source evidence is ranked without claiming blame,
// and the assessment gives agents a stable qualification/difficulty result.

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
    array('set-cookie' => 'tracking_secret=customer-value; Path=/; HttpOnly', 'x-cache' => 'MISS'),
    array('boot' => array('render' => array('components' => array('example-membership' => 12.4))))
);

sspa_cr_t(in_array('tracking_secret', $scan['set_cookie_names'], true), 'Set-Cookie name retained');
sspa_cr_t(isset($scan['nonce_names']['save_nonce']), 'nonce field name retained');
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

$profiles = array(array('id' => 17, 'page_key' => 'shop', 'url' => home_url('/shop/')));
$captures = array(17 => array('cache_recon' => $scan));
$inventory = array(
    'files_scanned' => 4,
    'bytes_scanned' => 12000,
    'truncated' => false,
    'stored_code_not_scanned' => false,
    'candidates' => array(array(
        'component' => 'example-membership',
        'risk' => 'high',
        'score' => 11,
        'observed_rendering' => true,
        'signals' => array('visitor_state', 'render_registration', 'nonce_emitter'),
        'evidence' => array(array('file' => 'includes/frontend.php', 'line' => 12, 'signals' => array('visitor_state'))),
    )),
);
$assessment = SSPA_Cache_Recon::build_assessment($profiles, $captures, $inventory);
sspa_cr_t('strong_candidate' === $assessment['qualification'], 'WooCommerce site with hazards qualifies as a strong candidate');
sspa_cr_t(in_array($assessment['difficulty'], array('moderate', 'high'), true), 'hazards raise the estimated difficulty (' . $assessment['difficulty'] . ')');
sspa_cr_t(1 === $assessment['pages_scanned'] && !empty($assessment['candidate_components']), 'assessment carries page count and ranked component evidence');
sspa_cr_t(home_url('/shop/') === $assessment['pages'][0]['url'], 'assessment retains the tested page URL, not its response content');
sspa_cr_t(in_array('type_a_vs_type_b_requires_controlled_identity_comparison', $assessment['limitations'], true), 'assessment does not pretend passive evidence proves Type A or B');

sspa_cr_t(SSPA_Cache_Recon::eligible_job(array('page_key' => 'shop', 'url' => home_url('/shop/'), 'variant' => 'anon')), 'shop page is a shared-cache candidate');
sspa_cr_t(!SSPA_Cache_Recon::eligible_job(array('page_key' => 'wc-checkout', 'url' => home_url('/checkout/'), 'variant' => 'anon')), 'checkout is excluded as private');
sspa_cr_t(!SSPA_Cache_Recon::eligible_job(array('page_key' => 'admin-dashboard', 'url' => admin_url(), 'variant' => 'admin')), 'wp-admin is excluded');
