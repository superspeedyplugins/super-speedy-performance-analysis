<?php
require_once __DIR__ . "/../lib/retained-fixtures.php";
sspa_retained_reset("54");
defined('ABSPATH') || exit;
function sspa_54_t($ok, $message) { echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n"; }
global $wpdb;
$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => wp_generate_uuid4(),
    'blog_id' => get_current_blog_id(),
    'run_type' => 'baseline',
    'status' => 'crawling',
    'started' => gmdate('Y-m-d H:i:s'),
));
if ($wpdb->last_error) throw new RuntimeException($wpdb->last_error);
$run_id = (int)$wpdb->insert_id;
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
// Finish the fixture through the real controller instead of leaving an active run with no queue.
SSPA_Run_Controller::cancel($run_id);
sspa_54_t(SSPA_Run_Controller::run_row($run_id)['status'] === 'cancelled', 'the controller retains a terminal cancelled run after queue deletion');
sspa_retained_save('54', array('runs'=>array($run_id)));
