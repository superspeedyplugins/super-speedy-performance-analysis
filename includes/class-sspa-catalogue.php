<?php
defined('ABSPATH') || exit;

/**
 * Builds the list of pages to profile. Every job has a stable page_key so results are
 * comparable across runs. Read-only GET requests only in phase 1.
 * Design reference: brainstorm 3.2.
 */
class SSPA_Catalogue {

    /**
     * @param array $only_page_keys Optional filter (spot runs / tests).
     * @return array[] jobs: {page_key, url, variant}
     */
    public static function build($only_page_keys = array()) {
        $jobs = array();

        $add = function ($page_key, $url, $variant = 'anon') use (&$jobs) {
            if ($url) {
                $jobs[] = array('page_key' => $page_key, 'url' => $url, 'variant' => $variant);
            }
        };

        // Noise-floor baseline (handled by the mu-loader, near-zero WP work).
        $add('baseline', home_url('/?sspa_baseline=1'));

        // --- Front end ---
        $add('home', home_url('/'));

        $blog_page = (int) get_option('page_for_posts');
        if ($blog_page && 'page' === get_option('show_on_front')) {
            $add('blog', get_permalink($blog_page));
        }

        $post = get_posts(array('numberposts' => 1, 'post_status' => 'publish'));
        if ($post) {
            $add('post-single', get_permalink($post[0]));
        }

        $add('post-cat', self::biggest_term_link('category'));
        $add('post-tag', self::biggest_term_link('post_tag'));

        $search_term = self::search_term();
        $add('search-many', home_url('/?s=' . rawurlencode($search_term)));
        $add('search-zero', home_url('/?s=sspa0result0search'));
        $add('404', home_url('/sspa-404-probe-' . wp_rand(1000, 9999) . '/'));
        $add('feed', get_feed_link());
        $add('rest-posts', rest_url('wp/v2/posts'));

        // --- WooCommerce front end ---
        if (class_exists('WooCommerce')) {
            $shop = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
            if ($shop > 0) {
                $add('shop', get_permalink($shop));
            }
            $add('product-cat', self::biggest_term_link('product_cat'));
            $product = get_posts(array('numberposts' => 1, 'post_type' => 'product', 'post_status' => 'publish'));
            if ($product) {
                $add('product-single', get_permalink($product[0]));
            }
            foreach (array('cart', 'checkout', 'myaccount') as $wc_page) {
                $pid = wc_get_page_id($wc_page);
                if ($pid > 0) {
                    $add('wc-' . $wc_page, get_permalink($pid));
                }
            }
        }

        // --- Custom post types (public, non-builtin, excluding Woo product handled above) ---
        $cpts = get_post_types(array('public' => true, '_builtin' => false), 'objects');
        foreach ($cpts as $cpt) {
            if (in_array($cpt->name, array('product', 'shop_order', 'shop_coupon'), true)) {
                continue;
            }
            if ($cpt->has_archive) {
                $archive = get_post_type_archive_link($cpt->name);
                $add('cpt-' . $cpt->name . '-archive', $archive);
            }
            $single = get_posts(array('numberposts' => 1, 'post_type' => $cpt->name, 'post_status' => 'publish'));
            if ($single) {
                $add('cpt-' . $cpt->name . '-single', get_permalink($single[0]));
            }
            foreach (get_object_taxonomies($cpt->name, 'objects') as $tax) {
                if ($tax->public && !in_array($tax->name, array('category', 'post_tag', 'product_cat', 'product_tag'), true)) {
                    $add('tax-' . $tax->name, self::biggest_term_link($tax->name));
                }
            }
        }

        // --- wp-admin (GET only, read-only screens) ---
        $add('admin-dashboard', admin_url('index.php'), 'admin');
        $add('admin-plugins', admin_url('plugins.php'), 'admin');
        $add('admin-media', admin_url('upload.php'), 'admin');
        $add('admin-posts', admin_url('edit.php'), 'admin');
        if ($post) {
            $add('admin-edit-post', admin_url('post.php?post=' . $post[0]->ID . '&action=edit'), 'admin');
        }
        $add('admin-new-post', admin_url('post-new.php'), 'admin');

        if (class_exists('WooCommerce')) {
            $add('admin-products', admin_url('edit.php?post_type=product'), 'admin');
            if (!empty($product)) {
                $add('admin-edit-product', admin_url('post.php?post=' . $product[0]->ID . '&action=edit'), 'admin');
            }
            $add('admin-new-product', admin_url('post-new.php?post_type=product'), 'admin');

            $hpos = class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
                && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            if ($hpos) {
                $add('admin-orders', admin_url('admin.php?page=wc-orders'), 'admin');
                $add('admin-orders-search', admin_url('admin.php?page=wc-orders&s=test'), 'admin');
            } else {
                $add('admin-orders', admin_url('edit.php?post_type=shop_order'), 'admin');
                $add('admin-orders-search', admin_url('edit.php?post_type=shop_order&s=test'), 'admin');
            }
            $orders = function_exists('wc_get_orders') ? wc_get_orders(array('limit' => 1, 'return' => 'ids')) : array();
            if ($orders) {
                $order_id = (int) $orders[0];
                $edit_url = $hpos
                    ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id)
                    : admin_url('post.php?post=' . $order_id . '&action=edit');
                $add('admin-edit-order', $edit_url, 'admin');
            }
        }

        if ($only_page_keys) {
            $jobs = array_values(array_filter($jobs, function ($job) use ($only_page_keys) {
                return in_array($job['page_key'], $only_page_keys, true);
            }));
        }

        return $jobs;
    }

    private static function biggest_term_link($taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            return null;
        }
        $terms = get_terms(array('taxonomy' => $taxonomy, 'orderby' => 'count', 'order' => 'DESC', 'number' => 1, 'hide_empty' => true));
        if (is_wp_error($terms) || !$terms) {
            return null;
        }
        $link = get_term_link($terms[0]);
        return is_wp_error($link) ? null : $link;
    }

    private static function search_term() {
        // Use the biggest term's name: guaranteed to match plenty of content.
        foreach (array('product_cat', 'category') as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_terms(array('taxonomy' => $taxonomy, 'orderby' => 'count', 'order' => 'DESC', 'number' => 1, 'hide_empty' => true));
            if (!is_wp_error($terms) && $terms) {
                $words = explode(' ', $terms[0]->name);
                return $words[0];
            }
        }
        return 'the';
    }
}
