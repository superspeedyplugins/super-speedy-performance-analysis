<?php
defined('ABSPATH') || exit;

/**
 * Settings-page workflow picker and controlled editor launcher.
 *
 * The browser loads the selected object's real editor in a same-origin frame. Classic
 * workflows submit that editor's complete form; REST workflows send one empty update through
 * the editor's own authenticated API client. Both are no-change saves, so real update hooks
 * run without SSPA inventing a field mutation that then has to be restored.
 */
class SSPA_Workflow_Analysis {

    public static function register() {
        add_action('wp_ajax_sspa_workflow_targets', array(__CLASS__, 'ajax_targets'));
        add_action('wp_ajax_sspa_workflow_launch', array(__CLASS__, 'ajax_launch'));
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

    /** The newest modified editable object is first and therefore the picker default. */
    public static function targets($object_type, $limit = 50) {
        $object_type = sanitize_key($object_type);
        $limit = max(1, min(100, (int) $limit));
        $targets = array();

        if ('order' === $object_type) {
            if (!class_exists('WooCommerce') || !function_exists('wc_get_orders')) {
                return $targets;
            }
            $orders = wc_get_orders(array(
                'limit' => $limit,
                'return' => 'objects',
                'status' => array_keys(wc_get_order_statuses()),
                'orderby' => 'modified',
                'order' => 'DESC',
            ));
            foreach ($orders as $order) {
                if (!$order || !current_user_can('edit_shop_order', $order->get_id())) {
                    continue;
                }
                $targets[] = array(
                    'id' => $order->get_id(),
                    'label' => sprintf(
                        /* translators: 1: order number, 2: billing name, 3: status, 4: modified date */
                        __('Order #%1$s · %2$s · %3$s · modified %4$s', 'super-speedy-performance-analysis'),
                        $order->get_order_number(),
                        trim($order->get_formatted_billing_full_name()) ?: __('Guest', 'super-speedy-performance-analysis'),
                        wc_get_order_status_name($order->get_status()),
                        $order->get_date_modified() ? $order->get_date_modified()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : __('unknown', 'super-speedy-performance-analysis')
                    ),
                );
            }
            return $targets;
        }

        $object = get_post_type_object($object_type);
        if (!$object || !$object->public || !$object->show_ui || empty($object->cap->edit_posts)
            || !current_user_can($object->cap->edit_posts)) {
            return $targets;
        }
        $posts = get_posts(array(
            'post_type' => $object_type,
            'post_status' => 'any',
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
        ));
        foreach ($posts as $post) {
            if (!current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $title = get_the_title($post);
            $status = get_post_status_object($post->post_status);
            $targets[] = array(
                'id' => $post->ID,
                'label' => sprintf(
                    /* translators: 1: title, 2: object ID, 3: status, 4: modified date */
                    __('%1$s · #%2$d · %3$s · modified %4$s', 'super-speedy-performance-analysis'),
                    $title ? $title : __('(untitled)', 'super-speedy-performance-analysis'),
                    $post->ID,
                    $status ? $status->label : $post->post_status,
                    get_the_modified_date(get_option('date_format') . ' ' . get_option('time_format'), $post)
                ),
            );
        }
        return $targets;
    }

    /** Transports exposed by the installed editor for this object. */
    public static function transports($object_type, $object_id = 0) {
        $object_type = sanitize_key($object_type);
        if ('order' === $object_type) {
            if (!class_exists('WooCommerce') || !$object_id || !function_exists('wc_get_order') || !wc_get_order($object_id)) {
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
    public static function launch($object_type, $object_id, $transport, $suppress_mail = true) {
        $object_type = sanitize_key($object_type);
        $object_id = (int) $object_id;
        $transport = sanitize_key($transport);
        $mail_mode = $suppress_mail ? 'suppress' : 'deliver';

        if (SSPA_Run_Controller::active_run_id()) {
            return new WP_Error('sspa_workflow_active', __('An analysis is already running.', 'super-speedy-performance-analysis'));
        }
        $available = wp_list_pluck(self::transports($object_type, $object_id), 'key');
        if (!in_array($transport, $available, true)) {
            return new WP_Error('sspa_workflow_transport', __('That save transport is not available for this editor.', 'super-speedy-performance-analysis'));
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
        return array('editor_url' => $editor_url);
    }

    /** Validated workflow configuration for the editor currently being rendered. */
    public static function editor_config($context) {
        if (empty($_GET['sspa_workflow']) || empty($_GET['sspa_workflow_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
            return array('active' => false);
        }
        $object_type = isset($context['object_type']) ? sanitize_key($context['object_type']) : '';
        $object_id = isset($context['object_id']) ? (int) $context['object_id'] : 0;
        $transport = isset($_GET['sspa_workflow_transport']) ? sanitize_key(wp_unslash($_GET['sspa_workflow_transport'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $mail_mode = isset($_GET['sspa_workflow_mail']) ? sanitize_key(wp_unslash($_GET['sspa_workflow_mail'])) : 'suppress'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $mail_mode = 'deliver' === $mail_mode ? 'deliver' : 'suppress';
        $nonce = sanitize_text_field(wp_unslash($_GET['sspa_workflow_nonce'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!wp_verify_nonce($nonce, self::nonce_action($object_type, $object_id, $transport, $mail_mode))
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
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_targets() {
        self::guard();
        $object_type = isset($_POST['object_type']) ? sanitize_key(wp_unslash($_POST['object_type'])) : '';
        $targets = self::targets($object_type);
        $object_id = $targets ? (int) $targets[0]['id'] : 0;
        wp_send_json_success(array(
            'targets' => $targets,
            'transports' => self::transports($object_type, $object_id),
        ));
    }

    public static function ajax_launch() {
        self::guard();
        $launch = self::launch(
            isset($_POST['object_type']) ? sanitize_key(wp_unslash($_POST['object_type'])) : '',
            isset($_POST['object_id']) ? (int) $_POST['object_id'] : 0,
            isset($_POST['transport']) ? sanitize_key(wp_unslash($_POST['transport'])) : '',
            !empty($_POST['suppress_mail'])
        );
        if (is_wp_error($launch)) {
            wp_send_json_error(array('message' => $launch->get_error_message(), 'code' => $launch->get_error_code()));
        }
        wp_send_json_success($launch);
    }
}
