<?php
require_once __DIR__ . '/native-guard.php';
function sspa_retained_delete($table, $where) {
    global $wpdb;
    if (false === $wpdb->delete($table, $where)) throw new RuntimeException($wpdb->last_error);
}
// Reset only identifiers saved by this same case, at its next entry. Never an END cleanup.
function sspa_retained_reset($case) {
    global $wpdb;
    $saved = (array)get_option('sspa_retained_' . $case, array());
    foreach ((array)($saved['runs'] ?? array()) as $run_id) {
        $run_id = (int)$run_id;
        foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . SSPA_Schema::table('submission_outbox') . ' WHERE run_id=%d', $run_id)) as $outbox_id) {
            sspa_retained_delete(SSPA_Schema::table('submission_events'), array('outbox_id' => (int)$outbox_id));
        }
        foreach (array('profiles','component_stats','findings','submission_outbox','run_jobs') as $table) sspa_retained_delete(SSPA_Schema::table($table), array('run_id' => $run_id));
        sspa_retained_delete(SSPA_Schema::table('plugin_impacts'), array('test_run_id' => $run_id));
        sspa_retained_delete(SSPA_Schema::table('runs'), array('id' => $run_id));
    }
    foreach ((array)($saved['outbox'] ?? array()) as $id) { sspa_retained_delete(SSPA_Schema::table('submission_events'), array('outbox_id'=>(int)$id)); sspa_retained_delete(SSPA_Schema::table('submission_outbox'), array('id'=>(int)$id)); }
    foreach ((array)($saved['terms'] ?? array()) as $term) wp_delete_term((int)$term[0], $term[1]);
    foreach ((array)($saved['posts'] ?? array()) as $id) wp_delete_post((int)$id, true);
    foreach ((array)($saved['site_metrics'] ?? array()) as $id) sspa_retained_delete(SSPA_Schema::table('site_metrics'), array('id' => (int)$id));
    update_option('sspa_retained_' . $case, array(), false);
    if ($saved) echo 'ENTRY RESET: cleared recorded prior case ' . $case . ' data: ' . wp_json_encode($saved) . "\n";
}
function sspa_retained_save($case, $records) {
    update_option('sspa_retained_' . $case, $records, false);
    echo 'RETAINED: case ' . $case . ' fixture identifiers for inspection and next-entry reset' . "\n";
}
