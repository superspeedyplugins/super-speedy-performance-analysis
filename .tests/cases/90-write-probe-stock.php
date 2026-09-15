<?php
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
wp_set_current_user(1);
update_option('woocommerce_manage_stock', 'yes');
$id = (int) get_option('sspa_stock_regression_product', 0);
$product = $id ? wc_get_product($id) : false;
if (!$product) { $product = new WC_Product_Simple(); }
$product->set_name('SSPA retained catalogue stock regression');
$product->set_status('publish');
$product->set_regular_price('10');
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product->set_stock_status('instock');
$product->set_date_created(time() - 1);
$id = $product->save();
update_option('sspa_stock_regression_product', $id, false);
sspa_fleet_assert(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS is enabled for the stock regression');
$selected = get_posts(array('numberposts'=>1,'post_type'=>'product','post_status'=>'publish'));
sspa_fleet_assert($selected && (int)$selected[0]->ID === $id, 'adversarial catalogue product is selected by the unfixed order probe');
update_option('sspa_stock_regression_orders', array(), false);
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents(WPMU_PLUGIN_DIR . '/sspa-stock-regression-observer.php', <<<'FIXTURE'
<?php
add_action('woocommerce_order_status_changed', static function($id, $from, $to, $order) {
    if ('completed' !== $to || !$order->get_meta('_sspa_temp')) return;
    $rows = (array)get_option('sspa_stock_regression_orders', array());
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $rows[] = array('order_id'=>$id, 'product_id'=>$item->get_product_id(), 'marker'=>$product ? $product->get_meta('_sspa_temp') : '', 'price'=>$product ? $product->get_price() : null, 'managed'=>$product ? $product->managing_stock() : false, 'stock'=>$product ? $product->get_stock_quantity() : null);
    }
    update_option('sspa_stock_regression_orders', $rows, false);
}, 999, 4);
FIXTURE
);
$run = (int)sspa_fleet_cli(array('sspa','run','--pages=home','--include-writes','--porcelain'));
sspa_fleet_assert($run > 0, 'normal write-profiling command completes a saved run');
$state = json_decode(sspa_fleet_cli(array('eval', '$id=(int)get_option("sspa_stock_regression_product"); $p=wc_get_product($id); echo wp_json_encode(array("stock"=>$p->get_stock_quantity(),"orders"=>get_option("sspa_stock_regression_orders",array())));')), true);
sspa_fleet_assert(($state['stock'] ?? null) === 10, 'real catalogue stock remains 10 after successful write profiling');
echo 'MEASURED: ' . wp_json_encode($state) . "\n";
$orders = $state['orders'] ?? array();
sspa_fleet_assert(count($orders) >= 1 && count(array_unique(array_column($orders, 'product_id'))) === 1, 'real order-processing status hooks record one product across measured samples');
$row = $orders[0] ?? array();
sspa_fleet_assert(!empty($row['product_id']) && (int)$row['product_id'] !== $id && ($row['marker'] ?? '') === 'write_product', 'measured order contains only a plugin-owned temporary product');
sspa_fleet_assert(!empty($row['managed']) && (int)($row['stock'] ?? -1) === 0 && (float)($row['price'] ?? -1) === 0.0, 'real stock hooks consume the zero-price test product own stock');
if (($row['marker'] ?? '') === 'write_product') {
    $removed = json_decode(sspa_fleet_cli(array('eval', 'echo wp_json_encode(array("order"=>(bool)wc_get_order('.(int)$row['order_id'].'),"product"=>(bool)wc_get_product('.(int)$row['product_id'].')));')), true);
    sspa_fleet_assert($removed === array('order'=>false,'product'=>false), 'production cleanup removes only its temporary order and product');
}
global $wpdb;
$profiles = $wpdb->get_results($wpdb->prepare('SELECT page_key,response_code FROM '.SSPA_Schema::table('profiles').' WHERE run_id=%d', $run), ARRAY_A);
$writes = array_values(array_filter($profiles, static function($p){return $p['page_key'] === 'write-order-processing';}));
sspa_fleet_assert(count($writes) === 1 && (int)$writes[0]['response_code'] === 200, 'saved report contains the actual successful order-processing measurement');
sspa_fleet_done();
