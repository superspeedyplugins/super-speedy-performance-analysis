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
     * : Comma-separated page keys to limit the run to (e.g. home,shop). Works for deep runs
     * too, which is how you re-measure one fixed plugin on one page in minutes instead of
     * sweeping every page.
     *
     * [--suspects=<slugs>]
     * : deep only - comma-separated plugin slugs to isolate.
     *
     * [--url=<url>]
     * : deep only - scope the whole sweep to ONE url on this site, even one no analysis has
     * ever profiled. The sweep takes its own baselines for that page, so it needs no prior run.
     *
     * [--no-cache-modes]
     * : deep only - skip the object-cache-off and cache-priming measurements phase 2 would
     * otherwise add for every plugin that shows an impact.
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
     *     wp sspa run --type=deep --suspects=some-plugin --pages=admin-orders-search
     *     wp sspa run --type=deep --suspects=some-plugin --url=https://example.com/product/thing/
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
        }
        if (!empty($assoc_args['url'])) {
            $start_args['url'] = $assoc_args['url'];
        }
        if (isset($assoc_args['cache-modes'])) {
            // WP-CLI turns --no-cache-modes into cache-modes => false.
            $start_args['cache_modes'] = (bool) $assoc_args['cache-modes'];
        }
        if (!empty($assoc_args['include-writes'])) {
            $start_args['include_writes'] = true;
        }

        $run_id = SSPA_Run_Controller::start($start_args);
        if (is_wp_error($run_id)) {
            WP_CLI::error($run_id->get_error_message());
        }

        // A full sweep (deep) legitimately runs for hours - every plugin on every page,
        // in up to three cache modes.
        $deadline = time() + ('deep' === $type ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS);
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
                WP_CLI::log('Site score: ' . $report['score'] . '/100 - ' . count($report['findings']) . ' finding(s). Run `wp sspa report` for detail.');
            }
        }
    }

    /**
     * Measure one complete purchase: what a customer waits through at each step of the
     * checkout funnel, with the full plugin set active and nothing switched off.
     *
     * This BUYS SOMETHING FOR REAL. A real order is created, real emails are sent and real
     * integrations fire; the order is cancelled and deleted afterwards and stock is put
     * back. Use --dry-run first to see exactly what it would set off.
     *
     * ## OPTIONS
     *
     * [--product=<id>]
     * : Product to buy. Defaults to the cheapest purchasable in-stock shippable one.
     *
     * [--repeats=<n>]
     * : Run the flow n times (default 1). Every repeat is another real order.
     *
     * [--payment=<mode>]
     * : no_payment (default) or sandbox. Sandbox needs a supported gateway in test mode.
     *
     * [--mail=<mode>]
     * : deliver (default - real emails, really sent, really timed), construct or suppress.
     *
     * [--no-integrations]
     * : Unhook third-party order callbacks. The number is then not a real-store number.
     *
     * [--no-webhooks]
     * : Do not fire webhooks.
     *
     * [--dry-run]
     * : Print the pre-flight inventory and create nothing.
     *
     * [--porcelain]
     * : Output only the run id.
     *
     * ## EXAMPLES
     *
     *     wp sspa checkout-flow --dry-run
     *     wp sspa checkout-flow
     *     wp sspa checkout-flow --product=47 --repeats=3
     *
     * @subcommand checkout-flow
     */
    public function checkout_flow($args, $assoc_args) {
        if (!class_exists('WooCommerce')) {
            WP_CLI::error('WooCommerce is not active, so there is no checkout to profile.');
        }

        if (!empty($assoc_args['dry-run'])) {
            $crawler = new SSPA_Crawler();
            $sample = $crawler->send_profiled(home_url('/?sspa_flow_probe=1'), array(
                'method' => 'GET',
                'flags' => array('v' => 'guest', 'ck' => 'pre'),
            ));
            if (!is_array($sample['json'])) {
                WP_CLI::error('The pre-flight request could not be measured: ' . ($sample['error'] ? $sample['error'] : 'http ' . $sample['code']));
            }
            WP_CLI::line(wp_json_encode($sample['json']));
            return;
        }

        $start_args = array('type' => 'checkout', 'trigger' => 'cli');
        if (!empty($assoc_args['product'])) {
            $start_args['product_id'] = (int) $assoc_args['product'];
        }
        if (!empty($assoc_args['payment'])) {
            $start_args['payment_mode'] = $assoc_args['payment'];
        }
        if (!empty($assoc_args['mail'])) {
            $start_args['mail_mode'] = $assoc_args['mail'];
        }
        $start_args['allow_integrations'] = empty($assoc_args['no-integrations']);
        $start_args['allow_webhooks'] = empty($assoc_args['no-webhooks']);

        $repeats = max(1, (int) (isset($assoc_args['repeats']) ? $assoc_args['repeats'] : 1));
        $run_ids = array();
        for ($i = 0; $i < $repeats; $i++) {
            $run_id = SSPA_Run_Controller::start($start_args);
            if (is_wp_error($run_id)) {
                WP_CLI::error($run_id->get_error_message());
            }
            $deadline = time() + 15 * MINUTE_IN_SECONDS;
            do {
                SSPA_Run_Controller::process_batch($run_id);
                $status = SSPA_Run_Controller::status($run_id);
            } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

            $run = SSPA_Run_Controller::run_row($run_id);
            $notes = json_decode((string) $run['notes'], true);
            $outcome = is_array($notes) && isset($notes['outcome']) ? $notes['outcome'] : 'unknown';
            if ('ok' !== $outcome) {
                WP_CLI::error(sprintf('Purchase %d did not complete: %s', $i + 1, $outcome));
            }
            $run_ids[] = $run_id;

            if (empty($assoc_args['porcelain'])) {
                $waterfall = SSPA_Checkout_Flow::waterfall($run_id);
                WP_CLI::log(sprintf(
                    'Run %d: customer waited %s ms (at risk %s ms, post-capture %s ms), slowest step %s',
                    $run_id,
                    $waterfall['total_ms'],
                    $waterfall['at_risk_ms'],
                    $waterfall['secured_ms'],
                    $waterfall['slowest']
                ));
            }
        }

        if (!empty($assoc_args['porcelain'])) {
            WP_CLI::line(implode(',', $run_ids));
            return;
        }
        WP_CLI::success(sprintf('%d measured purchase(s). Run `wp sspa findings --run=%d` for detail.', count($run_ids), end($run_ids)));
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
     * Compatibility alias for the cache optimisation analysis produced by a normal analysis.
     *
     * [--run=<id>]
     * : Specific completed run; defaults to latest.
     *
     * [--format=<format>]
     * : summary (default) or json.
     *
     * @subcommand cache-scan
     */
    public function cache_scan($args, $assoc_args) {
        $assessment = SSPA_Cache_Recon::export_data(!empty($assoc_args['run']) ? (int) $assoc_args['run'] : 0);
        if (is_wp_error($assessment)) {
            WP_CLI::error($assessment->get_error_message());
        }
        if (isset($assoc_args['format']) && 'json' === $assoc_args['format']) {
            WP_CLI::line(wp_json_encode($assessment));
            return;
        }
        $a = $assessment['assessment'];
        WP_CLI::log($assessment['headline']);
        WP_CLI::log($assessment['detail']);
        WP_CLI::log(sprintf(
            'Shared-cache status: %s | Review difficulty: %s | Hazards: %d | Candidate components: %d',
            $a['shared_cache_status'],
            $a['difficulty'],
            count((array) $a['hazards']),
            count((array) $a['candidate_components'])
        ));
    }

    /**
     * Download the immediate cache optimisation analysis produced by a normal run.
     *
     * [--run=<id>]
     * : Specific completed run; defaults to latest.
     *
     * [--format=<format>]
     * : summary (default) or json.
     *
     * [--output=<path>]
     * : Write the complete JSON document to this path.
     *
     * @subcommand cache-optimisation-report
     */
    public function cache_optimisation_report($args, $assoc_args) {
        $report = SSPA_Cache_Recon::export_data(!empty($assoc_args['run']) ? (int) $assoc_args['run'] : 0);
        if (is_wp_error($report)) {
            WP_CLI::error($report->get_error_message());
        }
        if (!empty($assoc_args['output'])) {
            $json = wp_json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;
            if (false === file_put_contents($assoc_args['output'], $json)) {
                WP_CLI::error('Could not write analysis to ' . $assoc_args['output']);
            }
            WP_CLI::success('Wrote ' . $assoc_args['output']);
            return;
        }
        if (isset($assoc_args['format']) && 'json' === $assoc_args['format']) {
            WP_CLI::line(wp_json_encode($report));
            return;
        }
        $a = $report['assessment'];
        WP_CLI::log($report['headline']);
        WP_CLI::log($report['detail']);
        WP_CLI::log(sprintf(
            'Shared-cache status: %s | Review difficulty: %s | Hazards: %d | Candidate components: %d',
            $a['shared_cache_status'],
            $a['difficulty'],
            count((array) $a['hazards']),
            count((array) $a['candidate_components'])
        ));
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
     * Output the stable outbound WordPress HTTP API inventory.
     *
     * [--run-id=<id>]
     * : Specific completed baseline or spot run; defaults to the latest.
     *
     * [--format=<format>]
     * : json (default) or table.
     *
     * @subcommand http-calls
     */
    public function http_calls($args, $assoc_args) {
        $report = SSPA_Report::http_calls(!empty($assoc_args['run-id']) ? (int) $assoc_args['run-id'] : 0);
        if (is_wp_error($report)) {
            WP_CLI::error($report->get_error_message());
        }
        if (!isset($assoc_args['format']) || 'json' === $assoc_args['format']) {
            WP_CLI::line(wp_json_encode($report));
            return;
        }
        WP_CLI\Utils\format_items('table', $report['calls'], array(
            'endpoint', 'method', 'component', 'purpose', 'block_safety', 'calls', 'total_ms', 'worst_ms',
        ));
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
