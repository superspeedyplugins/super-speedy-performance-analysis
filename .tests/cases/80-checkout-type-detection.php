<?php
// The checkout preflight must not call a page a shortcode checkout just because WooCommerce
// says the block is not the default. A configured checkout page holding neither the shortcode
// nor the block is unsupported, and the UI must say so instead of offering a purchase.

function sspa_80_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_80_failures']++; }
}
$GLOBALS['sspa_80_failures'] = 0;

$page_id = (int) get_option('woocommerce_checkout_page_id');
sspa_80_t($page_id > 0, 'a checkout page is configured (' . $page_id . ')');

$set = static function ($content) use ($page_id) {
    wp_update_post(array('ID' => $page_id, 'post_content' => $content));
    clean_post_cache($page_id);
    wp_cache_flush();
    return SSPA_Checkout_Preflight::checkout_type();
};

sspa_80_t('classic' === $set('[woocommerce_checkout]'), 'a page carrying the shortcode is a classic checkout');
sspa_80_t('block' === $set('<!-- wp:woocommerce/checkout /-->'), 'a page carrying the block is a block checkout');
$plain = $set('Synthetic unsupported checkout');
sspa_80_t('unknown' === $plain, 'a page with neither shortcode nor block is unknown, not classic (got ' . $plain . ')');

// Leave the page in the state the other cases and journeys expect on entry.
$set('[woocommerce_checkout]');

if ($GLOBALS['sspa_80_failures']) { exit(1); }
