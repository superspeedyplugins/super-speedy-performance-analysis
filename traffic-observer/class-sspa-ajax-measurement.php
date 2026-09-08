<?php
defined('ABSPATH') || exit;
/** Capture-time provenance; loaded by the active observer before ordinary plugins. */
class SSPA_Ajax_Measurement {
    private $config;
    private $loaded = array();
    private $detail = null;
    private $context = null;
    public function __construct($config) {
        $this->config = $config;
        add_action('plugin_loaded', function ($file) {
            $header = get_file_data($file, array('Version' => 'Version'));
            $this->loaded[plugin_basename($file)] = $header['Version'];
        }, PHP_INT_MAX);
        add_action('muplugins_loaded', function () {
            if (function_exists('spro_fast_ajax_measurement_context')) { $this->context = spro_fast_ajax_measurement_context(); }
        }, PHP_INT_MAX);
        if (!empty($config['detail'])) {
            $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : '';
            $wc = isset($_GET['wc-ajax']) && is_string($_GET['wc-ajax']) ? $_GET['wc-ajax'] : '';
            $candidate = $wc ?: ((defined('DOING_AJAX') && DOING_AJAX) ? $action : '');
            $selector = ($wc ? 'wc_ajax:' : 'admin_ajax:') . $candidate;
            if (!empty($config['endpoints']) && !in_array($selector, $config['endpoints'], true)) { $candidate = ''; }
            if ($candidate && preg_match('/^[a-zA-Z0-9_.-]{1,120}$/D', $candidate) && strpos($candidate, 'sspa_') !== 0) {
                $prefix = 'sspa_ajax_slot_' . $config['uuid'] . '_';
                $claimed = false;
                for ($i = 0; $i < 5; $i++) { if (add_option($prefix . substr(hash('sha256', $candidate), 0, 16) . '_' . $i, 1, '', false)) { $claimed = true; break; } }
                if ($claimed) {
                    for ($i = 0; $i < 20; $i++) {
                        if (add_option($prefix . 'total_' . $i, 1, '', false)) {
                            require_once __DIR__ . '/class-sspa-endpoint-detail.php';
                            $hook = $wc ? 'wc_ajax_' . $wc : ('wp_ajax_' . $action);
                            $this->detail = new SSPA_Endpoint_Detail($hook); break;
                        }
                    }
                }
            }
        }
    }
    public function capture($identity) {
        $selector = $identity['transport'] . ':' . $identity['endpoint'];
        if (!empty($this->config['endpoints']) && !in_array($selector, $this->config['endpoints'], true)) { return null; }
        ksort($this->loaded);
        // Read the provider at request completion too: its descriptor was fixed by its MU filter.
        if (function_exists('spro_fast_ajax_measurement_context')) { $this->context = spro_fast_ajax_measurement_context(); }
        $theme = wp_get_theme();
        $setup = array('plugins' => $this->loaded, 'theme' => array('stylesheet' => $theme->get_stylesheet(), 'template' => $theme->get_template(), 'version' => $theme->get('Version')), 'spro' => $this->context);
        return array('schema' => 'sspa/ajax-measurement@1', 'group' => $this->config['endpoint_groups'][$selector] ?? array('group' => 'unknown', 'label' => 'Unknown'), 'uuid' => $this->config['uuid'], 'scenario' => $this->config['scenario'],
            'mode' => $this->detail ? 'named-action-detail@1' : 'identity@1', 'detail_requested' => !empty($this->config['detail']), 'peak_memory_bytes' => memory_get_peak_usage(true), 'boundary' => 'mu_observer_to_shutdown',
            'environment' => array('wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'blog_id' => get_current_blog_id()),
            'setup' => $setup, 'setup_hash' => hash('sha256', wp_json_encode($setup)), 'inventory_at_start' => $this->config['inventory'],
            'activity' => $this->detail ? $this->detail->result() : null, 'browser_elapsed_ms' => null);
    }
}
