<?php
// Test transport only. Construction and real PHPMailer completion hooks still run.
// Only on this plugin's isolated test sites: the default scenario `tests` and any `tests-*`.
if (!defined('ABSPATH') || !preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) return;
add_action('phpmailer_init', static function ($mailer) {
    $port = (int) get_option('sspa_regression_smtp_port');
    if (!$port) throw new RuntimeException('Local regression SMTP port is required');
    $mailer->isSMTP();
    $mailer->Host = '127.0.0.1';
    $mailer->Port = $port;
    $mailer->SMTPAuth = false;
    $mailer->SMTPAutoTLS = false;
    $mailer->SMTPSecure = '';
    $mailer->Timeout = 3;
}, PHP_INT_MAX);
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($pre !== false) return $pre;
    $host = wp_parse_url($url, PHP_URL_HOST);
    if ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with((string) $host, '.localhost')) return false;
    return new WP_Error('sspa_regression_external_blocked', 'External HTTP is disabled on this synthetic regression site');
}, PHP_INT_MAX, 3);
// Deliberately refusing local collector boundary: registration succeeds locally, reservation
// receives a503 so the real immutable outbox retry path remains inspectable.
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (!get_option('sspa_fleet_receiver')) return $pre;
    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!str_contains((string) $path, '/installations') && !str_contains((string) $path, '/submissions')) return $pre;
    $payload = json_decode($args['body'] ?? '', true);
    $log = get_option('sspa_fleet_receiver_log', array());
    $log[] = array('path' => $path, 'payload' => str_ends_with($path, '/installations') ? array('registration' => true) : $payload);
    update_option('sspa_fleet_receiver_log', $log, false);
    if (str_ends_with($path, '/installations')) return array('response' => array('code' => 200), 'headers' => array(), 'body' => wp_json_encode(array('registered' => true, 'install_uuid' => $payload['install_uuid'])), 'cookies' => array());
    return array('response' => array('code' => 503), 'headers' => array(), 'body' => wp_json_encode(array('error' => array('code' => 'synthetic_local_refusal'))), 'cookies' => array());
}, 1, 3);

// Exact local observation samples make the synthetic request-count assertion deterministic.
add_filter('sspa_traffic_origin_sample_modulus', static function () { return 1; });
// Observe the genuine temporary marker before production removes it on successful Trash.
add_action('shutdown', static function () {
    if (!get_option('sspa_fleet_enabled') || !isset($GLOBALS['sspa_capture']) || !function_exists('wc_get_orders')) return;
    $seen = get_option('sspa_fleet_marked_orders', array());
    foreach (wc_get_orders(array('limit' => 10, 'meta_key' => '_sspa_temp', 'meta_value' => '1')) as $order) {
        $seen[$order->get_id()] = $order->get_meta('_sspa_temp');
    }
    update_option('sspa_fleet_marked_orders', $seen, false);
}, PHP_INT_MAX);
