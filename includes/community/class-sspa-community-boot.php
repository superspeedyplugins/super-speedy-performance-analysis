<?php
defined('ABSPATH') || exit;

/** Bounded, consent-gated projection of the profiler's boot report. Never exports raw captures. */
class SSPA_Community_Boot {
    private static $phases = array(
        'core_before_plugins', 'plugin_includes', 'plugins_loaded_callbacks', 'theme_load_and_setup',
        'init_callbacks', 'post_init_boot', 'routing_and_query', 'render_and_output',
    );
    private static $hooks = array(
        'plugins_loaded', 'after_setup_theme', 'init', 'widgets_init', 'wp_loaded',
        'wp_enqueue_scripts', 'admin_init', 'admin_menu', 'admin_enqueue_scripts',
        'template_redirect', 'rest_api_init', 'wp_head', 'wp_footer', 'the_content',
        'woocommerce_before_main_content', 'woocommerce_archive_description',
        'woocommerce_before_shop_loop', 'woocommerce_shop_loop', 'woocommerce_before_shop_loop_item',
        'woocommerce_shop_loop_item_title', 'woocommerce_after_shop_loop_item', 'woocommerce_after_shop_loop',
        'woocommerce_after_main_content', 'woocommerce_before_single_product',
        'woocommerce_single_product_summary', 'woocommerce_after_single_product_summary',
        'woocommerce_after_single_product', 'woocommerce_sidebar', 'shortcode', 'widget', 'block',
    );

    public static function project($boot, $page_uuid, $submission_uuid, $inventory) {
        if (!is_array($boot)) {
            return new WP_Error('sspa_boot_shape', __('The saved boot profile is invalid.', 'super-speedy-performance-analysis'));
        }
        try {
            $segments = array();
            foreach (self::$phases as $phase) {
                if (isset($boot['segments'][$phase])) { $segments[$phase] = self::number($boot['segments'][$phase]); }
            }
            $hooks = array();
            foreach (array_slice((array) ($boot['hooks'] ?? array()), 0, 100, true) as $name => $row) {
                $hooks[] = array(
                    'hook' => self::hook($name), 'ms' => self::number($row['ms'] ?? null),
                    'components' => self::timings($row['components'] ?? array(), $submission_uuid, $inventory),
                );
            }
            $assets = array();
            foreach (array_slice((array) ($boot['assets'] ?? array()), 0, 100, true) as $name => $row) {
                $assets[] = array_merge(self::component($name, $submission_uuid, $inventory), array(
                    'scripts' => self::count($row['scripts'] ?? null), 'styles' => self::count($row['styles'] ?? null),
                ));
            }
            $render = $boot['render'] ?? array();
            return array(
                'page_profile_uuid' => $page_uuid, 'segments' => (object) $segments,
                'includes' => self::timings($boot['includes'] ?? array(), $submission_uuid, $inventory),
                'components' => self::timings($boot['components'] ?? array(), $submission_uuid, $inventory),
                'hooks' => $hooks,
                'top_callbacks' => self::callbacks($boot['top_callbacks'] ?? array(), 15, $submission_uuid, $inventory),
                'render' => array(
                    'timed_ms' => isset($render['timed_ms']) ? self::number($render['timed_ms']) : null,
                    'untimed_ms' => isset($render['untimed_ms']) ? self::number($render['untimed_ms']) : null,
                    'components' => self::timings($render['components'] ?? array(), $submission_uuid, $inventory),
                    'top_callbacks' => self::callbacks($render['top'] ?? array(), 10, $submission_uuid, $inventory),
                ),
                'assets' => $assets,
                'coverage' => array('hook_timing' => 'selected_hooks', 'registration' => 'not_captured', 'source_bounded' => true),
            );
        } catch (InvalidArgumentException $error) {
            return new WP_Error('sspa_boot_metric', __('The saved boot profile contains an invalid timing or count.', 'super-speedy-performance-analysis'));
        }
    }

    private static function number($value) {
        if (!is_numeric($value) || !is_finite((float) $value) || $value < 0) {
            throw new InvalidArgumentException('Invalid boot metric');
        }
        return $value + 0;
    }

    private static function count($value) {
        $number = self::number($value);
        if ($number != floor($number) || $number > PHP_INT_MAX) { throw new InvalidArgumentException('Invalid asset count'); }
        return (int) $number;
    }

    private static function hook($name) {
        return in_array($name, self::$hooks, true) ? $name : 'custom';
    }

    private static function component($name, $submission_uuid, $inventory) {
        $type = 'plugin';
        $version = null;
        if ('core' === $name || 'wordpress-core' === $name) { $type = 'core'; }
        elseif ('theme' === $name) {
            // The boot timer's theme bucket has no specific theme identity. Do not substitute
            // the current theme or pretend a parent/child pair can be attributed individually.
            $type = 'private';
        } elseif (0 === strpos((string) $name, 'mu:')) { $type = 'private'; }
        foreach ($inventory as $item) {
            if ($item['slug'] === $name && in_array($item['type'], array('plugin', 'theme'), true)) {
                $type = $item['type']; $version = $item['version']; break;
            }
        }
        $component = SSPA_Community_Privacy::component($name, $type, $submission_uuid);
        return array('component' => $component, 'component_version' => $version);
    }

    private static function timings($values, $submission_uuid, $inventory) {
        $out = array();
        foreach (array_slice((array) $values, 0, 100, true) as $name => $ms) {
            $out[] = array_merge(self::component($name, $submission_uuid, $inventory), array('ms' => self::number($ms)));
        }
        return $out;
    }

    private static function callbacks($values, $limit, $submission_uuid, $inventory) {
        $out = array();
        foreach (array_slice((array) $values, 0, $limit) as $row) {
            $component = self::component($row['component'] ?? '', $submission_uuid, $inventory);
            $label = (string) ($row['label'] ?? '');
            $callback = 'unavailable';
            if ('private' !== $component['component']['type'] && strlen($label) <= 191
                && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:(?:::|->)[A-Za-z_][A-Za-z0-9_]*)?$/D', $label)) {
                $callback = $label;
            }
            $out[] = array_merge($component, array(
                'hook' => self::hook($row['hook'] ?? ''), 'callback' => $callback, 'ms' => self::number($row['ms'] ?? null),
            ));
        }
        return $out;
    }
}
