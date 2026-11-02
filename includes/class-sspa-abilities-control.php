<?php
defined('ABSPATH') || exit;

/**
 * The rest of the admin screens as abilities, so an agent with only the MCP connection can do
 * everything the Performance Analysis screens do: discover what the site can measure, start
 * every kind of measurement, keep it moving without WP-Cron, read every result at the depth the
 * screens show, and manage settings and stored evidence.
 *
 * Registered from SSPA_Abilities::register_abilities(), so it shares its gating (REST and
 * WP-CLI requests only, WordPress 6.9+), category and capabilities. Every executor calls the
 * same method the admin screen's AJAX handler calls; nothing here re-implements a measurement.
 *
 * Tiers: reads need sspa_manage; everything that starts, changes or deletes needs
 * sspa_execute; deleting evidence or sending anything off the site also needs confirm:true.
 */
class SSPA_Abilities_Control {

    const C = SSPA_Abilities::CATEGORY;

    /** Server requests advance-cache-check makes per call, and its time budget in seconds. */
    const CACHE_CHECK_REQUESTS = 7;
    const CACHE_CHECK_SECONDS = 20;

    public static function ability_names() {
        $names = array();
        foreach (array_keys(self::definitions()) as $slug) {
            $names[] = self::C . '/' . $slug;
        }
        return $names;
    }

    public static function register_abilities() {
        foreach (self::definitions() as $slug => $definition) {
            wp_register_ability(self::C . '/' . $slug, $definition);
        }
    }

    /* ---------------- shared schema pieces ---------------- */

    private static function meta($tier) {
        $annotations = array(
            'read' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            'act' => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
            'act_idempotent' => array('readonly' => false, 'destructive' => false, 'idempotent' => true),
            'destructive' => array('readonly' => false, 'destructive' => true, 'idempotent' => false),
        );
        return array(
            'annotations' => $annotations[$tier],
            'show_in_rest' => true,
            'mcp' => array('public' => true),
        );
    }

    private static function input($properties = array(), $required = array()) {
        $schema = array(
            'type' => 'object',
            'properties' => $properties ? $properties : new stdClass(),
            'additionalProperties' => false,
            'default' => array(),
        );
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /*
     * confirm is never in a schema's "required" list: a missing confirm must reach the
     * executor, which refuses with the stable sspa_confirm_required code an agent can act on,
     * rather than fail schema validation with a generic message.
     */
    private static function confirm_property() {
        return array('type' => 'boolean', 'description' => __('Safety interlock: must be true. Pass it only after the site owner has agreed to this action.', 'super-speedy-performance-analysis'));
    }

    private static function ability($label, $description, $tier, $callback, $input = null, $output = null) {
        return array(
            'label' => $label,
            'description' => $description,
            'category' => self::C,
            'input_schema' => $input ? $input : self::input(),
            'output_schema' => $output ? $output : array('type' => 'object'),
            'permission_callback' => array('SSPA_Abilities', 'read' === $tier ? 'can_manage' : 'can_execute'),
            'execute_callback' => array(__CLASS__, $callback),
            'meta' => self::meta($tier),
        );
    }

    private static function definitions() {
        $string = array('type' => 'string');
        $int = array('type' => 'integer');
        return array(
            // ---------------- read ----------------
            'get-capabilities' => self::ability(
                __('Discover what this site can measure', 'super-speedy-performance-analysis'),
                __('CALL THIS FIRST. Returns every workflow and the abilities that drive it, each prerequisite with its current state (profiler loader installed, Excimer, object cache, WooCommerce, loopback transport, whether WP-Cron runs, any active run), the page catalogue whose keys run-analysis accepts, attribution modes, and install steps for anything missing. Read notes for what to call next.', 'super-speedy-performance-analysis'),
                'read', 'exec_capabilities'
            ),
            'list-runs' => self::ability(
                __('List saved runs', 'super-speedy-performance-analysis'),
                __('Every saved run (analyses, plugin impact sweeps, page checks, checkout and cache runs), newest first, 50 per page. Use the ids with get-run, compare-history or get-markdown-report.', 'super-speedy-performance-analysis'),
                'read', 'exec_list_runs',
                self::input(array('page' => array('type' => 'integer', 'minimum' => 1)))
            ),
            'get-run' => self::ability(
                __('Get one run', 'super-speedy-performance-analysis'),
                __('One run: its type, status and progress, and the pages it measured with their headline figures. For a page\'s full detail call get-page-profile with the page_key.', 'super-speedy-performance-analysis'),
                'read', 'exec_get_run',
                self::input(array('run_id' => $int), array('run_id'))
            ),
            'list-page-profiles' => self::ability(
                __('List the current page profiles', 'super-speedy-performance-analysis'),
                __('The Pages tab: the newest measurement of every profiled page with its profile_id, URL and headline figures, slowest first. Pass a profile_id to get-page-profile for the full detail.', 'super-speedy-performance-analysis'),
                'read', 'exec_list_page_profiles'
            ),
            'get-page-profile' => self::ability(
                __('Get a page profile in full', 'super-speedy-performance-analysis'),
                __('Everything the page analysis panel shows for one measured page: phases, hooks and callbacks, components, SQL groups with query plans, HTTP calls, the function-level profile when Excimer ran, and measured plugin impact on that page. Identify the page by profile_id, by page_key (newest measurement of that page) or by url (newest measurement of that URL; profile-url measures one that has none).', 'super-speedy-performance-analysis'),
                'read', 'exec_get_page_profile',
                self::input(array('profile_id' => $int, 'page_key' => $string, 'url' => $string))
            ),
            'get-plugins-table' => self::ability(
                __('Get the Plugins table', 'super-speedy-performance-analysis'),
                __('Every component of the latest analysis with its attributed SQL, queries, rows, slowest query and HTTP time, its measured-impact verdict (adds or saves, typical and worst page), whether that verdict is stale, and object-cache notes. Pass page to see one page\'s figures; mode chooses attribution (code_owner or caller).', 'super-speedy-performance-analysis'),
                'read', 'exec_plugins_table',
                self::input(array('page' => $string, 'mode' => array('type' => 'string', 'enum' => array_keys(SSPA_Attribution::modes()))))
            ),
            'get-plugin-impact-detail' => self::ability(
                __('Get one plugin\'s measured impact per page', 'super-speedy-performance-analysis'),
                __('Every page and cache-mode measurement of one plugin from Plugin Impact Analysis, with the version measured.', 'super-speedy-performance-analysis'),
                'read', 'exec_plugin_detail',
                self::input(array('plugin' => $string), array('plugin'))
            ),
            'get-impact-plan' => self::ability(
                __('Plan a Plugin Impact Analysis', 'super-speedy-performance-analysis'),
                __('Before run-deep-analysis: the plugins that can be safely excluded, ordered by what attribution blames, the plugins that must be excluded together, and the figures for an estimate (pages, seconds per measurement). Site-wide by default; pass profile_id or url to plan one page.', 'super-speedy-performance-analysis'),
                'read', 'exec_impact_plan',
                self::input(array('profile_id' => $int, 'url' => $string))
            ),
            'get-markdown-report' => self::ability(
                __('Get a Markdown report', 'super-speedy-performance-analysis'),
                __('The Markdown export the screens download: kind=run with a run id, kind=page with a profile id, kind=checkout with a checkout run id.', 'super-speedy-performance-analysis'),
                'read', 'exec_markdown',
                self::input(array('kind' => array('type' => 'string', 'enum' => array('run', 'page', 'checkout')), 'id' => $int), array('kind', 'id'))
            ),
            'get-history-series' => self::ability(
                __('Get a history chart series', 'super-speedy-performance-analysis'),
                __('The Compare chart\'s data for one metric across saved runs up to after_run_id. selection_mode is setup (same setup) or all.', 'super-speedy-performance-analysis'),
                'read', 'exec_history_series',
                self::input(array('after_run_id' => $int, 'before_run_id' => $int, 'metric' => $string, 'selection_mode' => $string), array('after_run_id'))
            ),
            'get-settings' => self::ability(
                __('Get Performance Analysis settings', 'super-speedy-performance-analysis'),
                __('Sharing state, enabled publishers, plugin-update detection, stored-capture retention, whether data is removed on uninstall, the profiler state, the submission queue counts, the loopback origin address and whether loopback credentials are stored (never the credentials).', 'super-speedy-performance-analysis'),
                'read', 'exec_get_settings'
            ),
            'get-submission-queue' => self::ability(
                __('Get the submission queue', 'super-speedy-performance-analysis'),
                __('Queued and sent superspeedy.org submissions with their state, newest first.', 'super-speedy-performance-analysis'),
                'read', 'exec_submission_queue',
                self::input(array('limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100)))
            ),
            'get-payload-preview' => self::ability(
                __('Preview a submission payload', 'super-speedy-performance-analysis'),
                __('The exact anonymised JSON a queued submission sends (outbox_id), or the latest run would send (no id), with a plain-English summary.', 'super-speedy-performance-analysis'),
                'read', 'exec_payload_preview',
                self::input(array('outbox_id' => $int))
            ),
            'get-community-figures' => self::ability(
                __('Get the public community figures', 'super-speedy-performance-analysis'),
                __('Downloads the public figures for sites like this one from superspeedy.org, as the Compare tab does. This contacts superspeedy.org; nothing about this site is sent.', 'super-speedy-performance-analysis'),
                'read', 'exec_community_figures'
            ),
            'get-endpoint-profiles' => self::ability(
                __('Get AJAX windows and endpoint profiles', 'super-speedy-performance-analysis'),
                __('The AJAX tab: recorded AJAX windows, saved before/after comparisons and endpoint startup profiles.', 'super-speedy-performance-analysis'),
                'read', 'exec_endpoint_profiles'
            ),
            'get-admin-save-targets' => self::ability(
                __('Get save-profiling targets', 'super-speedy-performance-analysis'),
                __('The object types whose save can be profiled, and for object_type, the editor transports available. Pass one to profile-admin-save.', 'super-speedy-performance-analysis'),
                'read', 'exec_admin_save_targets',
                self::input(array('object_type' => $string))
            ),

            // ---------------- act ----------------
            'advance-run' => self::ability(
                __('Advance the active run', 'super-speedy-performance-analysis'),
                __('Process the next batch of the active run (up to about 15 seconds) and return its status. Call it in a loop after any run-* or profile-url ability until status is no longer crawling or analysing. Runs otherwise move only when WP-Cron fires, which on many hosts is never.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_advance_run',
                self::input(array('run_id' => $int))
            ),
            'cancel-run' => self::ability(
                __('Cancel the active run', 'super-speedy-performance-analysis'),
                __('Cancel the active run, or run_id. Measurements already taken stay saved.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_cancel_run',
                self::input(array('run_id' => $int))
            ),
            'set-profiler' => self::ability(
                __('Enable or disable the profiler', 'super-speedy-performance-analysis'),
                __('Enabling installs the profiling loader in wp-content/mu-plugins (and the query-capture drop-in wp-content/db.php when no other drop-in is there); analyses need it. Enabling writes files to the site, so it needs confirm:true. Disabling removes both and needs no confirm.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_set_profiler',
                self::input(array('enabled' => array('type' => 'boolean'), 'confirm' => self::confirm_property()), array('enabled'))
            ),
            'profile-url' => self::ability(
                __('Profile any URL on this site', 'super-speedy-performance-analysis'),
                __('Measure one URL on this site, as the admin bar\'s "Analyse this page" does: as a logged-out visitor (as=anon) or as the agent user (as=admin; the default for wp-admin URLs). all_hooks times every hook callback; raw_streams also keeps raw timing streams. Asynchronous: loop advance-run, then get-page-profile with the returned page_key.', 'super-speedy-performance-analysis'),
                'act', 'exec_profile_url',
                self::input(array(
                    'url' => $string,
                    'as' => array('type' => 'string', 'enum' => array('anon', 'admin')),
                    'all_hooks' => array('type' => 'boolean'),
                    'raw_streams' => array('type' => 'boolean'),
                ), array('url'))
            ),
            'profile-admin-save' => self::ability(
                __('Profile an editor save', 'super-speedy-performance-analysis'),
                __('Profile the save request of an editor, as the Workflows tab does, without a browser: a temporary object of object_type (from get-admin-save-targets) is created, its edit screen loaded with the agent user\'s login, and the editor\'s real save request sent once with the profiler token (transport rest: the block editor\'s REST update; classic: the editor form submitted with its save button). Normal save hooks, emails and integrations run; emails go to the site administrator. The temporary object is deleted afterwards. Synchronous: read the result with get-page-profile (profile_id).', 'super-speedy-performance-analysis'),
                'act', 'exec_profile_admin_save',
                self::input(array(
                    'object_type' => $string,
                    'transport' => array('type' => 'string', 'enum' => array('rest', 'classic')),
                ), array('object_type'))
            ),
            'run-cache-check' => self::ability(
                __('Start the cache performance check', 'super-speedy-performance-analysis'),
                __('Start the Cache performance tab\'s check: each representative page requested twice as an anonymous visitor, with basket cookies and as a logged-in test customer, then once more anonymously to catch a private response stored under the public key. Over MCP only the server-side requests run; the browser visitor-path timing is recorded as not measured. Then loop advance-cache-check with the assessment_id, and read get-cache-optimisation-analysis.', 'super-speedy-performance-analysis'),
                'act', 'exec_run_cache_check'
            ),
            'advance-cache-check' => self::ability(
                __('Advance the cache performance check', 'super-speedy-performance-analysis'),
                __('Make the next few server requests of a started cache check (assessment_id) and return progress. When the last request is made the check is completed and saved; done is then true.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_advance_cache_check',
                self::input(array('assessment_id' => $string), array('assessment_id'))
            ),
            'start-ajax-window' => self::ability(
                __('Start an AJAX window', 'super-speedy-performance-analysis'),
                __('Start a 15-minute AJAX observation window (the AJAX tab) labelled by scenario, optionally limited to endpoints such as "admin_ajax:action" or "rest:/wc/store/v1/cart". Exercise the site, then stop-ajax-window.', 'super-speedy-performance-analysis'),
                'act', 'exec_start_ajax_window',
                self::input(array('scenario' => $string, 'endpoints' => array('type' => 'array', 'items' => $string), 'detail' => array('type' => 'boolean')), array('scenario'))
            ),
            'stop-ajax-window' => self::ability(
                __('Stop an AJAX window', 'super-speedy-performance-analysis'),
                __('Stop the AJAX window uuid and keep its observations.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_stop_ajax_window',
                self::input(array('uuid' => $string), array('uuid'))
            ),
            'compare-ajax-windows' => self::ability(
                __('Compare two AJAX windows', 'super-speedy-performance-analysis'),
                __('Compare a before and an after AJAX window; name saves the comparison on the AJAX tab.', 'super-speedy-performance-analysis'),
                'act', 'exec_compare_ajax_windows',
                self::input(array('before' => $string, 'after' => $string, 'name' => array('type' => 'string', 'maxLength' => 80)), array('before', 'after'))
            ),
            'profile-endpoint' => self::ability(
                __('Profile an AJAX or REST endpoint', 'super-speedy-performance-analysis'),
                __('Profile the startup of an endpoint recorded in AJAX window uuid (key from get-endpoint-profiles) by requesting url samples times. Only GET and HEAD are allowed. confirm:true states that this exact URL may be requested. Then loop advance-run.', 'super-speedy-performance-analysis'),
                'act', 'exec_profile_endpoint',
                self::input(array(
                    'uuid' => $string, 'key' => $string, 'url' => $string,
                    'samples' => array('type' => 'integer', 'minimum' => 1),
                    'as' => $string,
                    'confirm' => self::confirm_property(),
                ), array('uuid', 'key', 'url'))
            ),
            'set-history-expectation' => self::ability(
                __('Approve or clear a history expectation', 'super-speedy-performance-analysis'),
                __('On Compare, approve a page\'s change in after_run_id as expected (mode=approve) or clear that approval (mode=clear), for page_identity.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_history_expectation',
                self::input(array('mode' => array('type' => 'string', 'enum' => array('approve', 'clear')), 'page_identity' => $string, 'after_run_id' => $int), array('mode', 'page_identity'))
            ),
            'process-submissions' => self::ability(
                __('Deliver queued submissions now', 'super-speedy-performance-analysis'),
                __('Deliver submissions already queued for superspeedy.org, as the open Share tab does. Only runs the site owner already chose to share are queued.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_process_submissions'
            ),
            'manage-submission' => self::ability(
                __('Retry, pause or resume a submission', 'super-speedy-performance-analysis'),
                __('Change one queued submission (outbox_id): retry now, pause or resume.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_manage_submission',
                self::input(array('outbox_id' => $int, 'operation' => array('type' => 'string', 'enum' => array('retry', 'pause', 'resume'))), array('outbox_id', 'operation'))
            ),
            'update-settings' => self::ability(
                __('Change Performance Analysis settings', 'super-speedy-performance-analysis'),
                __('Change any of: plugin_update_detection (notice and quick comparison after plugin changes), remove_data_on_uninstall, publishers ({slug: true|false}, which publishers\' recommendations may be shown), loopback_origin (the origin server address PA connects to when its own requests are blocked in front of the site, e.g. 127.0.0.1 or 10.0.0.5:8080; "" clears) and loopback_credentials ({user, password} for a site behind HTTP basic auth, or {clear: true}; stored, sent only to this site, never returned). Sharing itself is not changed here.', 'super-speedy-performance-analysis'),
                'act_idempotent', 'exec_update_settings',
                self::input(array(
                    'plugin_update_detection' => array('type' => 'boolean'),
                    'remove_data_on_uninstall' => array('type' => 'boolean'),
                    'publishers' => array('type' => 'object', 'additionalProperties' => array('type' => 'boolean')),
                    'loopback_origin' => $string,
                    'loopback_credentials' => array(
                        'type' => 'object',
                        'properties' => array('user' => $string, 'password' => $string, 'clear' => array('type' => 'boolean')),
                        'additionalProperties' => false,
                    ),
                ))
            ),
            'prepare-quote-request' => self::ability(
                __('Prepare a fix-quote request', 'super-speedy-performance-analysis'),
                __('Build the Markdown a "Request a fix quote" would send for one page profile and hold it as a draft for two hours. Show the markdown to the site owner; send-quote-request sends it. source is page-analysis, cache-tab, summary or compare.', 'super-speedy-performance-analysis'),
                'act', 'exec_prepare_quote',
                self::input(array('profile_id' => $int, 'source' => array('type' => 'string', 'enum' => SSPA_Quote_Request::SOURCES)), array('profile_id', 'source'))
            ),

            // ---------------- destructive or outward-facing ----------------
            'prune-stored-captures' => self::ability(
                __('Delete stored capture detail', 'super-speedy-performance-analysis'),
                __('Delete the detailed capture data of every run outside the retention window, as Tools does. Headline figures stay; page profiles of pruned runs lose their detail. Opted-in runs are queued for sharing first. Needs confirm:true.', 'super-speedy-performance-analysis'),
                'destructive', 'exec_prune',
                self::input(array('confirm' => self::confirm_property()))
            ),
            'delete-traffic-collection' => self::ability(
                __('Delete a traffic collection', 'super-speedy-performance-analysis'),
                __('Delete collection_id and its observations. Needs confirm:true.', 'super-speedy-performance-analysis'),
                'destructive', 'exec_delete_traffic',
                self::input(array('collection_id' => $int, 'confirm' => self::confirm_property()), array('collection_id'))
            ),
            'cleanup-workflow-objects' => self::ability(
                __('Remove leftover save-profiling objects', 'super-speedy-performance-analysis'),
                __('Cancel any save-profiling run and delete the temporary object it created. Needs confirm:true.', 'super-speedy-performance-analysis'),
                'destructive', 'exec_cleanup_workflow',
                self::input(array('confirm' => self::confirm_property()))
            ),
            'send-quote-request' => self::ability(
                __('Send a fix-quote request', 'super-speedy-performance-analysis'),
                __('Send a prepared draft (request_uuid) to Super Speedy Plugins with the contact details given. consent:true records that the site owner agreed to send this report and their details; confirm:true that they asked for it to be sent now. Leave marketing_opt_in false unless they asked for marketing email.', 'super-speedy-performance-analysis'),
                'destructive', 'exec_send_quote',
                self::input(array(
                    'request_uuid' => $string,
                    'name' => $string,
                    'email' => $string,
                    'site_url' => $string,
                    'message' => $string,
                    'ongoing_care' => array('type' => 'boolean'),
                    'marketing_opt_in' => array('type' => 'boolean'),
                    'consent' => array('type' => 'boolean'),
                    'confirm' => self::confirm_property(),
                ), array('request_uuid', 'email'))
            ),
        );
    }

    private static function confirmed($input) {
        return isset($input['confirm']) && true === $input['confirm'];
    }

    private static function confirm_error() {
        return new WP_Error('sspa_confirm_required', __('This action needs confirm:true. Ask the site owner first.', 'super-speedy-performance-analysis'));
    }

    /* ---------------- read executors ---------------- */

    public static function exec_capabilities($input) {
        $health = SSPA_Helper_Files::health();
        $active = SSPA_Run_Controller::active_run_id();
        $capabilities = array();
        foreach (SSPA_Tools::capabilities() as $key => $capability) {
            $capabilities[$key] = array(
                'label' => (string) $capability['label'],
                'status' => (string) $capability['status'],
                'used' => !empty($capability['used']),
                'detail' => (string) ($capability['detail'] ?? ''),
            );
            if (!empty($capability['install']) && !$capabilities[$key]['used']) {
                $capabilities[$key]['install_steps'] = SSPA_Tools::install_steps($capability['install']);
            }
        }
        $pages = array();
        foreach (SSPA_Catalogue::build() as $job) {
            if (in_array($job['page_key'], array('baseline', 'mail-probe'), true)) {
                continue;
            }
            $pages[] = array('page_key' => (string) $job['page_key'], 'url' => (string) $job['url'], 'variant' => (string) $job['variant']);
        }
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $notes = array();
        if (!$health['mu']) {
            $notes[] = __('The profiler is off. Ask the site owner, then call set-profiler with enabled:true and confirm:true; every analysis needs it.', 'super-speedy-performance-analysis');
        }
        if ($active) {
            $notes[] = __('A run is active: loop advance-run until it finishes, or cancel-run.', 'super-speedy-performance-analysis');
        }
        if (!SSPA_Report::latest_done_run_id()) {
            $notes[] = __('No analysis has finished yet: start with run-analysis (type baseline), loop advance-run, then read get-report.', 'super-speedy-performance-analysis');
        }
        $loopback = self::loopback_state($health, $active);
        if ($health['mu'] && !$loopback['healthy'] && !$active) {
            $notes[] = __('This site cannot fetch its own pages (prerequisites.loopback.reason), so nothing can be measured over MCP yet. If a firewall or CDN in front of the site blocks it, set update-settings loopback_origin to the origin server\'s address (ask the host); if the site is behind HTTP basic auth, set loopback_credentials. Then call get-capabilities again.', 'super-speedy-performance-analysis');
        }
        $notes[] = __('Always loop advance-run after starting a run: it does not rely on WP-Cron.', 'super-speedy-performance-analysis');
        return array(
            'plugin_version' => SSPA_VERSION,
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'site_url' => home_url('/'),
            'abilities' => array_merge(SSPA_Abilities::ability_names()),
            'workflows' => array(
                array('name' => 'site analysis', 'steps' => array('run-analysis', 'advance-run', 'get-report', 'get-findings', 'list-page-profiles', 'get-page-profile', 'get-plugins-table')),
                array('name' => 'one page or URL', 'steps' => array('profile-url', 'advance-run', 'get-page-profile')),
                array('name' => 'plugin culprits', 'steps' => array('get-impact-plan', 'run-deep-analysis', 'advance-run', 'get-plugin-impacts', 'get-plugin-impact-detail')),
                array('name' => 'page cache', 'steps' => array('run-cache-check', 'advance-cache-check', 'get-cache-optimisation-analysis', 'get-cache-safety-report')),
                array('name' => 'checkout', 'steps' => array('run-checkout-flow (dry_run first)', 'advance-run', 'get-checkout-flow')),
                array('name' => 'editor save', 'steps' => array('get-admin-save-targets', 'profile-admin-save', 'get-page-profile')),
                array('name' => 'AJAX and REST', 'steps' => array('start-ajax-window', 'stop-ajax-window', 'get-endpoint-profiles', 'profile-endpoint', 'advance-run', 'compare-ajax-windows')),
                array('name' => 'real traffic', 'steps' => array('start-traffic-collection', 'get-traffic-collection-status', 'stop-traffic-collection', 'get-traffic-observations', 'compare-traffic-collections')),
                array('name' => 'history', 'steps' => array('list-runs', 'get-run', 'compare-history', 'get-history-series', 'set-history-expectation')),
                array('name' => 'reports', 'steps' => array('get-markdown-report', 'prepare-quote-request', 'send-quote-request')),
            ),
            'prerequisites' => array(
                'profiler' => array(
                    'enabled' => (bool) $health['mu'],
                    'reason' => (string) ($health['mu_reason'] ?? ''),
                    'file_mods_blocked' => (bool) SSPA_Helper_Files::file_mods_blocked(),
                    'query_capture_dropin' => (string) SSPA_Helper_Files::dropin_status(),
                ),
                'capabilities' => $capabilities,
                'object_cache' => SSPA_Object_Cache_Capability::inspect(),
                'woocommerce' => class_exists('WooCommerce'),
                'loopback' => $loopback,
                'wp_cron' => array(
                    'disabled' => (bool) $cron_disabled,
                    'note' => $cron_disabled
                        ? __('WP-Cron is disabled here: runs move only when advance-run is called (or a system cron runs wp-cron.php).', 'super-speedy-performance-analysis')
                        : __('WP-Cron is enabled, but it only fires on site traffic; loop advance-run anyway.', 'super-speedy-performance-analysis'),
                ),
                'active_run' => $active ? SSPA_Run_Controller::status($active) : null,
                'latest_done_run_id' => (int) SSPA_Report::latest_done_run_id(),
            ),
            'page_catalogue' => $pages,
            'attribution_modes' => SSPA_Attribution::modes(),
            'outside_mcp' => array(
                __('Server-level changes (installing Excimer, PHP-FPM, OPcache or MySQL settings) need the host or SSH; install_steps says what to run.', 'super-speedy-performance-analysis'),
                __('Browser visitor-path timing in the cache check needs a browser; over MCP it is recorded as not measured.', 'super-speedy-performance-analysis'),
            ),
            'notes' => $notes,
        );
    }

    /**
     * Can PA reach its own site, over which route, and why. Probed live (30-second probes) so
     * the first call an agent makes tells the truth; while a run is active the stored choice
     * from its start is reported instead, because a probe could change the route under it.
     */
    private static function loopback_state($health, $active) {
        $credentials = SSPA_Loopback_Route::credentials_state();
        $state = array(
            'healthy' => false,
            'transport' => 'unavailable',
            'route' => null,
            'origin_address' => null,
            'credentials_set' => $credentials['set'],
            'configured_origin' => SSPA_Loopback_Route::configured_origin(),
            'reason' => '',
            'attempts' => array(),
            'checked' => 'live',
            'browser_fallback' => __('When loopback is blocked, the Performance Analysis screen can measure pages through the administrator\'s browser instead. That needs a person in wp-admin and cannot be driven over MCP.', 'super-speedy-performance-analysis'),
        );
        if (!$health['mu']) {
            $state['checked'] = 'not checked';
            $state['reason'] = __('Not checked: the profiler is off, and the check needs it to recognise its own answer. Enable it with set-profiler, then call get-capabilities again.', 'super-speedy-performance-analysis');
            return $state;
        }
        if ($active) {
            $stored = SSPA_Loopback_Route::last_selection();
            $probe = array(
                'healthy' => !empty($stored['healthy']),
                'reason' => (string) ($stored['reason'] ?? ''),
                'route' => isset($stored['route']) ? $stored['route'] : null,
                'origin_address' => isset($stored['address']) ? $stored['address'] : null,
                'attempts' => isset($stored['attempts']) ? (array) $stored['attempts'] : array(),
            );
            $state['checked'] = 'stored at run start';
        } else {
            $probe = SSPA_Run_Controller::loopback_preflight('', 'anon', 30);
        }
        $state['healthy'] = (bool) $probe['healthy'];
        $state['transport'] = $probe['healthy'] ? 'loopback' : 'unavailable';
        $state['route'] = $probe['route'];
        $state['origin_address'] = $probe['origin_address'];
        $state['attempts'] = $probe['attempts'];
        if (!$probe['healthy']) {
            $state['reason'] = (string) $probe['reason'];
        } elseif ('direct' === $probe['route']) {
            /* translators: 1: origin address, 2: why the public route failed */
            $state['reason'] = sprintf(__('Loopback works direct to the origin server at %1$s. The public route failed: %2$s.', 'super-speedy-performance-analysis'), $probe['origin_address'], $probe['attempts'][0]['reason']);
        } else {
            $state['reason'] = __('Loopback works over the public route: the site reached itself at its own URL.', 'super-speedy-performance-analysis');
        }
        return $state;
    }

    public static function exec_list_runs($input) {
        $page = max(1, (int) ($input['page'] ?? 1));
        $rows = SSPA_History_Series::run_page($page);
        $more = count($rows) > SSPA_History_Series::PAGE_SIZE;
        $runs = array();
        foreach (array_slice($rows, 0, SSPA_History_Series::PAGE_SIZE) as $row) {
            $notes = json_decode((string) $row['notes'], true);
            $runs[] = array(
                'run_id' => (int) $row['id'],
                'run_type' => (string) $row['run_type'],
                'status' => (string) $row['status'],
                'trigger' => (string) $row['trigger_source'],
                'started' => (string) $row['started'],
                'finished' => (string) $row['finished'],
                'notes' => is_array($notes) ? $notes : null,
            );
        }
        return array('page' => $page, 'has_more' => $more, 'total' => SSPA_History_Series::run_count(), 'runs' => $runs);
    }

    public static function exec_get_run($input) {
        $run_id = (int) ($input['run_id'] ?? 0);
        $status = SSPA_Run_Controller::status($run_id);
        if (!$status) {
            return new WP_Error('sspa_not_found', __('No run with that id.', 'super-speedy-performance-analysis'));
        }
        return array('run' => $status, 'pages' => SSPA_Report::pages($run_id));
    }

    public static function exec_list_page_profiles($input) {
        $newest = array();
        foreach (SSPA_Profile_Panel::current_profiles(SSPA_Plugins_Table::latest_run_id()) as $row) {
            $newest[$row['page_key']] = $row; // ordered by id, so the last row per key is the newest
        }
        uasort($newest, function ($a, $b) {
            return (float) $b['page_gen_ms'] <=> (float) $a['page_gen_ms'];
        });
        $profiles = array();
        foreach ($newest as $row) {
            $profiles[] = array(
                'profile_id' => (int) $row['id'],
                'run_id' => (int) $row['run_id'],
                'run_type' => (string) $row['run_type'],
                'page_key' => (string) $row['page_key'],
                'variant' => (string) $row['variant'],
                'url' => (string) SSPA_Profile_Panel::measured_page_url($row),
                'generation_ms' => null === $row['page_gen_ms'] ? null : round((float) $row['page_gen_ms'], 1),
                'ttfb_ms' => null === $row['ttfb_ms'] ? null : round((float) $row['ttfb_ms'], 1),
                'sql_ms' => null === $row['sql_ms'] ? null : round((float) $row['sql_ms'], 1),
                'sql_count' => null === $row['sql_count'] ? null : (int) $row['sql_count'],
                'peak_mem_bytes' => null === $row['peak_mem_bytes'] ? null : (int) $row['peak_mem_bytes'],
                'blocked_by' => $row['blocked_by'],
            );
        }
        return array('profiles' => $profiles);
    }

    private static function resolve_profile_id($input) {
        if (!empty($input['profile_id'])) {
            return (int) $input['profile_id'];
        }
        $page_key = isset($input['page_key']) ? sanitize_text_field((string) $input['page_key']) : '';
        if ('' === $page_key && !empty($input['url'])) {
            $job = SSPA_Adhoc::job_for(esc_url_raw((string) $input['url']));
            if (is_wp_error($job)) {
                return $job;
            }
            $page_key = $job['page_key'];
        }
        if ('' === $page_key) {
            return new WP_Error('sspa_profile_input', __('Pass profile_id, page_key or url.', 'super-speedy-performance-analysis'));
        }
        $profile_id = SSPA_Profile_Panel::newest_profile_id_for_page($page_key);
        if (!$profile_id) {
            /* translators: %s: page key */
            return new WP_Error('sspa_not_found', sprintf(__('No measurement of %s yet: call profile-url or run-analysis first.', 'super-speedy-performance-analysis'), $page_key));
        }
        return $profile_id;
    }

    public static function exec_get_page_profile($input) {
        $profile_id = self::resolve_profile_id($input);
        if (is_wp_error($profile_id)) {
            return $profile_id;
        }
        return SSPA_Profile_Panel::export_data($profile_id);
    }

    public static function exec_plugins_table($input) {
        $run_id = SSPA_Plugins_Table::latest_run_id();
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('No analysis has finished yet: call run-analysis first.', 'super-speedy-performance-analysis'));
        }
        $data = SSPA_Plugins_Table::rows($run_id, (string) ($input['mode'] ?? ''), (string) ($input['page'] ?? ''));
        $components = array();
        foreach ($data['components'] as $c) {
            $v = $c['verdict'];
            $components[] = array(
                'component' => (string) $c['component'],
                'type' => (string) $c['component_type'],
                'version' => $c['version'] ? (string) $c['version'] : null,
                'sql_ms' => round((float) $c['total_sql_ms'], 1),
                'queries' => (int) $c['total_queries'],
                'rows_fetched' => (int) $c['total_rows'],
                'slowest_query_ms' => round((float) $c['slowest_query_ms'], 1),
                'http_ms' => round((float) $c['total_http_ms'], 1),
                'measured_impact' => $v ? array(
                    'state' => null === $v['typical'] ? 'none' : ($v['typical'] < 0 ? 'saves' : 'adds'),
                    'typical_ms' => null === $v['typical'] ? null : round((float) $v['typical'], 1),
                    'worst_ms' => $v['worst'] ? round((float) $v['worst']['delta_ttfb_ms'], 1) : null,
                    'worst_page' => $v['worst'] ? (string) $v['worst']['page_key'] : null,
                    'measurable_pages' => (int) $v['measured'],
                    'pages_measured' => (int) $v['pages'],
                    'noise_ms' => round((float) $v['noise'], 1),
                    'summary' => SSPA_Plugins_Table::verdict_text($v, $data['page']),
                    'stale' => (bool) $c['stale'],
                ) : null,
                'object_cache' => isset($data['cache'][$c['component']]) ? $data['cache'][$c['component']] : null,
            );
        }
        return array(
            'run_id' => (int) $run_id,
            'mode' => (string) $data['mode'],
            'page' => (string) $data['page'],
            'pages' => array_keys($data['pages']),
            'page_profile_id' => '' !== $data['page'] && isset($data['profiles'][$data['page']]) ? (int) $data['profiles'][$data['page']] : null,
            'components' => $components,
        );
    }

    public static function exec_plugin_detail($input) {
        $plugin = sanitize_text_field((string) ($input['plugin'] ?? ''));
        $rows = SSPA_Plugins_Table::latest_impacts($plugin);
        if (!$rows) {
            return new WP_Error('sspa_not_found', __('No measured impacts for this plugin yet - run a plugin impact analysis.', 'super-speedy-performance-analysis'));
        }
        $measured_version = null;
        foreach ($rows as $row) {
            if (!empty($row['plugin_version'])) {
                $measured_version = (string) $row['plugin_version'];
                break;
            }
        }
        return array('plugin' => $plugin, 'measured_version' => $measured_version, 'rows' => $rows);
    }

    public static function exec_impact_plan($input) {
        return SSPA_Profile_Panel::impact_plan((int) ($input['profile_id'] ?? 0), (string) ($input['url'] ?? ''));
    }

    public static function exec_markdown($input) {
        return SSPA_Markdown_Export::build(sanitize_key((string) ($input['kind'] ?? '')), (int) ($input['id'] ?? 0));
    }

    public static function exec_history_series($input) {
        return SSPA_History_Series::build(
            (int) ($input['after_run_id'] ?? 0),
            sanitize_key((string) ($input['metric'] ?? 'request_wall_ms')),
            (int) ($input['before_run_id'] ?? 0),
            sanitize_key((string) ($input['selection_mode'] ?? 'setup'))
        );
    }

    public static function exec_get_settings($input) {
        $health = SSPA_Helper_Files::health();
        return array(
            'sharing' => array(
                'opted_in' => (bool) SSPA_Submitter::opted_in(),
                'consent_version' => (string) get_option('sspa_share_consent_version', ''),
                'consented_at' => (string) get_option('sspa_share_consented_at', ''),
                'note' => __('Sharing is the site owner\'s decision and is changed on the Share tab, not over MCP.', 'super-speedy-performance-analysis'),
            ),
            'publishers' => SSPA_Community_State::publishers(),
            'plugin_update_detection' => (bool) sspa_get_option('plugin_update_detection'),
            'blob_retention_runs' => (int) sspa_get_option('blob_retention_runs'),
            'remove_data_on_uninstall' => (bool) sspa_get_option('remove_data_on_uninstall'),
            'profiler_enabled' => (bool) $health['mu'],
            'submission_counts' => SSPA_Community_Outbox::counts(),
            'loopback_origin' => SSPA_Loopback_Route::configured_origin(),
            'loopback_credentials' => SSPA_Loopback_Route::credentials_state(),
        );
    }

    public static function exec_submission_queue($input) {
        $limit = min(100, max(1, (int) ($input['limit'] ?? 20)));
        return array(
            'status' => SSPA_Community_Outbox::queue_status(),
            'counts' => SSPA_Community_Outbox::counts(),
            'submissions' => SSPA_Community_Outbox::history($limit),
        );
    }

    public static function exec_payload_preview($input) {
        $json = SSPA_Submitter::preview((int) ($input['outbox_id'] ?? 0));
        if (is_wp_error($json)) {
            return $json;
        }
        return array(
            'payload' => json_decode($json, true),
            'summary' => SSPA_Submitter::describe_payload($json),
            'bytes' => strlen($json),
        );
    }

    public static function exec_community_figures($input) {
        return SSPA_Community_Figures::load();
    }

    public static function exec_endpoint_profiles($input) {
        return array(
            'windows' => array_values(SSPA_Ajax_Profile::windows()),
            'comparisons' => SSPA_Ajax_Profile::comparisons(),
            'endpoint_profiles' => SSPA_Endpoint_Profile::records(),
        );
    }

    public static function exec_admin_save_targets($input) {
        $object_type = sanitize_key((string) ($input['object_type'] ?? ''));
        $out = array('object_types' => SSPA_Workflow_Analysis::object_types());
        if ('' !== $object_type) {
            $out['targets'] = SSPA_Workflow_Analysis::targets($object_type);
            $out['transports'] = SSPA_Workflow_Analysis::transports($object_type);
        }
        return $out;
    }

    /* ---------------- act executors ---------------- */

    public static function exec_advance_run($input) {
        $run_id = !empty($input['run_id']) ? (int) $input['run_id'] : SSPA_Run_Controller::active_run_id();
        if (!$run_id) {
            return array('active' => false, 'run' => null, 'latest_done_run_id' => (int) SSPA_Report::latest_done_run_id());
        }
        $status = SSPA_Run_Controller::status($run_id);
        if ($status && in_array($status['status'], array('crawling', 'analysing'), true)) {
            SSPA_Run_Controller::process_batch($run_id);
            $status = SSPA_Run_Controller::status($run_id);
        }
        return array(
            'active' => $status && in_array($status['status'], array('crawling', 'analysing'), true),
            'run' => $status,
            'latest_done_run_id' => (int) SSPA_Report::latest_done_run_id(),
        );
    }

    public static function exec_cancel_run($input) {
        $run_id = !empty($input['run_id']) ? (int) $input['run_id'] : SSPA_Run_Controller::active_run_id();
        if ($run_id) {
            SSPA_Run_Controller::cancel($run_id);
        }
        return array('cancelled_run_id' => (int) $run_id);
    }

    public static function exec_set_profiler($input) {
        $enabled = !empty($input['enabled']);
        if ($enabled && !self::confirmed($input)) {
            return self::confirm_error();
        }
        $result = SSPA_Run_Controller::set_profiler($enabled);
        if (is_wp_error($result)) {
            return $result;
        }
        $health = SSPA_Helper_Files::health();
        return array('enabled' => (bool) $health['mu'], 'query_capture_dropin' => (string) SSPA_Helper_Files::dropin_status());
    }

    public static function exec_profile_url($input) {
        $url = esc_url_raw((string) ($input['url'] ?? ''));
        $as = isset($input['as']) ? sanitize_key((string) $input['as']) : null;
        $job = SSPA_Adhoc::job_for($url, $as);
        if (is_wp_error($job)) {
            return $job;
        }
        $run_id = SSPA_Run_Controller::start(array(
            'type' => 'adhoc',
            'url' => $url,
            'variant' => $job['variant'],
            'trigger' => 'mcp',
            'all_hooks' => !empty($input['all_hooks']),
            'raw_streams' => !empty($input['raw_streams']),
        ));
        if (is_wp_error($run_id)) {
            return $run_id;
        }
        return array('run_id' => (int) $run_id, 'page_key' => (string) $job['page_key'], 'variant' => (string) $job['variant']);
    }

    public static function exec_profile_admin_save($input) {
        return SSPA_Admin_Save::replay((string) ($input['object_type'] ?? ''), (string) ($input['transport'] ?? ''));
    }

    public static function exec_run_cache_check($input) {
        $prepared = SSPA_Cache_Delivery::prepare_check();
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        return array(
            'assessment_id' => (string) $prepared['assessment_id'],
            'targets' => $prepared['targets'],
            'server_requests' => count($prepared['targets']) * count(SSPA_Cache_Delivery::server_legs()),
        );
    }

    public static function exec_advance_cache_check($input) {
        $assessment_id = sanitize_text_field((string) ($input['assessment_id'] ?? ''));
        $pending = get_option(SSPA_Cache_Delivery::PENDING_OPTION, null);
        if (!is_array($pending) || empty($pending['assessment_id']) || !hash_equals((string) $pending['assessment_id'], $assessment_id)) {
            $report = SSPA_Cache_Delivery::latest();
            if ($report && hash_equals((string) $report['assessment_id'], $assessment_id)) {
                return array('done' => true, 'page_cache_status' => (string) $report['page_cache_status'], 'headline' => (string) $report['headline']);
            }
            return new WP_Error('sspa_cache_delivery_expired', __('This cache check is missing or has been replaced. Start it again with run-cache-check.', 'super-speedy-performance-analysis'));
        }
        // The server enforces the fixed order (anonymous first, private audiences, final
        // anonymous); walk it the same way the Cache tab's browser does.
        $legs = SSPA_Cache_Delivery::server_legs();
        $total = count($pending['targets']) * count($legs);
        $made = 0;
        $started = microtime(true);
        foreach ($pending['targets'] as $target) {
            foreach ($legs as $leg) {
                $key = $target['page_key'] . ':' . $leg[0] . ':' . $leg[1];
                if (isset($pending['server_results'][$key])) {
                    continue;
                }
                if ($made >= self::CACHE_CHECK_REQUESTS || microtime(true) - $started > self::CACHE_CHECK_SECONDS) {
                    break 2;
                }
                $result = SSPA_Cache_Delivery::probe_server_request($assessment_id, $target['page_key'], $leg[0], $leg[1]);
                if (is_wp_error($result)) {
                    return $result;
                }
                $made++;
                $pending = get_option(SSPA_Cache_Delivery::PENDING_OPTION, null);
            }
        }
        $done = is_array($pending) ? count($pending['server_results']) : $total;
        if ($done < $total) {
            return array('done' => false, 'requests_made' => $done, 'requests_total' => $total);
        }
        $report = SSPA_Cache_Delivery::complete($assessment_id, array(), false);
        if (is_wp_error($report)) {
            return $report;
        }
        return array('done' => true, 'requests_made' => $total, 'requests_total' => $total, 'page_cache_status' => (string) $report['page_cache_status'], 'headline' => (string) $report['headline']);
    }

    public static function exec_start_ajax_window($input) {
        return SSPA_Ajax_Profile::start(array(
            'scenario' => sanitize_text_field((string) ($input['scenario'] ?? '')),
            'endpoints' => array_map('sanitize_text_field', (array) ($input['endpoints'] ?? array())),
            'detail' => !empty($input['detail']),
        ));
    }

    public static function exec_stop_ajax_window($input) {
        return SSPA_Ajax_Profile::stop(sanitize_text_field((string) ($input['uuid'] ?? '')));
    }

    public static function exec_compare_ajax_windows($input) {
        $before = sanitize_text_field((string) ($input['before'] ?? ''));
        $after = sanitize_text_field((string) ($input['after'] ?? ''));
        $result = SSPA_Ajax_Profile::compare($before, $after);
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        if (!is_wp_error($result) && '' !== $name) {
            $comparisons = SSPA_Ajax_Profile::comparisons();
            $comparisons[hash('sha256', $before . $after . $name)] = array('name' => $name, 'before' => $before, 'after' => $after);
            update_option('sspa_ajax_comparisons', $comparisons, false);
        }
        return $result;
    }

    public static function exec_profile_endpoint($input) {
        return SSPA_Endpoint_Profile::start(array(
            'uuid' => sanitize_text_field((string) ($input['uuid'] ?? '')),
            'key' => sanitize_key((string) ($input['key'] ?? '')),
            // Sanitised as a URL inside start(), after its placeholder check.
            'url' => sanitize_text_field((string) ($input['url'] ?? '')),
            'samples' => isset($input['samples']) ? absint($input['samples']) : SSPA_Endpoint_Profile::DEFAULT_SAMPLES,
            'confirmed' => self::confirmed($input),
            'as' => isset($input['as']) ? sanitize_key((string) $input['as']) : null,
        ));
    }

    public static function exec_history_expectation($input) {
        $page = sanitize_text_field((string) ($input['page_identity'] ?? ''));
        $result = 'approve' === ($input['mode'] ?? '')
            ? SSPA_History::approve_assertion((int) ($input['after_run_id'] ?? 0), $page)
            : SSPA_History::clear_assertion($page);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!$result) {
            return new WP_Error('sspa_expectation', __('That expectation could not be changed.', 'super-speedy-performance-analysis'));
        }
        return array('changed' => true);
    }

    public static function exec_process_submissions($input) {
        SSPA_Community_Worker::run();
        return array('more' => (bool) SSPA_Community_Outbox::due(), 'counts' => SSPA_Community_Outbox::counts());
    }

    public static function exec_manage_submission($input) {
        $id = (int) ($input['outbox_id'] ?? 0);
        $operation = (string) ($input['operation'] ?? '');
        if ('retry' === $operation) {
            $result = SSPA_Community_Outbox::retry_now($id);
        } elseif ('pause' === $operation) {
            $result = SSPA_Community_Outbox::pause($id);
        } else {
            $result = SSPA_Community_Outbox::resume($id);
        }
        return is_wp_error($result) ? $result : array('outbox_id' => $id, 'operation' => $operation);
    }

    public static function exec_update_settings($input) {
        // Validate the loopback fields before changing anything, so a bad value changes nothing.
        if (isset($input['loopback_origin']) && is_wp_error($origin = SSPA_Loopback_Route::normalise_origin($input['loopback_origin']))) {
            return $origin;
        }
        $credentials = isset($input['loopback_credentials']) ? (array) $input['loopback_credentials'] : null;
        if (null !== $credentials && empty($credentials['clear'])
            && is_wp_error($valid = SSPA_Loopback_Route::validate_credentials((string) ($credentials['user'] ?? ''), (string) ($credentials['password'] ?? '')))) {
            return $valid;
        }
        if (isset($input['plugin_update_detection'])) {
            sspa_update_option('plugin_update_detection', (bool) $input['plugin_update_detection']);
            if (!$input['plugin_update_detection']) {
                SSPA_Change_Set::dismiss();
            }
        }
        if (isset($input['remove_data_on_uninstall'])) {
            sspa_update_option('remove_data_on_uninstall', (bool) $input['remove_data_on_uninstall']);
        }
        foreach ((array) ($input['publishers'] ?? array()) as $slug => $enabled) {
            SSPA_Community_State::set_enabled(sanitize_text_field((string) $slug), (bool) $enabled);
        }
        if (isset($input['loopback_origin'])) {
            SSPA_Loopback_Route::set_origin($input['loopback_origin']);
        }
        if (null !== $credentials) {
            if (!empty($credentials['clear'])) {
                SSPA_Loopback_Route::clear_credentials();
            } else {
                SSPA_Loopback_Route::set_credentials((string) ($credentials['user'] ?? ''), (string) $credentials['password']);
            }
        }
        return self::exec_get_settings(array());
    }

    public static function exec_prepare_quote($input) {
        return SSPA_Quote_Request::prepare_draft((int) ($input['profile_id'] ?? 0), sanitize_key((string) ($input['source'] ?? '')));
    }

    /* ---------------- destructive executors ---------------- */

    public static function exec_prune($input) {
        return self::confirmed($input) ? SSPA_Run_Controller::prune_blobs() : self::confirm_error();
    }

    public static function exec_delete_traffic($input) {
        if (!self::confirmed($input)) {
            return self::confirm_error();
        }
        $result = SSPA_Traffic_Collection::delete((int) ($input['collection_id'] ?? 0));
        return is_wp_error($result) ? $result : array('deleted' => (int) $input['collection_id'], 'result' => $result);
    }

    public static function exec_cleanup_workflow($input) {
        if (!self::confirmed($input)) {
            return self::confirm_error();
        }
        $entry = get_option(SSPA_Workflow_Analysis::TEMP_OPTION, array());
        if (!empty($entry['run_id'])) {
            SSPA_Run_Controller::cancel((int) $entry['run_id']);
        }
        SSPA_Workflow_Analysis::cleanup();
        return array('removed_object_id' => (int) ($entry['id'] ?? 0));
    }

    public static function exec_send_quote($input) {
        if (!self::confirmed($input)) {
            return self::confirm_error();
        }
        return SSPA_Quote_Request::send_draft(sanitize_text_field((string) ($input['request_uuid'] ?? '')), array(
            'consent' => isset($input['consent']) && true === $input['consent'],
            'site_url' => sanitize_text_field((string) ($input['site_url'] ?? '')),
            'name' => sanitize_text_field((string) ($input['name'] ?? '')),
            'email' => sanitize_text_field((string) ($input['email'] ?? '')),
            'ongoing_care' => !empty($input['ongoing_care']),
            'marketing_opt_in' => !empty($input['marketing_opt_in']),
            'message' => sanitize_textarea_field((string) ($input['message'] ?? '')),
        ));
    }
}
