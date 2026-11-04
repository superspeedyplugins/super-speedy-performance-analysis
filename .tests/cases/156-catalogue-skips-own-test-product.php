<?php
// The analysis and the cache check measure "the product page" from the catalogue's product-single
// entry, the latest published product. The checkout flow's own hidden test product is published, so
// once a checkout run had created it after the real catalogue it became that page (reproduced on a
// real store: a hidden 1.00 product with 4 queries stood in for 95,910 real products).
//
// A real product is created, then the checkout flow's own test product through its real code path,
// so the test product is the newest. The catalogue and the cache check's targets must still pick the
// real product. Both products are retained for inspection and replaced on the next entry.

function sspa_ct_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

if (!class_exists('WooCommerce')) {
    sspa_ct_t(false, 'WooCommerce is active on the suite site');
    return;
}
wp_set_current_user(1);
foreach (get_posts(array('post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_sspa_case156', 'meta_value' => '1')) as $old) {
    wp_delete_post((int) $old, true);
}
$real = new WC_Product_Simple();
$real->set_name('Case 156 real product');
$real->set_status('publish');
$real->set_regular_price('10.00');
$real->update_meta_data('_sspa_case156', '1');
$real->save();
sleep(1); // distinct post_date, so the test product is unambiguously newer
SSPA_Checkout_Flow::delete_test_product();
$test = SSPA_Checkout_Flow::default_product();
sspa_ct_t($test && $test->get_id() > $real->get_id(), 'the checkout flow\'s own test product is the newest product');

$catalogue = SSPA_Catalogue::build(array('product-single'));
$single = null;
foreach ($catalogue as $job) {
    if ('product-single' === $job['page_key']) {
        $single = $job['url'];
    }
}
sspa_ct_t($single === get_permalink($real->get_id()), 'the catalogue measures the real product, not the plugin\'s own test product (' . $single . ')');

$targets = SSPA_Cache_Delivery::targets();
$product_target = null;
foreach ($targets as $target) {
    if ('product' === $target['page_key']) {
        $product_target = $target['url'];
    }
}
sspa_ct_t($product_target === get_permalink($real->get_id()), 'the cache check\'s product page is the real product (' . $product_target . ')');
