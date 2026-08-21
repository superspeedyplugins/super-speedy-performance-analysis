<?php
defined('ABSPATH') || exit;

/** Durable run queue: immutable job rows plus one small mutable state row. */
class SSPA_Run_Queue {
    public static function save($run_id, $queue) {
        global $wpdb;
        $run_id = (int) $run_id;
        $jobs = isset($queue['jobs']) && is_array($queue['jobs']) ? array_values($queue['jobs']) : array();
        $idx = isset($queue['idx']) ? max(0, (int) $queue['idx']) : 0;
        unset($queue['jobs'], $queue['idx']);
        $jobs_table = SSPA_Schema::table('run_jobs');
        $existing = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE run_id = %d', $jobs_table, $run_id));
        for ($position = $existing; $position < count($jobs); $position++) {
            $wpdb->insert($jobs_table, array('run_id' => $run_id, 'position' => $position, 'status' => $position < $idx ? 'done' : 'queued', 'job_blob' => wp_json_encode($jobs[$position]), 'created_at' => gmdate('Y-m-d H:i:s')));
        }
        if ($idx > 0) {
            $wpdb->query($wpdb->prepare("UPDATE %i SET status='done', finished_at=COALESCE(finished_at,UTC_TIMESTAMP()) WHERE run_id=%d AND position<%d AND status<>'done'", $jobs_table, $run_id, $idx));
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO %i (run_id,job_cursor,total_jobs,state_blob,started_at,last_progress) VALUES (%d,%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE job_cursor=VALUES(job_cursor),total_jobs=VALUES(total_jobs),state_blob=VALUES(state_blob),last_progress=VALUES(last_progress)",
            SSPA_Schema::table('run_queues'), $run_id, $idx, count($jobs), wp_json_encode($queue),
            gmdate('Y-m-d H:i:s', isset($queue['started_at']) ? (int) $queue['started_at'] : time()),
            gmdate('Y-m-d H:i:s', isset($queue['last_progress']) ? (int) $queue['last_progress'] : time())
        ));
        return true;
    }

    public static function get($run_id) {
        global $wpdb;
        $state = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id=%d', SSPA_Schema::table('run_queues'), (int) $run_id), ARRAY_A);
        if (!$state) {
            return null;
        }
        $queue = json_decode((string) $state['state_blob'], true);
        $queue = is_array($queue) ? $queue : array();
        $queue['idx'] = (int) $state['job_cursor'];
        $queue['jobs'] = array();
        foreach ($wpdb->get_col($wpdb->prepare('SELECT job_blob FROM %i WHERE run_id=%d ORDER BY position', SSPA_Schema::table('run_jobs'), (int) $run_id)) as $blob) {
            $job = json_decode((string) $blob, true);
            $queue['jobs'][] = is_array($job) ? $job : array();
        }
        return $queue;
    }

    public static function delete($run_id) {
        global $wpdb;
        $wpdb->delete(SSPA_Schema::table('run_jobs'), array('run_id' => (int) $run_id));
        $wpdb->delete(SSPA_Schema::table('run_queues'), array('run_id' => (int) $run_id));
    }

    public static function discard_legacy_options() {
        global $wpdb;
        $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sspa_queue_%'");
        foreach ($names as $name) {
            $run_id = (int) substr($name, strlen('sspa_queue_'));
            if ($run_id) {
                $wpdb->query($wpdb->prepare("UPDATE %i SET status='failed',finished=%s,notes=%s WHERE id=%d AND status='crawling'", SSPA_Schema::table('runs'), gmdate('Y-m-d H:i:s'), 'Queue storage upgraded; restart this interrupted pre-1.0 analysis.', $run_id));
            }
            delete_option($name);
        }
    }
}
