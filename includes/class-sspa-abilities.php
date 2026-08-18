<?php
defined('ABSPATH') || exit;

/**
 * Abilities API + MCP exposure, so any AI agent can run and interpret an analysis.
 * Mirrors the proven SSE_MCP pattern; loaded only when the Abilities API exists
 * (bootstrap gates on function_exists('wp_register_ability')).
 *
 * Readonly abilities are callable via GET on the core REST run controller.
 */
class SSPA_Abilities {

    /** Dash-only category slug; ability names are CATEGORY . '/name'. */
    const CATEGORY = 'super-speedy-performance';
    const CAP_MANAGE = 'sspa_manage';
    const CAP_EXECUTE = 'sspa_execute';

    public static function init() {
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register_abilities'));
        add_filter('user_has_cap', array(__CLASS__, 'grant_admin_caps'), 10, 3);
    }

    public static function ability_names() {
        return array(
            self::CATEGORY . '/get-status',
            self::CATEGORY . '/get-report',
            self::CATEGORY . '/get-archive-profile',
            self::CATEGORY . '/get-cache-optimisation-analysis',
            self::CATEGORY . '/get-cache-safety-report',
            self::CATEGORY . '/get-findings',
            self::CATEGORY . '/get-plugin-impacts',
            self::CATEGORY . '/get-site-metrics',
            self::CATEGORY . '/run-analysis',
            self::CATEGORY . '/run-deep-analysis',
            self::CATEGORY . '/run-checkout-flow',
            self::CATEGORY . '/get-checkout-flow',
            self::CATEGORY . '/start-traffic-collection',
            self::CATEGORY . '/get-traffic-collection-status',
            self::CATEGORY . '/stop-traffic-collection',
            self::CATEGORY . '/get-traffic-observations',
            self::CATEGORY . '/submit-results',
        );
    }

    public static function can_manage() {
        return current_user_can(self::CAP_MANAGE);
    }

    public static function can_execute() {
        return current_user_can(self::CAP_EXECUTE);
    }

    public static function grant_admin_caps($allcaps, $caps, $args) {
        if (!empty($allcaps['manage_options'])) {
            $allcaps[self::CAP_MANAGE] = true;
            $allcaps[self::CAP_EXECUTE] = true;
        }
        return $allcaps;
    }

    public static function register_category() {
        // The Abilities API is WordPress 6.9+. The hooks below only fire where it exists, but
        // guard anyway so a direct call on an older site is a no-op rather than a fatal.
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category(self::CATEGORY, array(
            'label' => __('Super Speedy Performance Analysis', 'super-speedy-performance-analysis'),
            'description' => __('Profile a WordPress site, attribute SQL/RAM/time to plugins, isolate culprits, read plain-English findings.', 'super-speedy-performance-analysis'),
        ));
    }

    public static function register_abilities() {
        // See register_category() - WordPress 6.9+ only.
        if (!function_exists('wp_register_ability')) {
            return;
        }
        $readonly = array(
            'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            'show_in_rest' => true,
            'mcp' => array('public' => true),        );
        $active = array(
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
            'show_in_rest' => true,
            'mcp' => array('public' => true),        );
        // Gotcha: input only reaches the callback when input_schema is non-empty.
        $no_input = array(
            'type' => 'object',
            'properties' => new stdClass(),
            'additionalProperties' => false,
            'default' => array(),
        );
        $idempotent_control = array(
            'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
            'show_in_rest' => true,
            'mcp' => array('public' => true),
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
            'description' => __('The full report for the latest completed analysis: site score (out of 100), plain-English insights with recommendations, per-page metrics, all findings, and measured per-plugin impacts. This is the primary read for interpreting results.', 'super-speedy-performance-analysis'),
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

        wp_register_ability(self::CATEGORY . '/get-archive-profile', array(
            'label' => __('Get archive query profile', 'super-speedy-performance-analysis'),
            'description' => __('What this site\'s archive pages (category, tag, shop, product category, CPT and custom taxonomy archives) actually filter and order by, measured from a real run. Returns the composite indexes those archives would use, in column order with per-column direction, plus the postmeta keys and lookup-table columns that must be materialised as typed columns first - each with an inferred SQL type and a confidence. Intended for auto-configuring Super Speedy Archives. Never auto-apply a type whose confidence is "mixed" or "low", and treat complete=false as insufficient evidence rather than as "nothing needed".', 'super-speedy-performance-analysis'),
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
            'execute_callback' => array(__CLASS__, 'exec_get_archive_profile'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/get-cache-safety-report', array(
            'label' => __('Get shared-cache safety report', 'super-speedy-performance-analysis'),
            'description' => __('Read the latest shared-cache safety scan: shared-cache status, estimated review difficulty, potential visitor-specific content hazards, existing dynamic-region coverage and the plugin or theme candidates worth inspecting first. Candidate components are leads, not proven causes.', 'super-speedy-performance-analysis'),
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
            'execute_callback' => array(__CLASS__, 'exec_get_cache_safety_report'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/get-cache-optimisation-analysis', array(
            'label' => __('Get cache optimisation analysis', 'super-speedy-performance-analysis'),
            'description' => __('Read the immediate cache implementation difficulty and hazard analysis from a normal performance run. No traffic collector is required. The versioned document includes private-site diagnostic URLs and local component paths, but no response or customer content.', 'super-speedy-performance-analysis'),
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
            'execute_callback' => array(__CLASS__, 'exec_get_cache_optimisation_analysis'),
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
            'permission_callback' => array(__CLASS__, 'can_execute'),
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
                    'pages' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => __('Page keys to limit the sweep to, e.g. ["shop"]. Far cheaper than the whole site when you are checking one page.', 'super-speedy-performance-analysis')),
                    'url' => array('type' => 'string', 'description' => __('Scope the whole sweep to one URL on this site, even one no analysis has profiled - the sweep takes its own baselines for that page.', 'super-speedy-performance-analysis')),
                    'cache_modes' => array('type' => 'boolean', 'description' => __('Whether plugins that show an impact are also measured with the object cache off and priming. True by default; false makes a scoped sweep three times cheaper.', 'super-speedy-performance-analysis')),
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
            'permission_callback' => array(__CLASS__, 'can_execute'),
            'execute_callback' => array(__CLASS__, 'exec_run_deep'),
            'meta' => $active,
        ));

        wp_register_ability(self::CATEGORY . '/run-checkout-flow', array(
            'label' => __('Measure the checkout flow (buys something for real)', 'super-speedy-performance-analysis'),
            'description' => __('Measure a real purchase and the order-handling workflow. THIS CREATES A REAL ORDER. The MCP-safe defaults suppress mail, integrations and webhooks; each can be enabled explicitly. The order is cancelled and deleted afterwards and stock is restored. Call with dry_run first, show the pre-flight inventory to the site owner, then pass confirm:true for a real run. Asynchronous when not a dry run: poll get-status, then get-checkout-flow.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'dry_run' => array('type' => 'boolean', 'description' => __('Return the pre-flight inventory and create nothing.', 'super-speedy-performance-analysis')),
                    'confirm' => array('type' => 'boolean', 'description' => __('Must be true for a real checkout run.', 'super-speedy-performance-analysis')),
                    'product_id' => array('type' => 'integer', 'description' => __('Product to buy; defaults to the cheapest purchasable, in-stock, shippable one.', 'super-speedy-performance-analysis')),
                    'allow_integrations' => array('type' => 'boolean', 'description' => __('Default false. Set true explicitly to fire store integrations.', 'super-speedy-performance-analysis')),
                    'allow_webhooks' => array('type' => 'boolean', 'description' => __('Default false. Set true explicitly to fire webhooks.', 'super-speedy-performance-analysis')),
                    'mail_mode' => array('type' => 'string', 'enum' => array('deliver', 'construct', 'suppress'), 'description' => __('suppress by default. Set deliver explicitly to send order emails.', 'super-speedy-performance-analysis')),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_execute'),
            'execute_callback' => array(__CLASS__, 'exec_run_checkout_flow'),
            // Destructive: it creates an order, sends mail and fires integrations. An
            // agent must not run it without the owner having seen the pre-flight.
            'meta' => array(
                'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => false),
                'show_in_rest' => true,
                'mcp' => array('public' => true),            ),
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

        wp_register_ability(self::CATEGORY . '/start-traffic-collection', array(
            'label' => __('Start experimental traffic collection', 'super-speedy-performance-analysis'),
            'description' => __('Start the lightweight WooCommerce traffic observer for 24 hours, 72 hours or 7 days. Exact origin requests are retained for logged-in visitors and visitors with non-empty baskets; broad anonymous origin traffic is sampled. Returns the existing collection unchanged when the same duration is already active.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'duration' => array('type' => 'string', 'enum' => array('24h', '72h', '7d'), 'default' => '24h'),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_execute'),
            'execute_callback' => array(__CLASS__, 'exec_start_traffic_collection'),
            'meta' => $idempotent_control,
        ));

        wp_register_ability(self::CATEGORY . '/get-traffic-collection-status', array(
            'label' => __('Get experimental traffic collection status', 'super-speedy-performance-analysis'),
            'description' => __('Read collector state, deadlines, event and disk limits, database pre-flight timing and measured observer preparation overhead.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array('collection_id' => array('type' => 'integer')),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_get_traffic_collection_status'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/stop-traffic-collection', array(
            'label' => __('Stop experimental traffic collection', 'super-speedy-performance-analysis'),
            'description' => __('Stop request collection while retaining the bounded 72-hour order-outcome observer. emergency=true removes the observer immediately. Collected data is retained.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'collection_id' => array('type' => 'integer'),
                    'emergency' => array('type' => 'boolean', 'default' => false),
                ),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_execute'),
            'execute_callback' => array(__CLASS__, 'exec_stop_traffic_collection'),
            'meta' => $idempotent_control,
        ));

        wp_register_ability(self::CATEGORY . '/get-traffic-observations', array(
            'label' => __('Get experimental traffic observations', 'super-speedy-performance-analysis'),
            'description' => __('Return privacy-safe aggregate Phase 3 observations for design review. This is explicitly not the finished Traffic Performance Analysis report.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array('collection_id' => array('type' => 'integer')),
                'additionalProperties' => false,
                'default' => array(),
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_get_traffic_observations'),
            'meta' => $readonly,
        ));

        wp_register_ability(self::CATEGORY . '/compare-traffic-collections', array(
            'label' => __('Compare traffic collections', 'super-speedy-performance-analysis'),
            'description' => __('Compare before and after origin page-generation, SSF protection and private-state catalogue evidence with duration-normalised daily totals.', 'super-speedy-performance-analysis'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object',
                'properties' => array(
                    'before_collection_id' => array('type' => 'integer'),
                    'after_collection_id' => array('type' => 'integer'),
                ),
                'required' => array('before_collection_id', 'after_collection_id'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'execute_callback' => array(__CLASS__, 'exec_compare_traffic_collections'),
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
            'permission_callback' => array(__CLASS__, 'can_execute'),
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

    public static function exec_get_archive_profile($input) {
        return SSPA_Report::archive_profile(!empty($input['run_id']) ? (int) $input['run_id'] : 0);
    }

    public static function exec_get_cache_safety_report($input) {
        return SSPA_Cache_Recon::export_data(!empty($input['run_id']) ? (int) $input['run_id'] : 0);
    }

    public static function exec_get_cache_optimisation_analysis($input) {
        return SSPA_Cache_Recon::export_data(!empty($input['run_id']) ? (int) $input['run_id'] : 0);
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
        if (!empty($input['pages'])) {
            $args['page_keys'] = array_map('sanitize_text_field', (array) $input['pages']);
        }
        if (!empty($input['url'])) {
            $args['url'] = esc_url_raw((string) $input['url']);
        }
        if (isset($input['cache_modes'])) {
            $args['cache_modes'] = (bool) $input['cache_modes'];
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

        if (!isset($input['confirm']) || true !== rest_sanitize_boolean($input['confirm'])) {
            return new WP_Error('sspa_confirm_required', __('confirm must be true for a real checkout run.', 'super-speedy-performance-analysis'));
        }

        $args = array(
            'type' => 'checkout',
            'trigger' => 'mcp',
            'mail_mode' => 'suppress',
            'allow_integrations' => false,
            'allow_webhooks' => false,
        );
        foreach (array('product_id', 'mail_mode') as $key) {
            if (isset($input[$key])) {
                $args[$key] = $input[$key];
            }
        }
        foreach (array('allow_integrations', 'allow_webhooks') as $key) {
            if (isset($input[$key])) {
                $args[$key] = (bool) $input[$key];
            }
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
        $run_id = !empty($input['run_id']) ? (int) $input['run_id'] : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE run_type = 'checkout' AND status IN ('done','failed') ORDER BY id DESC LIMIT 1",
            SSPA_Schema::table('runs')
        ));
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

    public static function exec_start_traffic_collection($input) {
        return SSPA_Traffic_Collection::start(!empty($input['duration']) ? (string) $input['duration'] : '24h', 'mcp');
    }

    public static function exec_get_traffic_collection_status($input) {
        return SSPA_Traffic_Collection::status(!empty($input['collection_id']) ? (int) $input['collection_id'] : 0);
    }

    public static function exec_stop_traffic_collection($input) {
        return SSPA_Traffic_Collection::stop(
            !empty($input['collection_id']) ? (int) $input['collection_id'] : 0,
            !empty($input['emergency'])
        );
    }

    public static function exec_get_traffic_observations($input) {
        return SSPA_Traffic_Collection::observations(!empty($input['collection_id']) ? (int) $input['collection_id'] : 0);
    }

    public static function exec_compare_traffic_collections($input) {
        return SSPA_Traffic_Collection::comparison(
            (int) $input['before_collection_id'],
            (int) $input['after_collection_id']
        );
    }

    public static function exec_submit($input) {
        $result = SSPA_Submitter::submit();
        if (is_wp_error($result)) {
            return $result;
        }
        return array('queued' => true);
    }

}
