<?php
/**
 * Plugin Name: SSPA regression - superspeedy.org request observer
 * Description: Test fixture only. Logs every WordPress HTTP API request to superspeedy.org and, when told to,
 * answers the public figures documents.
 *
 * Regression sites refuse all external HTTP (local-delivery.php, at PHP_INT_MAX). This runs first:
 *
 * Option sspa_test_ssorg_mode:
 *   (unset)   observe only; the request continues to the regression boundary, which refuses it
 *   'fixture' GET /api/v1/cohorts and /api/v1/plugin-impact are answered from
 *             .tests/fixtures/superspeedy-org/*.json, the bytes the superspeedy.org build emitted
 *   'live'    GET /api/v1/cohorts and /api/v1/plugin-impact are fetched from the real superspeedy.org and
 *             its real status and body are handed back, so the plugin meets what is published today
 * Option sspa_test_ssorg_log: every request, in order, with the answer it received.
 */
defined('ABSPATH') || exit;

add_filter('pre_http_request', function ($preempt, $args, $url) {
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if (false !== $preempt || ('superspeedy.org' !== $host && '.superspeedy.org' !== substr($host, -16))) {
        return $preempt;
    }
    $mode = (string) get_option('sspa_test_ssorg_mode');
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $documents = array('/api/v1/cohorts' => 'cohorts.json', '/api/v1/plugin-impact' => 'plugin-impact.json');
    $entry = array(
        'method' => strtoupper((string) $args['method']), 'url' => $url, 'user_agent' => (string) $args['user-agent'],
        'mode' => $mode, 'code' => null, 'at' => microtime(true),
    );
    $answer = false;
    if (isset($documents[$path]) && 'GET' === $entry['method'] && 'fixture' === $mode) {
        $file = WP_PLUGIN_DIR . '/super-speedy-performance-analysis/.tests/fixtures/superspeedy-org/' . $documents[$path];
        $answer = array(
            'headers' => array('content-type' => 'application/json; charset=utf-8'),
            'body' => file_get_contents($file),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    } elseif (isset($documents[$path]) && 'GET' === $entry['method'] && 'live' === $mode) {
        try {
            $real = \WpOrg\Requests\Requests::get($url, array('User-Agent' => $entry['user_agent']), array('timeout' => 20, 'follow_redirects' => false));
            $answer = array(
                'headers' => array('content-type' => (string) $real->headers['content-type']),
                'body' => (string) $real->body,
                'response' => array('code' => (int) $real->status_code, 'message' => get_status_header_desc((int) $real->status_code)),
                'cookies' => array(),
                'filename' => null,
            );
        } catch (\Throwable $e) {
            $answer = new WP_Error('http_request_failed', $e->getMessage());
        }
    }
    $entry['code'] = is_wp_error($answer) ? $answer->get_error_code() : (is_array($answer) ? $answer['response']['code'] : 'refused-by-regression-boundary');
    $log = get_option('sspa_test_ssorg_log', array());
    $log = is_array($log) ? $log : array();
    $log[] = $entry;
    update_option('sspa_test_ssorg_log', $log, false);
    return $answer;
}, 1, 3);
