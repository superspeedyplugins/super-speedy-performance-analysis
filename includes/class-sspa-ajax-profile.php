<?php
defined('ABSPATH') || exit;
/** Local first-class AJAX windows. Admin exercises real workflows; no endpoint is replayed. */
class SSPA_Ajax_Profile {
    public static $pending = null;
    const OPTION = 'sspa_ajax_windows';
    public static function windows() { return (array) get_option(self::OPTION, array()); }
    public static function start($args = array()) {
        if (SSPA_Traffic_Collection::active()) { return new WP_Error('sspa_ajax_busy', 'Stop the active traffic collection before starting an AJAX window.'); }
        $scenario = sanitize_text_field(isset($args['scenario']) ? $args['scenario'] : '');
        if (!$scenario || strlen($scenario) > 80) { return new WP_Error('sspa_ajax_scenario', 'Enter a scenario label of 1–80 characters without personal data.'); }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $inventory = array(); foreach (get_plugins() as $file => $plugin) { $inventory[$file] = $plugin['Version']; } ksort($inventory);
        $endpoints = array();
        foreach ((array) ($args['endpoints'] ?? array()) as $endpoint) {
            if (!is_string($endpoint) || strlen($endpoint) > 510 || !preg_match('/^(admin_ajax|wc_ajax|rest):[^\r\n]+$/D', $endpoint)) { return new WP_Error('sspa_ajax_endpoint', 'An endpoint selection is invalid.'); }
            $endpoints[] = $endpoint;
        }
        $endpoint_groups = array();
        if (class_exists('SPRO_Fast_Ajax_Advice')) {
            $known = SSPA_Report::endpoint_evidence();
            if (!is_wp_error($known)) {
                foreach ($known['endpoints'] as $entry) {
                    $id = $entry['identity']; $endpoint = $id['action'] ?: $id['route_pattern'];
                    $endpoint_groups[$id['transport'] . ':' . $endpoint] = SPRO_Fast_Ajax_Advice::endpoint(array('transport' => $id['transport'], 'endpoint' => $endpoint, 'method' => $id['method'], 'context' => $id['auth_context']));
                }
            }
        }
        self::$pending = array('endpoint_groups' => $endpoint_groups, 'uuid' => wp_generate_uuid4(), 'scenario' => $scenario, 'detail' => !empty($args['detail']), 'inventory' => $inventory, 'endpoints' => array_values(array_unique($endpoints)));
        $result = SSPA_Traffic_Collection::start('15m', 'ajax-profile');
        if (is_wp_error($result)) { self::$pending = null; return $result; }
        $collection = SSPA_Traffic_Collection::latest();
        $window = self::$pending + array('collection_id' => (int) $collection['id'], 'created' => gmdate('c'), 'label' => sanitize_text_field($args['label'] ?? $scenario));
        self::$pending = null;
        $windows = self::windows(); $windows[$window['uuid']] = $window;
        update_option(self::OPTION, $windows, false);
        return $window;
    }
    public static function stop($uuid) {
        $windows = self::windows();
        if (!isset($windows[$uuid])) { return new WP_Error('sspa_ajax_window', 'AJAX window not found.'); }
        return SSPA_Traffic_Collection::stop($windows[$uuid]['collection_id'], true);
    }
    public static function rows($window) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT id, transport, endpoint, http_method, auth_context, observed_at, status_code, wall_ms, measurement_json FROM %i WHERE collection_id = %d AND measurement_json IS NOT NULL ORDER BY id ASC LIMIT 10000', SSPA_Schema::table('traffic_endpoint_observations'), $window['collection_id']), ARRAY_A);
    }
    public static function compare($before_uuid, $after_uuid) {
        $windows = self::windows();
        if (!isset($windows[$before_uuid], $windows[$after_uuid])) { return new WP_Error('sspa_ajax_pair', 'Select two saved AJAX windows.'); }
        if ($before_uuid === $after_uuid) { return new WP_Error('sspa_ajax_same', 'Before and after must be different windows.'); }
        $pages = array(); $periods = array();
        foreach (array('previous' => $before_uuid, 'current' => $after_uuid) as $side => $uuid) {
            $last_setup = array();
            foreach (self::rows($windows[$uuid]) as $row) {
                $capture = json_decode($row['measurement_json'], true);
                if (!self::valid_capture($capture, $uuid)) {
                    return new WP_Error('sspa_ajax_capture_invalid', 'A saved AJAX request has incomplete or inconsistent measurement provenance. This comparison cannot be calculated.');
                }
                $identity = array($row['transport'], $row['endpoint'], $row['http_method'], $row['auth_context'], $capture['scenario'], $capture['mode'], $capture['detail_requested'] ?? false, $capture['boundary'], $capture['environment']);
                $key = hash('sha256', wp_json_encode($identity));
                if (!isset($pages[$key])) {
                    $empty = array('points' => array(), 'faults' => array(), 'setups' => array());
                    $pages[$key] = array('group' => $capture['group'] ?? array('group' => 'unknown', 'label' => 'Unknown'), 'key' => $key, 'label' => $row['transport'] . ':' . $row['endpoint'] . ' · ' . $capture['scenario'], 'method' => $row['http_method'], 'variant' => $row['auth_context'], 'object_cache_mode' => $capture['mode'], 'previous' => $empty, 'current' => $empty);
                }
                $setup = $capture['setup_hash'];
                if (($last_setup[$key] ?? null) !== $setup) { $periods[$side][$key][] = array('setup_hash' => $setup, 'from' => (int) $row['observed_at'], 'setup' => $capture['setup']); $last_setup[$key] = $setup; }
                $pages[$key][$side]['setups'][$setup] = $capture['setup'];
                $status = (int) $row['status_code']; $ok = $status >= 200 && $status < 400;
                $point = array('value' => (float) $row['wall_ms'], 'run_id' => $windows[$uuid]['collection_id'], 'sample' => (int) $row['id'], 'response_code' => $status, 'state' => $ok ? 'measured' : 'http_error', 'at' => gmdate('c', (int) $row['observed_at']), 'evidence' => $capture);
                $pages[$key][$side][$ok ? 'points' : 'faults'][] = $point;
            }
        }
        foreach ($pages as &$page) {
            foreach (array('previous', 'current') as $side) {
                $page[$side] += SSPA_Measurement_Math::distribution(array_column($page[$side]['points'], 'value'));
                $page[$side]['errors'] = count($page[$side]['faults']);
            }
            $mixed = count($page['previous']['setups']) > 1 || count($page['current']['setups']) > 1;
            $page['delta'] = SSPA_Measurement_Math::delta($mixed ? null : $page['previous']['median'], $mixed ? null : $page['current']['median']);
            $page['warning'] = $mixed ? 'Setup changed inside a window. Points and periods are retained; start a new stable window for a headline comparison.' : (!$page['previous']['samples'] || !$page['current']['samples'] ? 'Unmatched scenario or insufficient successful evidence.' : 'Observational comparison; verify functional behaviour and concurrent configuration changes.');
        } unset($page);
        return array('schema' => 'sspa/ajax-series@1', 'measurement_kind' => 'ajax', 'selection_mode' => 'pair', 'before' => $windows[$before_uuid], 'after' => $windows[$after_uuid], 'metric' => array('key' => 'request_wall_ms', 'label' => 'Server request time', 'unit' => 'ms', 'description' => 'MU observer entry to shutdown; not browser elapsed time or endpoint-handler time.'), 'pages' => array_values($pages), 'periods' => $periods, 'warnings' => array('Successful response timings use nearest-rank median and p95. Errors remain separate. A fast HTTP 200 does not prove functional success.', 'No combined group statistic: traffic mixes can differ. Chart is local only.'));
    }
    public static function comparisons() { return (array) get_option('sspa_ajax_comparisons', array()); }
    private static function valid_capture($capture, $uuid) {
        if (!is_array($capture) || ($capture['schema'] ?? '') !== 'sspa/ajax-measurement@1'
            || ($capture['uuid'] ?? '') !== $uuid || !is_string($capture['scenario'] ?? null)
            || '' === $capture['scenario'] || ($capture['boundary'] ?? '') !== 'mu_observer_to_shutdown'
            || !in_array($capture['mode'] ?? '', array('identity@1', 'named-action-detail@1'), true)
            || !is_array($capture['environment'] ?? null) || !is_array($capture['setup'] ?? null)
            || !is_array($capture['setup']['plugins'] ?? null) || !is_array($capture['setup']['theme'] ?? null)
            || !array_key_exists('spro', $capture['setup'])
            || !is_string($capture['setup_hash'] ?? null)) { return false; }
        foreach (array('wordpress', 'php', 'blog_id') as $field) {
            if (!isset($capture['environment'][$field]) || '' === (string) $capture['environment'][$field]) { return false; }
        }
        return hash_equals(hash('sha256', wp_json_encode($capture['setup'])), $capture['setup_hash']);
    }
    public static function ajax() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permission denied.', 403); }
        $op = sanitize_key($_POST['operation'] ?? '');
        if ($op === 'start') { $result = self::start(wp_unslash($_POST)); }
        elseif ($op === 'stop') { $result = self::stop(sanitize_text_field(wp_unslash($_POST['uuid'] ?? ''))); }
        elseif ($op === 'compare') {
            $before = sanitize_text_field(wp_unslash($_POST['before'] ?? '')); $after = sanitize_text_field(wp_unslash($_POST['after'] ?? ''));
            $result = self::compare($before, $after);
            $name = sanitize_text_field(wp_unslash($_POST['comparison_name'] ?? ''));
            if (!is_wp_error($result) && $name !== '') {
                if (strlen($name) > 80) { wp_send_json_error('Comparison names must be at most 80 characters.'); }
                $comparisons = self::comparisons(); $id = hash('sha256', $before . $after . $name);
                $comparisons[$id] = array('name' => $name, 'before' => $before, 'after' => $after); update_option('sspa_ajax_comparisons', $comparisons, false);
            }
        }
        else { $result = new WP_Error('sspa_ajax_operation', 'Unknown operation.'); }
        if (is_wp_error($result)) { wp_send_json_error($result->get_error_message()); }
        wp_send_json_success($result);
    }
}
