<?php
defined('ABSPATH') || exit;

/**
 * Settings-page workflow picker and controlled editor launcher.
 *
 * The browser loads a temporary administrator-owned object's editor in a same-origin frame.
 * Classic workflows submit its form; REST workflows send an empty update through the
 * authenticated API client. The run removes the object after measuring its save hooks.
 */
class SSPA_Workflow_Analysis {

    public static function register() {
        add_action('wp_ajax_sspa_workflow_targets', array(__CLASS__, 'ajax_targets'));
        add_action('wp_ajax_sspa_workflow_launch', array(__CLASS__, 'ajax_launch'));
        add_action('wp_ajax_sspa_workflow_cleanup', array(__CLASS__, 'ajax_cleanup'));
    }

    /** Every editable public post type, plus WooCommerce orders as their own data model. */
    public static function object_types() {
        $types = array();
        $objects = get_post_types(array('public' => true, 'show_ui' => true), 'objects');
        foreach ($objects as $object) {
            if ('attachment' === $object->name || empty($object->cap->edit_posts)
                || !current_user_can($object->cap->edit_posts)) {
                continue;
            }
            $types[] = array(
                'key' => $object->name,
                'label' => $object->labels->singular_name,
            );
        }
        if (class_exists('WooCommerce') && current_user_can('edit_shop_orders')) {
            $types[] = array(
                'key' => 'order',
                'label' => __('Order', 'super-speedy-performance-analysis'),
            );
        }

        $priority = array('post' => 0, 'page' => 1, 'product' => 2, 'order' => 3);
        usort($types, function ($a, $b) use ($priority) {
            $a_order = isset($priority[$a['key']]) ? $priority[$a['key']] : 100;
            $b_order = isset($priority[$b['key']]) ? $priority[$b['key']] : 100;
            if ($a_order !== $b_order) {
                return $a_order - $b_order;
            }
            return strcasecmp($a['label'], $b['label']);
        });
        return $types;
    }

    const TEMP_OPTION = 'sspa_workflow_object';

    /** Existing site content is never offered as a workflow target. */
    public static function targets($object_type, $limit = 50) {
        return array();
    }

    public static function owns($object_type, $object_id) {
        $entry = get_option(self::TEMP_OPTION, array());
        if (empty($entry['id']) || (int) $entry['id'] !== (int) $object_id || $entry['type'] !== $object_type) {
            return false;
        }
        if ('order' === $object_type) {
            $order = wc_get_order($object_id);
            return $order && $order->get_meta('_sspa_temp') && $order->get_meta('_sspa_workflow');
        }
        return (bool) get_post_meta($object_id, '_sspa_workflow', true);
    }

    public static function bind_run($run_id) {
        $entry = get_option(self::TEMP_OPTION, array());
        $entry['run_id'] = (int) $run_id;
        update_option(self::TEMP_OPTION, $entry, false);
    }

    public static function cleanup($run_id = 0) {
        $entry = get_option(self::TEMP_OPTION, array());
        if (!$entry || ($run_id && (int) ($entry['run_id'] ?? 0) !== (int) $run_id)) {
            return;
        }
        if (self::owns($entry['type'], $entry['id'])) {
            SSPA_Probes::delete_temp((int) $entry['id'], 'order' === $entry['type']);
        }
        delete_option(self::TEMP_OPTION);
    }

    private static function create_target($object_type) {
        self::cleanup();
        // Recover marked objects even if PHP stopped before the launch registry was saved.
        foreach (get_posts(array('post_type' => array_keys(get_post_types()), 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_sspa_workflow', 'meta_value' => '1', 'fields' => 'ids')) as $stale_id) {
            SSPA_Probes::delete_temp((int) $stale_id);
        }
        if (function_exists('wc_get_orders')) {
            foreach (wc_get_orders(array('limit' => -1, 'type' => 'shop_order', 'meta_key' => '_sspa_workflow', 'meta_value' => '1', 'return' => 'ids')) as $stale_id) {
                SSPA_Probes::delete_temp((int) $stale_id, true);
            }
        }
        if ('order' === $object_type) {
            $id = SSPA_Probes::create_temp_order(true);
            if (!$id) {
                return new WP_Error('sspa_workflow_create', __('Could not create the workflow order.', 'super-speedy-performance-analysis'));
            }
            update_option(self::TEMP_OPTION, array('id' => $id, 'type' => $object_type, 'run_id' => 0, 'created_at' => time()), false);
            $order = wc_get_order($id);
            $order->update_meta_data('_sspa_workflow', '1');
            SSPA_Mail::with_admin(static function() use ($order) { $order->save(); });
        } else {
            $id = SSPA_Probes::create_temp_copy($object_type, true);
            update_option(self::TEMP_OPTION, array('id' => $id, 'type' => $object_type, 'run_id' => 0, 'created_at' => time()), false);
            if ('product' === $object_type) {
                $product = wc_get_product($id);
                $product->set_regular_price('0');
                $product->set_price('0');
                $product->set_manage_stock(true);
                $product->set_stock_quantity(100);
                $product->set_catalog_visibility('hidden');
                SSPA_Mail::with_admin(static function() use ($product) { $product->save(); });
            }
        }
        update_option(self::TEMP_OPTION, array('id' => $id, 'type' => $object_type, 'run_id' => 0, 'created_at' => time()), false);
        return $id;
    }

    /** Transports exposed by the installed editor for this object. */
    public static function transports($object_type, $object_id = 0) {
        $object_type = sanitize_key($object_type);
        if ('order' === $object_type) {
            if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) {
                return array();
            }
            return array(
                array('key' => 'classic', 'label' => __('WooCommerce classic form handler', 'super-speedy-performance-analysis')),
                array('key' => 'rest', 'label' => __('WooCommerce admin REST route', 'super-speedy-performance-analysis')),
            );
        }

        $object = get_post_type_object($object_type);
        if (!$object) {
            return array();
        }
        if ($object->show_in_rest && function_exists('use_block_editor_for_post_type')
            && use_block_editor_for_post_type($object_type)) {
            return array(array('key' => 'rest', 'label' => __('Editor REST update', 'super-speedy-performance-analysis')));
        }
        return array(array('key' => 'classic', 'label' => __('Classic editor form handler', 'super-speedy-performance-analysis')));
    }

    /**
     * Build one signed editor URL. Loading this URL alone is harmless; the admin-save driver
     * validates the nonce again before it automates the selected transport.
     */
    public static function launch($object_type, $object_id, $transport, $legacy_mail_option = null) {
        try {
            $result = self::launch_owned($object_type, $object_id, $transport);
        } catch (Throwable $error) {
            $result = new WP_Error('sspa_workflow_failed', $error->getMessage());
        }
        if (is_wp_error($result) && !SSPA_Run_Controller::active_run_id()) { self::cleanup(); }
        return $result;
    }

    private static function launch_owned($object_type, $object_id, $transport) {
        $object_type = sanitize_key($object_type);
        $object_id = (int) $object_id;
        $transport = sanitize_key($transport);
        $mail_mode = 'deliver';

        if (SSPA_Run_Controller::active_run_id()) {
            return new WP_Error('sspa_workflow_active', __('An analysis is already running.', 'super-speedy-performance-analysis'));
        }
        if (!in_array($object_type, wp_list_pluck(self::object_types(), 'key'), true)) {
            return new WP_Error('sspa_workflow_type', __('That content type cannot be analysed.', 'super-speedy-performance-analysis'));
        }
        $available = wp_list_pluck(self::transports($object_type, $object_id), 'key');
        if (!in_array($transport, $available, true)) {
            return new WP_Error('sspa_workflow_transport', __('That save transport is not available for this editor.', 'super-speedy-performance-analysis'));
        }

        $object_id = self::create_target($object_type);
        if (is_wp_error($object_id)) {
            return $object_id;
        }
        if ('order' === $object_type) {
            $order = function_exists('wc_get_order') ? wc_get_order($object_id) : false;
            if (!$order || !current_user_can('edit_shop_order', $object_id)) {
                return new WP_Error('sspa_workflow_target', __('That order cannot be edited by this user.', 'super-speedy-performance-analysis'));
            }
            $editor_url = $order->get_edit_order_url();
        } else {
            $post = get_post($object_id);
            if (!$post || $post->post_type !== $object_type || !current_user_can('edit_post', $object_id)) {
                return new WP_Error('sspa_workflow_target', __('That content item cannot be edited by this user.', 'super-speedy-performance-analysis'));
            }
            $editor_url = get_edit_post_link($object_id, 'raw');
        }
        if (!$editor_url) {
            return new WP_Error('sspa_workflow_editor', __('WordPress did not provide an editor URL for that item.', 'super-speedy-performance-analysis'));
        }

        $action = self::nonce_action($object_type, $object_id, $transport, $mail_mode);
        $editor_url = add_query_arg(array(
            'sspa_workflow' => '1',
            'sspa_workflow_transport' => $transport,
            'sspa_workflow_mail' => $mail_mode,
            'sspa_workflow_nonce' => wp_create_nonce($action),
        ), $editor_url);
        return array('editor_url' => $editor_url, 'object_id' => $object_id, 'warning' => SSPA_Mail::warning());
    }

    /** Validated workflow configuration for the editor currently being rendered. */
    public static function editor_config($context) {
        if (!current_user_can('manage_options')) {
            return array('active' => false);
        }
        if (empty($_GET['sspa_workflow']) || empty($_GET['sspa_workflow_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
            return array('active' => false);
        }
        $object_type = isset($context['object_type']) ? sanitize_key($context['object_type']) : '';
        if ('shop_order' === $object_type) { $object_type = 'order'; }
        $object_id = isset($context['object_id']) ? (int) $context['object_id'] : 0;
        $transport = isset($_GET['sspa_workflow_transport']) ? sanitize_key(wp_unslash($_GET['sspa_workflow_transport'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $mail_mode = 'deliver';
        $nonce = sanitize_text_field(wp_unslash($_GET['sspa_workflow_nonce'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!self::owns($object_type, $object_id) || !wp_verify_nonce($nonce, self::nonce_action($object_type, $object_id, $transport, $mail_mode))
            || !in_array($transport, wp_list_pluck(self::transports($object_type, $object_id), 'key'), true)) {
            return array('active' => false);
        }

        $rest_url = '';
        if ('rest' === $transport) {
            if ('order' === $object_type) {
                $rest_url = rest_url('wc/v3/orders/' . $object_id);
            } else {
                $object = get_post_type_object($object_type);
                if ($object) {
                    $namespace = $object->rest_namespace ? $object->rest_namespace : 'wp/v2';
                    $base = $object->rest_base ? $object->rest_base : $object->name;
                    $rest_url = rest_url(trim($namespace, '/') . '/' . trim($base, '/') . '/' . $object_id);
                }
            }
        }
        return array(
            'active' => true,
            'transport' => $transport,
            'mail_mode' => $mail_mode,
            'rest_url' => $rest_url,
        );
    }

    private static function nonce_action($object_type, $object_id, $transport, $mail_mode) {
        return 'sspa_workflow_editor:' . sanitize_key($object_type) . ':' . (int) $object_id . ':' . sanitize_key($transport) . ':' . sanitize_key($mail_mode);
    }

    private static function guard() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_targets() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        $object_type = isset($_POST['object_type']) ? sanitize_key(wp_unslash($_POST['object_type'])) : '';
        $targets = self::targets($object_type);
        wp_send_json_success(array(
            'targets' => $targets,
            'transports' => self::transports($object_type),
        ));
    }

    public static function ajax_cleanup() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
        try {
            $entry = get_option(self::TEMP_OPTION, array());
            $id = isset($_POST['object_id']) ? absint($_POST['object_id']) : 0;
            if (!$id || (int) ($entry['id'] ?? 0) !== $id) { wp_send_json_success(); }
            if (!empty($entry['run_id'])) { SSPA_Run_Controller::cancel((int) $entry['run_id']); }
            self::cleanup();
        } catch (Throwable $error) { wp_send_json_error(array('message' => $error->getMessage())); }
        wp_send_json_success();
    }

    public static function ajax_launch() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        try {
        $launch = self::launch(
            isset($_POST['object_type']) ? sanitize_key(wp_unslash($_POST['object_type'])) : '',
            0,
            isset($_POST['transport']) ? sanitize_key(wp_unslash($_POST['transport'])) : ''
        );
        } catch (Throwable $error) {
            $launch = new WP_Error('sspa_workflow_failed', $error->getMessage());
        }
        if (is_wp_error($launch)) {
            wp_send_json_error(array('message' => $launch->get_error_message(), 'code' => $launch->get_error_code()));
        }
        wp_send_json_success($launch);
    }
}
