<?php
// Real captured SQL -> duplicate finding -> local preview and retained-archive checks.
// Every submission HTTP call is intercepted before transport.
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
wp_set_current_user(1);
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents(WPMU_PLUGIN_DIR . '/sspa-export-review-fixture.php', <<<'FIXTURE'
<?php
add_action('template_redirect', static function () {
    if (!is_front_page() || !get_option('zz_sspa_export_review', false)) return;
    global $wpdb;
    $previous_errors = $wpdb->suppress_errors(true);
    $query_errors = array();
    foreach (array(
        'SELECT 1 AS block_probe /* review-canary@example.invalid */',
        "SELECT 2 AS dash_probe -- review-canary@example.invalid\n",
        "SELECT 3 AS hash_probe # review-canary@example.invalid\n",
        'SELECT 0x726576696577 AS hex_probe',
        'SELECT 0b10110101 AS binary_probe',
        'SELECT 1234e5 AS exponent_probe',
        "SELECT 'synthetic_opaque_string_canary",
        'SELECT 1 /* synthetic_opaque_comment_canary',
    ) as $sql) {
        for ($i = 0; $i < 6; $i++) $wpdb->get_var($sql);
        $query_errors[$sql] = '' !== $wpdb->last_error;
    }
    $wpdb->suppress_errors($previous_errors);
    update_option('zz_sspa_export_review_errors', $query_errors, false);
});
FIXTURE
);
update_option('zz_sspa_export_review', 1, true);
wp_cache_flush();
$run = (int) sspa_fleet_cli(array('sspa', 'run', '--pages=home', '--porcelain'));
sspa_fleet_assert($run > 0, 'normal Home analysis completes');
$findings = SSPA_Report::findings($run);
$dupes = array_filter($findings, static function ($finding) { return 'dupe_queries' === $finding['type']; });
foreach (array('block_probe', 'dash_probe', 'hash_probe', 'hex_probe', 'binary_probe', 'exponent_probe') as $alias) {
    sspa_fleet_assert(false !== strpos(wp_json_encode($dupes), $alias), 'real duplicate finding captures ' . $alias);
}
wp_cache_delete('zz_sspa_export_review_errors', 'options');
wp_cache_delete('notoptions', 'options');
$actual_errors = get_option('zz_sspa_export_review_errors', array());
foreach (array("SELECT 'synthetic_opaque_string_canary", 'SELECT 1 /* synthetic_opaque_comment_canary') as $failed_sql) {
    sspa_fleet_assert(!empty($actual_errors[$failed_sql]) && false !== strpos(wp_json_encode($dupes), substr($failed_sql, strpos($failed_sql, 'synthetic_'))), 'real failed SQL is captured as duplicate evidence');
}
$payload = SSPA_Community_Exporter::build($run, null, null, 'manual');
sspa_fleet_assert(!is_wp_error($payload), 'local manual preview succeeds after removing private SQL values');
$json = is_wp_error($payload) ? '' : wp_json_encode($payload);
sspa_fleet_assert('' !== $json && false === strpos($json, 'review-canary@example.invalid'), 'preview excludes SQL comment values');
sspa_fleet_assert('' !== $json && false === strpos($json, 'synthetic_opaque_string_canary') && false === strpos($json, 'synthetic_opaque_comment_canary'), 'preview removes values from unterminated failed SQL tokens');
sspa_fleet_assert('' !== $json && false === strpos($json, '0x726576696577'), 'preview excludes captured hexadecimal data literals');
sspa_fleet_assert(false !== strpos($json, 'SELECT ? AS hex_probe'), 'preview preserves the hexadecimal query structure');
sspa_fleet_assert('' !== $json && false === strpos($json, '0b10110101') && false !== strpos($json, 'SELECT ? AS binary_probe'), 'preview normalises captured binary literals');
sspa_fleet_assert('' !== $json && false === strpos($json, '1234e5') && false !== strpos($json, 'SELECT ? AS exponent_probe'), 'preview normalises captured exponent literals');
foreach (array(
    array('sspa_privacy_forbidden_key', array('email' => 'synthetic')),
    array('sspa_privacy_forbidden_value', array('fingerprint' => 'review-canary@example.invalid')),
    array('sspa_privacy_forbidden_value', array('nested' => (object) array('fingerprint' => 'https://example.invalid/private'))),
) as $control) {
    $error = SSPA_Community_Privacy::validate(array('evidence' => (object) $control[1]));
    sspa_fleet_assert(is_wp_error($error) && $control[0] === $error->get_error_code(), 'object-shaped evidence rejects ' . $control[0]);
}
$safe = array('evidence' => (object) array('count' => 6, 'fingerprint' => 'SELECT ? AS hex_probe'));
sspa_fleet_assert(true === SSPA_Community_Privacy::validate($safe), 'safe object-shaped evidence remains valid');
$empty = array('evidence' => (object) SSPA_Community_Privacy::finding_evidence(array()));
sspa_fleet_assert(true === SSPA_Community_Privacy::validate($empty) && '{"evidence":{}}' === wp_json_encode($empty), 'empty finding keeps the receiver JSON object contract');
require_once SSPA_PLUGIN_DIR . 'profiler/fingerprint.php';
foreach (array(
    "SELECT '/* retained only as literal */', '-- text', '# text'" => 'SELECT ?, ?, ?',
    'SELECT `a/*b*/`, `a-- b`, `a#b`, `field12` FROM `table12` WHERE id=12' => 'SELECT `a/*b*/`, `a-- b`, `a#b`, `field12` FROM `table12` WHERE id=?',
    "SELECT 'it''s /* quoted */', \"a\\\"#b\" /* removed */ FROM t" => 'SELECT ?, ? FROM t',
    'SELECT 1--2' => 'SELECT ?--?',
    "SELECT/* 'unmatched */1" => 'SELECT ?',
    "SELECT 'opaque\\" => 'SELECT ?',
    'SELECT "opaque' => 'SELECT ?',
    'SELECT 1 /* opaque' => 'SELECT ?',
) as $sql => $expected) {
    sspa_fleet_assert($expected === sspa_sql_fingerprint($sql), 'SQL lexical boundaries: ' . $expected);
}
$long_sql = 'SELECT ' . str_repeat('column1, ', 109) . '`abc/*note*/tail` FROM t';
$long_fp = SSPA_Community_Privacy::sql_fingerprint($long_sql);
sspa_fleet_assert(strlen($long_fp) <= 1000 && $long_fp === SSPA_Community_Privacy::sql_fingerprint($long_fp), 'long query projection remains canonical across quoted-identifier truncation');
// Replay an actual finding from the pre-fix candidate's local captured archive.
// The fixture contains only synthetic SQL and its measured count/time.
$legacy_finding = json_decode(file_get_contents(SSPA_PLUGIN_DIR . '.tests/fixtures/legacy-comment-finding.json'));
$no_transport = static function () { return new WP_Error('local_only', 'No network transmission'); };
add_filter('pre_http_request', $no_transport, PHP_INT_MAX);
$row = SSPA_Community_Outbox::queue_run($run, 'manual');
remove_filter('pre_http_request', $no_transport, PHP_INT_MAX);
if (is_wp_error($row)) throw new RuntimeException($row->get_error_message());
$requests = array();
$upload_bytes = null;
$intercept = static function ($pre, $args, $url) use (&$requests, &$upload_bytes, $row) {
    $requests[] = $args['method'];
    if ('PUT' === $args['method']) {
        $upload_bytes = $args['body'];
        return new WP_Error('local_only', 'No network transmission');
    }
    $body = false !== strpos($url, '/installations')
        ? array('registered' => true, 'install_uuid' => SSPA_Community_Identity::install_uuid())
        : array('submission_uuid' => $row['submission_uuid'], 'reservation_uuid' => '11111111-1111-4111-8111-111111111111', 'storage_status' => 'awaiting_upload', 'upload_url' => 'https://00000000000000000000000000000000.r2.cloudflarestorage.com/synthetic', 'required_headers' => array('Content-Type' => 'application/gzip'));
    return array('response' => array('code' => 200), 'headers' => array(), 'body' => wp_json_encode($body), 'cookies' => array());
};
$collector = static function () { return 'https://collector.example.org'; };
$secret = static function () { return str_repeat('a', 64); };
add_filter('pre_option_sspa_collector_url', $collector);
add_filter('pre_option_sspa_collector_secret_' . md5('https://collector.example.org'), $secret);
add_filter('pre_http_request', $intercept, PHP_INT_MAX, 3);
try {
    foreach (array(
        'legacy captured finding' => wp_json_encode(array('evidence' => $legacy_finding)),
        'opaque legacy comment' => wp_json_encode(array('evidence' => (object) array('fingerprint' => 'SELECT ? /* synthetic-private-note */'))),
        'legacy unterminated string' => wp_json_encode(array('evidence' => (object) array('fingerprint' => "SELECT 'synthetic_opaque_string_canary"))),
        'legacy unterminated comment' => wp_json_encode(array('evidence' => (object) array('fingerprint' => 'SELECT ? /* synthetic_opaque_comment_canary'))),
        'legacy hex literal' => wp_json_encode(array('evidence' => (object) array('fingerprint' => 'SELECT 0x726576696577'))),
        'malformed JSON' => '{invalid',
    ) as $label => $stored_json) {
        $unsafe_row = $row;
        $unsafe_row['payload_gzip'] = gzencode($stored_json);
        $unsafe_row['compressed_bytes'] = strlen($unsafe_row['payload_gzip']);
        $unsafe_row['uncompressed_bytes'] = strlen($stored_json);
        $unsafe_row['payload_sha256'] = hash('sha256', $unsafe_row['payload_gzip']);
        $requests = array();
        $result = SSPA_Community_Client::submit($unsafe_row);
        sspa_fleet_assert(is_wp_error($result) && !empty($result->get_error_data()['permanent']) && !$requests, $label . ' rejects before any HTTP request');
    }
    $requests = array();
    $result = SSPA_Community_Client::submit($row);
    sspa_fleet_assert(is_wp_error($result) && 'sspa_upload_network_error' === $result->get_error_code() && in_array('PUT', $requests, true) && $row['payload_gzip'] === $upload_bytes, 'valid archive reaches intercepted upload with identical stored bytes');
    $retained = SSPA_Community_Outbox::get($row['id']);
    sspa_fleet_assert($row['payload_gzip'] === $retained['payload_gzip'] && $row['payload_sha256'] === $retained['payload_sha256'], 'archive bytes and identity remain unchanged');
} finally {
    remove_filter('pre_http_request', $intercept, PHP_INT_MAX);
    remove_filter('pre_option_sspa_collector_url', $collector);
    remove_filter('pre_option_sspa_collector_secret_' . md5('https://collector.example.org'), $secret);
}
sspa_fleet_done();
