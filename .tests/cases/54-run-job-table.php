<?php
defined('ABSPATH') || exit;
function sspa_54_t($ok, $message) { echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n"; }
global $wpdb;
$run_id = 540000 + wp_rand(1, 9999);
$queue = array('jobs' => array(
    array('page_key' => 'one', 'url' => home_url('/one'), 'variant' => 'anon'),
    array('page_key' => 'two', 'url' => home_url('/two'), 'variant' => 'anon'),
), 'idx' => 0, 'user_id' => 1, 'transport' => 'loopback', 'started_at' => time(), 'last_progress' => time());
SSPA_Run_Queue::save($run_id, $queue);
$ids_before = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE run_id=%d ORDER BY position', SSPA_Schema::table('run_jobs'), $run_id));
$loaded = SSPA_Run_Queue::get($run_id);
sspa_54_t(count($ids_before) === 2 && $loaded['jobs'] === $queue['jobs'], 'jobs are durable immutable rows and reload in order');
$queue['idx'] = 1;
SSPA_Run_Queue::save($run_id, $queue);
$ids_after = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE run_id=%d ORDER BY position', SSPA_Schema::table('run_jobs'), $run_id));
$statuses = $wpdb->get_col($wpdb->prepare('SELECT status FROM %i WHERE run_id=%d ORDER BY position', SSPA_Schema::table('run_jobs'), $run_id));
sspa_54_t($ids_before === $ids_after, 'advancing progress does not rewrite immutable job rows');
sspa_54_t($statuses === array('done', 'queued'), 'cursor advancement records per-job state');
sspa_54_t(false === get_option('sspa_queue_' . $run_id), 'new queues never write an option');
$queue['jobs'][] = array('page_key' => 'three', 'url' => home_url('/three'), 'variant' => 'anon');
SSPA_Run_Queue::save($run_id, $queue);
sspa_54_t(3 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE run_id=%d', SSPA_Schema::table('run_jobs'), $run_id)), 'phase extension appends one job row');
SSPA_Run_Queue::delete($run_id);
sspa_54_t(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE run_id=%d', SSPA_Schema::table('run_jobs'), $run_id)), 'terminal cleanup removes queue rows');
