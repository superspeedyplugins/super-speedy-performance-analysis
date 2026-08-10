<?php
defined('ABSPATH') || exit;

/**
 * Abilities API + MCP exposure, so any AI agent can run and interpret an analysis.
 * Mirrors the proven SSE_MCP pattern; loaded only when the Abilities API exists
 * (bootstrap gates on function_exists('wp_register_ability')).
 *
 * Readonly abilities are callable via GET on the core REST run controller
 * (/wp-json/wp-abilities/v1/abilities/<name>/run) even without the MCP Adapter.
 */
class SSPA_Abilities {

    /** Dash-only category slug; ability names are CATEGORY . '/name'. */
    const CATEGORY = 'super-speedy-performance';

    public static function init() {
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register_abilities'));
        add_action('mcp_adapter_init', array(__CLASS__, 'register_server'));
    }

    public static function ability_names() {
        return array(
            self::CATEGORY . '/get-status',
            self::CATEGORY . '/get-report',
            self::CATEGORY . '/get-findings',
            self::CATEGORY . '/get-plugin-impacts',
            self::CATEGORY . '/get-site-metrics',
            self::CATEGORY . '/run-analysis',
            self::CATEGORY . '/run-deep-analysis',
            self::CATEGORY . '/run-checkout-flow',
            self::CATEGORY . '/get-checkout-flow',
            self::CATEGORY . '/submit-results',
        );
    }

    public static function can_manage() {
        return current_user_can('manage_options');
    }

    public static function register_category() {
        wp_register_ability_category(self::CATEGORY, array(
            'label' => __('Super Speedy Performance Analysis', 'super-speedy-performance-analysis'),
            'description' => __('Profile a WordPress site, attribute SQL/RAM/time to plugins, isolate culprits, read plain-English findings.', 'super-speedy-performance-analysis'),
        ));
    }

    public static function register_abilities() {
        $readonly = array(
            'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            'show_in_rest' => true,
        );
        $active = array(
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
            'show_in_rest' => true,
        );
        // Gotcha: input only reaches the callback when input_schema is non-empty.
        $no_input = array(
            'type' => 'object',
            'properties' => new stdClass(),
            'additionalProperties' => false,
            'default' => array(),
        );

        wp_register_ability(self::CATEGORY . '/get-status', array(
            'label' => __('Get analysis status', 'super-speedy-performance-analysis'),
            'description' => __('Whether an analysis is currently running (with progress), and the id of the latest completed run. Poll this after run-analysis or run-deep-analysis.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => $no_input,
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'active' => array('type' => 'boolean'),
                    'run' => array('type' => array('object', 'null')),
                    'latest_done_run_id' => array('type' => 'integer'),
                ),
            ),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_get_status'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/get-report', array(
            'label' => __('Get performance report', 'super-speedy-performance-analysis'),
            'description' => __('The full report for the latest completed analysis: site score, plain-English insights with recommendations, per-page metrics, all findings, and measured per-plugin impacts. This is the primary read for interpreting results.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'run_id' => array('type' => 'integer', 'description' => __('Specific run id; defaults to the latest completed run.', 'super-speedy-performance-analysis')),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_get_report'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/get-findings', array(
            'label' => __('Get findings', 'super-speedy-performance-analysis'),
            'description' => __('Findings from the latest completed run, most severe first, each with a headline, evidence and an explicit recommendation.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'run_id' => array('type' => 'integer'),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'total' => array('type' => 'integer'),
                    'findings' => array('type' => 'array', 'items' => array('type' => 'object')),
                ),
            ),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_get_findings'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/get-plugin-impacts', array(
            'label' => __('Get measured plugin impacts', 'super-speedy-performance-analysis'),
            'description' => __('Per-plugin costs measured by the deep-analysis sweep: one row per plugin per page per cache mode, with generation-time, SQL, HTTP, query-count and memory deltas. Positive deltas = the plugin adds that much; negative = it SAVES that much (never call a negative delta slow). confidence=measured means proven by re-measurement; none means below the noise floor. Headline the warm (or normal) cache mode.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => $no_input,
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'total' => array('type' => 'integer'),
                    'impacts' => array('type' => 'array', 'items' => array('type' => 'object')),
                ),
            ),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_get_impacts'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/get-site-metrics', array(
            'label' => __('Get site metrics', 'super-speedy-performance-analysis'),
            'description' => __('Site demographics from the latest analysis: content counts, database size, environment, inferred sector.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => $no_input,
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_get_site_metrics'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/run-analysis', array(
            'label' => __('Run performance analysis', 'super-speedy-performance-analysis'),
            'description' => __('Start an analysis (asynchronous - poll get-status, then read get-report). type=baseline profiles every key page; type=spot with pages=[...] profiles specific pages; type=cache_impact compares object-cache on/off. Read-only against the site unless include_writes is true.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'type' => array('type' => 'string', 'enum' => array('baseline', 'spot', 'cache_impact')),
                    'pages' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => __('Page keys to limit the run to (e.g. home, shop).', 'super-speedy-performance-analysis')),
                    'include_writes' => array('type' => 'boolean', 'description' => __('Also profile save/order cascades against temporary objects.', 'super-speedy-performance-analysis')),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'run_id' => array('type' => 'integer'),
                    'status' => array('type' => 'string'),
                    'total' => array('type' => 'integer'),
                ),
            ),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_run_analysis'),
            'meta' => $active,
        ));

        wp_register_ability(self::CATEGORY . '/run-deep-analysis', array(
            'label' => __('Run plugin impact analysis (culprit isolation)', 'super-speedy-performance-analysis'),
            'description' => __('Sweep every eligible plugin (or the given suspects) across every profiled page, re-measuring each page with each plugin virtually disabled for test requests only (visitors unaffected) - in three object-cache modes when a persistent cache is present. Thorough and slow by design. Requires a completed analysis first. Asynchronous - poll get-status, then get-plugin-impacts.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'suspects' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => __('Plugin slugs to isolate; defaults to every plugin named in findings.', 'super-speedy-performance-analysis')),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'run_id' => array('type' => 'integer'),
                    'status' => array('type' => 'string'),
                ),
            ),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_run_deep'),
            'meta' => $active,
        ));

        wp_register_ability(self::CATEGORY . '/run-checkout-flow', array(
            'label' => __('Measure the checkout flow (buys something for real)', 'super-speedy-performance-analysis'),
            'description' => __('Measure what a customer waits through while buying something: each step of the purchase funnel, with the full plugin set active and nothing switched off. THIS CREATES A REAL ORDER. Real order emails are sent and real integrations fire; the order is cancelled and deleted afterwards and stock is restored. Call with dry_run first to get the pre-flight inventory - exactly which emails, webhooks and plugins the run would set off - and show it to the site owner before running for real. Asynchronous when not a dry run: poll get-status, then get-checkout-flow.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'dry_run' => array('type' => 'boolean', 'description' => __('Return the pre-flight inventory and create nothing.', 'super-speedy-performance-analysis')),
                    'product_id' => array('type' => 'integer', 'description' => __('Product to buy; defaults to the cheapest purchasable, in-stock, shippable one.', 'super-speedy-performance-analysis')),
                    'allow_integrations' => array('type' => 'boolean', 'description' => __('Default true. Turning this off makes the result no longer a real-store number.', 'super-speedy-performance-analysis')),
                    'allow_webhooks' => array('type' => 'boolean', 'description' => __('Default true.', 'super-speedy-performance-analysis')),
                    'mail_mode' => array('type' => 'string', 'enum' => array('deliver', 'construct', 'suppress'), 'description' => __('deliver (default) sends the order emails for real so the mail server is measured too.', 'super-speedy-performance-analysis')),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_run_checkout_flow'),
            // Destructive: it creates an order, sends mail and fires integrations. An
            // agent must not run it without the owner having seen the pre-flight.
            'meta' => array(
                'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => false),
                'show_in_rest' => true,
            ),
        ));

        wp_register_ability(self::CATEGORY . '/get-checkout-flow', array(
            'label' => __('Get checkout flow result', 'super-speedy-performance-analysis'),
            'description' => __('The measured purchase as a waterfall, split at the payment boundary: time the customer could still abandon during, and time after the sale was secured. Includes the slowest step, per-component PHP time across the whole purchase, outbound calls, mail behaviour, findings and the cleanup report.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'run_id' => array('type' => 'integer', 'description' => __('Specific checkout run id; defaults to the most recent one.', 'super-speedy-performance-analysis')),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_get_checkout_flow'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/submit-results', array(
            'label' => __('Queue anonymised results for superspeedy.org', 'super-speedy-performance-analysis'),
            'description' => __('Save the latest run to the durable local submission queue. Background delivery retries automatically. Only works when the site owner has opted in on the Share tab - agents cannot enable sharing.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => $no_input,
            'output_schema' => array(
                'type' => 'object',
                'properties' => array('queued' => array('type' => 'boolean')),
            ),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_submit'),
            'meta' => $active,
        ));
    }

    /* ---------------- executors (cast every scalar - $wpdb returns strings) ---------------- */

    public static function exec_get_status($input) {
        $active_id = SSPA_Run_Controller::active_run_id();
        return array(
            'active' => (bool) $active_id,
            'run' => $active_id ? SSPA_Run_Controller::status($active_id) : null,
            'latest_done_run_id' => (int) SSPA_Report::latest_done_run_id(),
        );
    }

    public static function exec_get_report($input) {
        $report = SSPA_Report::build(!empty($input['run_id']) ? (int) $input['run_id'] : 0);
        return is_wp_error($report) ? $report : $report;
    }

    public static function exec_get_findings($input) {
        $run_id = !empty($input['run_id']) ? (int) $input['run_id'] : SSPA_Report::latest_done_run_id();
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('No completed run.', 'super-speedy-performance-analysis'));
        }
        $findings = SSPA_Report::findings($run_id);
        return array('total' => count($findings), 'findings' => $findings);
    }

    public static function exec_get_impacts($input) {
        $impacts = SSPA_Report::impacts();
        return array('total' => count($impacts), 'impacts' => $impacts);
    }

    public static function exec_get_site_metrics($input) {
        $demo = SSPA_Demographics::latest();
        if (!$demo) {
            return new WP_Error('sspa_no_metrics', __('No demographics captured yet - run an analysis first.', 'super-speedy-performance-analysis'));
        }
        return array('sector' => (string) $demo['sector'], 'metrics' => $demo['metrics']);
    }

    public static function exec_run_analysis($input) {
        $args = array(
            'type' => (!empty($input['type']) && 'cache_impact' === $input['type']) ? 'cache_impact' : ((!empty($input['type']) && 'spot' === $input['type']) ? 'spot' : 'baseline'),
            'trigger' => 'mcp',
            'include_writes' => !empty($input['include_writes']),
        );
        if (!empty($input['pages'])) {
            $args['page_keys'] = array_map('sanitize_text_field', (array) $input['pages']);
        }
        $run_id = SSPA_Run_Controller::start($args);
        if (is_wp_error($run_id)) {
            return $run_id;
        }
        $status = SSPA_Run_Controller::status($run_id);
        return array(
            'run_id' => (int) $run_id,
            'status' => (string) $status['status'],
            'total' => (int) $status['total'],
        );
    }

    public static function exec_run_deep($input) {
        $args = array('type' => 'deep', 'trigger' => 'mcp');
        if (!empty($input['suspects'])) {
            $args['suspects'] = array_map('sanitize_key', (array) $input['suspects']);
        }
        $run_id = SSPA_Run_Controller::start($args);
        if (is_wp_error($run_id)) {
            return $run_id;
        }
        $status = SSPA_Run_Controller::status($run_id);
        return array('run_id' => (int) $run_id, 'status' => (string) $status['status']);
    }

    public static function exec_run_checkout_flow($input) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('sspa_no_store', __('WooCommerce is not active, so there is no checkout to profile.', 'super-speedy-performance-analysis'));
        }
        if (!empty($input['dry_run'])) {
            $crawler = new SSPA_Crawler();
            $sample = $crawler->send_profiled(home_url('/?sspa_flow_probe=1'), array(
                'method' => 'GET',
                'flags' => array('v' => 'guest', 'ck' => 'pre'),
            ));
            if (!is_array($sample['json'])) {
                return new WP_Error('sspa_preflight_failed', __('The pre-flight request could not be measured.', 'super-speedy-performance-analysis'));
            }
            return array('dry_run' => true, 'inventory' => $sample['json']);
        }

        $args = array('type' => 'checkout', 'trigger' => 'mcp');
        foreach (array('product_id', 'mail_mode') as $key) {
            if (isset($input[$key])) {
                $args[$key] = $input[$key];
            }
        }
        foreach (array('allow_integrations', 'allow_webhooks') as $key) {
            $args[$key] = !isset($input[$key]) || (bool) $input[$key];
        }
        $run_id = SSPA_Run_Controller::start($args);
        if (is_wp_error($run_id)) {
            return $run_id;
        }
        $status = SSPA_Run_Controller::status($run_id);
        return array('run_id' => (int) $run_id, 'status' => (string) $status['status'], 'dry_run' => false);
    }

    public static function exec_get_checkout_flow($input) {
        global $wpdb;
        $run_id = !empty($input['run_id']) ? (int) $input['run_id'] : (int) $wpdb->get_var(
            'SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE run_type = 'checkout' AND status IN ('done','failed') ORDER BY id DESC LIMIT 1"
        );
        if (!$run_id) {
            return new WP_Error('sspa_no_checkout_run', __('No checkout flow has been measured yet.', 'super-speedy-performance-analysis'));
        }
        $waterfall = SSPA_Checkout_Flow::waterfall($run_id);
        if (!$waterfall) {
            return new WP_Error('sspa_no_checkout_run', __('That run has no measured steps.', 'super-speedy-performance-analysis'));
        }
        $waterfall['findings'] = SSPA_Report::findings($run_id);
        return $waterfall;
    }

    public static function exec_submit($input) {
        $result = SSPA_Submitter::submit();
        if (is_wp_error($result)) {
            return $result;
        }
        return array('queued' => true);
    }

    public static function register_server($adapter = null) {
        if (!is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }
        try {
            $adapter->create_server(
                self::CATEGORY,
                'mcp',
                self::CATEGORY,
                'Super Speedy Performance Analysis',
                __('Profile the site, isolate slow plugins, read findings.', 'super-speedy-performance-analysis'),
                SSPA_VERSION,
                array('WP\\MCP\\Transport\\HttpTransport'),
                null,
                null,
                self::ability_names(),
                array(),
                array()
            );
        } catch (Throwable $e) {
            error_log('SSPA_Abilities: could not register MCP server: ' . $e->getMessage());
        }
    }
}
