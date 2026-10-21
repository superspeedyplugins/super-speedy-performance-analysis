<?php
/**
 * Real catalogue volume for the lead-generation offer tests.
 *
 * Inserts published WooCommerce simple products into the ordinary WordPress tables, with the
 * meta, product type, visibility and category rows WooCommerce itself writes, so the shop,
 * category, search and wp-admin product screens run WordPress's and WooCommerce's own queries
 * against a catalogue big enough to be slow. Nothing here times or shapes a query: the
 * profiler measures whatever those screens really cost.
 *
 * Usage: wp eval-file seed-volume.php <products>
 * Idempotent: tops the "Volume Goods" category up to the requested count and stops.
 */
require_once __DIR__ . '/../lib/native-guard.php';
global $wpdb;
$target = isset($args[0]) ? max(1, (int) $args[0]) : 40000;

$term = term_exists('Volume Goods', 'product_cat');
if (!$term) {
    $term = wp_insert_term('Volume Goods', 'product_cat');
}
$cat_tt = (int) get_term((int) $term['term_id'], 'product_cat')->term_taxonomy_id;
$simple_tt = (int) get_term_by('slug', 'simple', 'product_type')->term_taxonomy_id;
if (!$cat_tt || !$simple_tt) {
    echo "FAIL: product category or product type term missing\n";
    exit(1);
}
$have = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE term_taxonomy_id = %d', $wpdb->term_relationships, $cat_tt));
$words = array('oak', 'linen', 'copper', 'harbour', 'meadow', 'slate', 'amber', 'willow', 'granite', 'saffron', 'cobalt', 'juniper', 'marble', 'pebble', 'thistle', 'walnut', 'cedar', 'ember', 'fennel', 'quartz');
$author = (int) get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'))[0];
$now = current_time('mysql');
$now_gmt = current_time('mysql', true);
mt_srand(20261021 + $have);
$batch = 500;
while ($have < $target) {
    $count = min($batch, $target - $have);
    $rows = array();
    $first_slug = 'volume-item-' . ($have + 1);
    for ($i = 1; $i <= $count; $i++) {
        $n = $have + $i;
        $text = array();
        for ($w = 0; $w < 120; $w++) {
            $text[] = $words[mt_rand(0, count($words) - 1)];
        }
        $title = 'Volume item ' . $n . ' ' . $words[$n % count($words)];
        $rows[] = $wpdb->prepare('(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s)',
            $author, $now, $now_gmt, implode(' ', $text), $title, '', 'publish', 'open', 'closed', 'volume-item-' . $n, '', '', $now, $now_gmt, '', 0, 'product');
    }
    $wpdb->query('INSERT INTO ' . $wpdb->posts . ' (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, post_type) VALUES ' . implode(',', $rows));
    $first_id = (int) $wpdb->insert_id;
    $meta = array();
    $rel = array();
    for ($id = $first_id; $id < $first_id + $count; $id++) {
        $price = (string) (mt_rand(100, 99999) / 100);
        foreach (array('_price' => $price, '_regular_price' => $price, '_sku' => 'VOL-' . $id, '_stock_status' => 'instock', '_manage_stock' => 'no', 'total_sales' => '0', '_tax_status' => 'taxable', '_visibility' => 'visible') as $key => $value) {
            $meta[] = $wpdb->prepare('(%d, %s, %s)', $id, $key, $value);
        }
        $rel[] = $wpdb->prepare('(%d, %d)', $id, $cat_tt);
        $rel[] = $wpdb->prepare('(%d, %d)', $id, $simple_tt);
    }
    $wpdb->query('INSERT INTO ' . $wpdb->postmeta . ' (post_id, meta_key, meta_value) VALUES ' . implode(',', $meta));
    $wpdb->query('INSERT INTO ' . $wpdb->term_relationships . ' (object_id, term_taxonomy_id) VALUES ' . implode(',', $rel));
    $have += $count;
}
foreach (array($cat_tt, $simple_tt) as $tt) {
    $wpdb->query($wpdb->prepare('UPDATE %i SET count = (SELECT COUNT(*) FROM %i WHERE term_taxonomy_id = %d) WHERE term_taxonomy_id = %d', $wpdb->term_taxonomy, $wpdb->term_relationships, $tt, $tt));
}
// The product lookup table WooCommerce sorts and filters by, rebuilt by WooCommerce itself.
if (function_exists('wc_update_product_lookup_tables_column')) {
    $wpdb->query($wpdb->prepare('INSERT IGNORE INTO %i (product_id, sku, min_price, max_price, onsale, stock_status, total_sales, tax_status) SELECT p.ID, CONCAT(%s, p.ID), pm.meta_value, pm.meta_value, 0, %s, 0, %s FROM %i p JOIN %i pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = %s AND p.post_name LIKE %s', $wpdb->prefix . 'wc_product_meta_lookup', 'VOL-', 'instock', 'taxable', $wpdb->posts, $wpdb->postmeta, '_price', 'product', 'volume-item-%'));
}
// Orders in WooCommerce's HPOS tables, each with its placeholder post, billing and shipping
// addresses, operational data and one line item, as WooCommerce writes them. Synthetic
// example.invalid addresses only.
$orders_target = isset($args[1]) ? max(0, (int) $args[1]) : 0;
$orders_have = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE billing_email LIKE %s', $wpdb->prefix . 'wc_orders', 'volume-%@example.invalid'));
$statuses = array('wc-completed', 'wc-processing', 'wc-on-hold');
$towns = array('Harbourside', 'Meadowbank', 'Slatefield', 'Willowmere', 'Cedarford');
while ($orders_have < $orders_target) {
    $count = min($batch, $orders_target - $orders_have);
    $posts = array();
    for ($i = 0; $i < $count; $i++) {
        $posts[] = $wpdb->prepare('(0, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $now, $now_gmt, '', '', '', 'draft', 'closed', 'closed', '', '', '', $now, $now_gmt) ;
    }
    $wpdb->query('INSERT INTO ' . $wpdb->posts . ' (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt) VALUES ' . implode(',', $posts));
    $first_id = (int) $wpdb->insert_id;
    $wpdb->query($wpdb->prepare('UPDATE %i SET post_type = %s, post_content_filtered = %s WHERE ID BETWEEN %d AND %d', $wpdb->posts, 'shop_order_placehold', '', $first_id, $first_id + $count - 1));
    $orders = $addresses = $operational = $items = array();
    for ($id = $first_id; $id < $first_id + $count; $id++) {
        $n = $orders_have + ($id - $first_id) + 1;
        $email = 'volume-' . $n . '@example.invalid';
        $date = gmdate('Y-m-d H:i:s', strtotime($now_gmt) - $n * 600);
        $orders[] = $wpdb->prepare('(%d, %s, %s, %s, %f, %f, %d, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s)', $id, $statuses[$n % 3], 'USD', 'shop_order', 0, mt_rand(500, 50000) / 100, 0, $email, $date, $date, 0, 'cod', 'Cash on delivery', '', '127.0.0.1', 'volume seed', '');
        foreach (array('billing', 'shipping') as $type) {
            $addresses[] = $wpdb->prepare('(%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', $id, $type, 'Volume', 'Customer ' . $n, '', $n . ' ' . $words[$n % count($words)] . ' Road', '', $towns[$n % count($towns)], '', 'VO' . ($n % 99) . ' 1AA', 'GB', $email, '');
        }
        $operational[] = $wpdb->prepare('(%d, %s, %s, %d, %d, %d, %s, %d, %s, %d)', $id, 'checkout', '11.0.0', 0, 1, 1, '', 1, 'wc_order_volume' . $n, 1);
        $items[] = $wpdb->prepare('(%s, %s, %d)', 'Volume item ' . (($n % max(1, $have)) + 1), 'line_item', $id);
    }
    $wpdb->query('INSERT INTO ' . $wpdb->prefix . 'wc_orders (id, status, currency, type, tax_amount, total_amount, customer_id, billing_email, date_created_gmt, date_updated_gmt, parent_order_id, payment_method, payment_method_title, transaction_id, ip_address, user_agent, customer_note) VALUES ' . implode(',', $orders));
    $wpdb->query('INSERT INTO ' . $wpdb->prefix . 'wc_order_addresses (order_id, address_type, first_name, last_name, company, address_1, address_2, city, state, postcode, country, email, phone) VALUES ' . implode(',', $addresses));
    $wpdb->query('INSERT INTO ' . $wpdb->prefix . 'wc_order_operational_data (order_id, created_via, woocommerce_version, prices_include_tax, coupon_usages_are_counted, download_permission_granted, cart_hash, new_order_email_sent, order_key, order_stock_reduced) VALUES ' . implode(',', $operational));
    $wpdb->query('INSERT INTO ' . $wpdb->prefix . 'woocommerce_order_items (order_item_name, order_item_type, order_id) VALUES ' . implode(',', $items));
    $orders_have += $count;
}
wp_cache_flush();
delete_transient('wc_term_counts');
echo 'volume: ' . $have . " products in Volume Goods, $orders_have volume orders\n";
