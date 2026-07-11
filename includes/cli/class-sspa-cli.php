<?php
defined('ABSPATH') || exit;

/**
 * WP-CLI surface - agents SSH'd into a box, CI pipelines, and the e2e tests all drive
 * the plugin this way. Mirrors the Abilities API surface.
 */
class SSPA_CLI {

    /**
     * Run a performance analysis and wait for it to finish.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : baseline (default, all key pages), spot, deep (culprit isolation) or cache_impact.
     *
     * [--pages=<keys>]
     * : Comma-separated page keys to limit the run to (e.g. home,shop).
     *
     * [--suspects=<slugs>]
     * : deep only - comma-separated plugin slugs to isolate.
     *
     * [--include-writes]
     * : Also profile save/order cascades against temporary objects.
     *
     * [--porcelain]
     * : Output only the run id.
     *
     * ## EXAMPLES
     *
     *     wp sspa run
     *     wp sspa run --type=spot --pages=home,shop
     *     wp sspa run --type=deep --suspects=some-plugin
     */
    public function run($args, $assoc_args) {
        $type = isset($assoc_args['type']) ? $assoc_args['type'] : 'baseline';
        if (!in_array($type, array('baseline', 'spot', 'deep', 'cache_impact'), true)) {
            WP_CLI::error('Unknown type. Use baseline, spot, deep or cache_impact.');
        }
        $start_args = array('type' => $type, 'trigger' => 'cli');
        if (!empty($assoc_args['pages'])) {
            $start_args['page_keys'] = array_map('trim', explode(',', $assoc_args['pages']));
            if ('baseline' === $type) {
                $start_args['type'] = 'spot';
            }
        }
        if (!empty($assoc_args['suspects'])) {
            $start_args['suspects'] = array_map('trim', explode(',', $assoc_args['suspects']));
            $start_args['bisect'] = false;
        }
        if (!empty($assoc_args['include-writes'])) {
            $start_args['include_writes'] = true;
        }

        $run_id = SSPA_Run_Controller::start($start_args);
        if (is_wp_error($run_id)) {
            WP_CLI::error($run_id->get_error_message());
        }

        $deadline = time() + 30 * MINUTE_IN_SECONDS;
        $last_done = -1;
        do {
            SSPA_Run_Controller::process_batch($run_id);
            $status = SSPA_Run_Controller::status($run_id);
            if ($status && $status['done'] !== $last_done && empty($assoc_args['porcelain'])) {
                WP_CLI::log(sprintf('%d/%d %s', $status['done'], $status['total'], $status['current'] ? '- ' . $status['current'] : ''));
                $last_done = $status['done'];
            }
        } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

        if (!$status || 'done' !== $status['status']) {
            WP_CLI::error('Run finished with status: ' . ($status ? $status['status'] : 'unknown'));
        }
        if (!empty($assoc_args['porcelain'])) {
            WP_CLI::line($run_id);
            return;
        }
        WP_CLI::success(sprintf('Run %d complete (%d pages/measurements).', $run_id, $status['total']));
        if ('deep' !== $type) {
            $report = SSPA_Report::build($run_id === SSPA_Report::latest_done_run_id() ? $run_id : 0);
            if (!is_wp_error($report) && $report['score'] !== null) {
                WP_CLI::log('Site score: ' . $report['score'] . ' - ' . count($report['findings']) . ' finding(s). Run `wp sspa report` for detail.');
            }
        }
    }

    /**
     * Show the current/latest run status.
     *
     * [--format=<format>]
     * : table (default) or json.
     */
    public function status($args, $assoc_args) {
        $active = SSPA_Run_Controller::active_run_id();
        $run_id = $active ? $active : SSPA_Report::latest_done_run_id();
        $status = $run_id ? SSPA_Run_Controller::status($run_id) : null;
        if (!$status) {
            WP_CLI::log('No runs yet.');
            return;
        }
        if (isset($assoc_args['format']) && 'json' === $assoc_args['format']) {
            WP_CLI::line(wp_json_encode($status));
            return;
        }
        WP_CLI\Utils\format_items('table', array($status), array('run_id', 'status', 'done', 'total', 'current'));
    }

    /**
     * List findings from the latest (or given) completed run.
     *
     * [--run=<id>]
     * : Specific run id.
     *
     * [--format=<format>]
     * : table (default) or json.
     */
    public function findings($args, $assoc_args) {
        $run_id = !empty($assoc_args['run']) ? (int) $assoc_args['run'] : SSPA_Report::latest_done_run_id();
        if (!$run_id) {
            WP_CLI::error('No completed run found.');
        }
        $findings = SSPA_Report::findings($run_id);
        if (isset($assoc_args['format']) && 'json' === $assoc_args['format']) {
            WP_CLI::line(wp_json_encode($findings));
            return;
        }
        $rows = array_map(function ($f) {
            return array(
                'severity' => $f['severity'],
                'type' => $f['type'],
                'component' => $f['component'],
                'page' => $f['page_key'],
                'headline' => $f['headline'],
            );
        }, $findings);
        WP_CLI\Utils\format_items('table', $rows, array('severity', 'type', 'component', 'page', 'headline'));
    }

    /**
     * List measured per-plugin impacts from deep-analysis runs.
     *
     * [--format=<format>]
     * : table (default) or json.
     */
    public function impacts($args, $assoc_args) {
        $impacts = SSPA_Report::impacts();
        if (isset($assoc_args['format']) && 'json' === $assoc_args['format']) {
            WP_CLI::line(wp_json_encode($impacts));
            return;
        }
        if (!$impacts) {
            WP_CLI::log('No measured impacts yet - run `wp sspa run --type=deep`.');
            return;
        }
        WP_CLI\Utils\format_items('table', $impacts, array('plugin', 'page_key', 'method', 'delta_generation_ms', 'delta_sql_ms', 'delta_queries', 'confidence'));
    }

    /**
     * Output the full agent-facing report as JSON.
     *
     * [--run=<id>]
     * : Specific run id (defaults to the latest completed run).
     */
    public function report($args, $assoc_args) {
        $report = SSPA_Report::build(!empty($assoc_args['run']) ? (int) $assoc_args['run'] : 0);
        if (is_wp_error($report)) {
            WP_CLI::error($report->get_error_message());
        }
        WP_CLI::line(wp_json_encode($report));
    }
}
