<?php
defined('ABSPATH') || exit;

/**
 * The update watchdog (Pro). This file is deleted from the wordpress.org build.
 *
 * After a plugin, theme or WordPress core update (wp-admin, WP-Cron automatic updates or WP-CLI)
 * it waits for the batch to settle, measures the pages the last full analysis measured with no
 * browser (front end, wp-admin as the administrator and the logged-in customer pages), runs the
 * checkout test, compares the result with the last analysis before the updates and emails the
 * administrator only when something got worse. A weekly check does the same with no update.
 *
 * A regression is a page or checkout step at least threshold_percent AND threshold_ms slower, a
 * page or step that loaded before and now fails, a changed HTTP status, an approved output that
 * no longer matches, or a new PHP fatal or error. Each regressed page or step is measured once
 * more before it counts; only what regresses both times is reported.
 *
 * Everything runs as a chain of WP-Cron events: sspa_watchdog_run starts the page measurement,
 * then sspa_watchdog_step checks every STEP_SECONDS whether the current measurement finished and
 * starts the next (re-measurement, checkout test, checkout re-measurement), and finally sends the
 * verdict. The measurements themselves are ordinary runs (trigger_source 'watchdog') driven by
 * SSPA_Run_Controller's own batch events, so they appear in Compare like any other run.
 *
 * Design: .docs/2026-11-24-update-watchdog.md (approved by Dave, 24 November 2026).
 */
class SSPA_Watchdog {

    const SETTINGS = 'sspa_watchdog_settings';
    const STATE = 'sspa_watchdog_state';
    const LOG = 'sspa_watchdog_log';
    const CORE_VERSION = 'sspa_watchdog_core_version';

    const RUN_HOOK = 'sspa_watchdog_run';
    const STEP_HOOK = 'sspa_watchdog_step';
    const WEEKLY_HOOK = 'sspa_watchdog_weekly';

    /** Wait after the latest update before measuring, so a batch of updates gives one run. */
    const SETTLE_SECONDS = 600;
    /** How often the chain checks whether the current measurement finished. */
    const STEP_SECONDS = 15;
    /** Retry delay when another analysis is running. */
    const RETRY_SECONDS = 600;
    /** A watchdog whose current measurement has not finished after this long is abandoned. */
    const STALE_SECONDS = 14400;
    const LOG_LIMIT = 30;
    const TRIGGER = 'watchdog';

    /** Never measured by the watchdog: the mail probe emails the administrator per request. */
    const SKIPPED_PAGES = array('mail-probe');

    private static $before = array();

    // ---------------------------------------------------------------- settings

    public static function defaults() {
        return array(
            'enabled' => true,
            'recipients' => '',
            'threshold_percent' => 25,
            'threshold_ms' => 150,
            'weekly_day' => 'monday',
            'checkout' => true,
        );
    }

    public static function days() {
        return array(
            'monday' => __('Monday', 'super-speedy-performance-analysis'),
            'tuesday' => __('Tuesday', 'super-speedy-performance-analysis'),
            'wednesday' => __('Wednesday', 'super-speedy-performance-analysis'),
            'thursday' => __('Thursday', 'super-speedy-performance-analysis'),
            'friday' => __('Friday', 'super-speedy-performance-analysis'),
            'saturday' => __('Saturday', 'super-speedy-performance-analysis'),
            'sunday' => __('Sunday', 'super-speedy-performance-analysis'),
        );
    }

    public static function settings() {
        $saved = get_option(self::SETTINGS, array());
        return array_merge(self::defaults(), is_array($saved) ? $saved : array());
    }

    public static function setting($key) {
        $settings = self::settings();
        return isset($settings[$key]) ? $settings[$key] : null;
    }

    public static function enabled() {
        return (bool) self::setting('enabled');
    }

    /**
     * Validate a partial settings change. Nothing is saved when any field is invalid.
     *
     * @return array|WP_Error the complete settings after the change
     */
    public static function update_settings($input) {
        $settings = self::settings();
        $input = is_array($input) ? $input : array();
        foreach (array('enabled', 'checkout') as $flag) {
            if (array_key_exists($flag, $input)) {
                $settings[$flag] = (bool) $input[$flag];
            }
        }
        if (array_key_exists('recipients', $input)) {
            $list = array();
            foreach (preg_split('/[\s,;]+/', (string) $input['recipients'], -1, PREG_SPLIT_NO_EMPTY) as $address) {
                $address = sanitize_email($address);
                if (!$address || !is_email($address)) {
                    return new WP_Error('sspa_watchdog_recipient', sprintf(
                        /* translators: %s: the address that was entered. */
                        __('"%s" is not an email address. Separate several addresses with commas.', 'super-speedy-performance-analysis'),
                        sanitize_text_field((string) $input['recipients'])
                    ));
                }
                $list[] = $address;
            }
            $settings['recipients'] = implode(', ', array_unique($list));
        }
        if (array_key_exists('threshold_percent', $input)) {
            $percent = (int) $input['threshold_percent'];
            if ($percent < 1 || $percent > 1000) {
                return new WP_Error('sspa_watchdog_threshold', __('The percentage threshold must be between 1 and 1000.', 'super-speedy-performance-analysis'));
            }
            $settings['threshold_percent'] = $percent;
        }
        if (array_key_exists('threshold_ms', $input)) {
            $ms = (int) $input['threshold_ms'];
            if ($ms < 1 || $ms > 60000) {
                return new WP_Error('sspa_watchdog_threshold', __('The milliseconds threshold must be between 1 and 60000.', 'super-speedy-performance-analysis'));
            }
            $settings['threshold_ms'] = $ms;
        }
        if (array_key_exists('weekly_day', $input)) {
            $day = sanitize_key((string) $input['weekly_day']);
            if (!isset(self::days()[$day])) {
                return new WP_Error('sspa_watchdog_day', __('Choose a day of the week for the weekly check.', 'super-speedy-performance-analysis'));
            }
            $settings['weekly_day'] = $day;
        }
        $previous = self::settings();
        update_option(self::SETTINGS, $settings, false);
        if (!$settings['enabled']) {
            wp_clear_scheduled_hook(self::WEEKLY_HOOK);
            wp_clear_scheduled_hook(self::RUN_HOOK);
            $state = self::state();
            $state['pending'] = null;
            self::save_state($state);
        } elseif (!$previous['enabled'] || $previous['weekly_day'] !== $settings['weekly_day']) {
            wp_clear_scheduled_hook(self::WEEKLY_HOOK);
            self::schedule_weekly();
        }
        return $settings;
    }

    /** Where alerts go: the saved addresses, or the site administrator. */
    public static function recipients() {
        $saved = trim((string) self::setting('recipients'));
        if ('' !== $saved) {
            return array_map('trim', explode(',', $saved));
        }
        return array((string) get_option('admin_email'));
    }

    // ---------------------------------------------------------------- registration

    public static function register() {
        add_action(self::RUN_HOOK, array(__CLASS__, 'run_scheduled'));
        add_action(self::STEP_HOOK, array(__CLASS__, 'step'));
        add_action(self::WEEKLY_HOOK, array(__CLASS__, 'run_weekly'));
        add_action('wp_ajax_sspa_watchdog_settings', array(__CLASS__, 'ajax_settings'));
        add_action('wp_ajax_sspa_watchdog_run_now', array(__CLASS__, 'ajax_run_now'));
        add_action('admin_post_sspa_watchdog_markdown', array(__CLASS__, 'download_markdown'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));

        // Updates happen in wp-admin, in WP-Cron automatic updates and in WP-CLI; visitor
        // requests never update anything, so nothing is hooked on them.
        $is_cli = defined('WP_CLI') && WP_CLI;
        if (is_admin() || wp_doing_cron() || $is_cli) {
            add_filter('upgrader_pre_install', array(__CLASS__, 'before_update'), 10, 2);
            add_action('upgrader_process_complete', array(__CLASS__, 'after_update'), 20, 2);
            add_action('init', array(__CLASS__, 'check_core_version'), 99);
            add_action('init', array(__CLASS__, 'schedule_weekly'), 99);
            add_action('init', array(__CLASS__, 'keep_chain'), 99);
        }
    }

    // ---------------------------------------------------------------- recording updates

    /** Snapshot the version before the files change. The filter response is returned unchanged. */
    public static function before_update($response, $hook_extra) {
        if (!is_array($hook_extra) || (defined('SSPA_PROFILED_REQUEST') && SSPA_PROFILED_REQUEST)) {
            return $response;
        }
        foreach (self::plugins_in($hook_extra) as $file) {
            $data = self::plugin_header($file);
            if ($data) {
                self::$before['plugin:' . $file] = $data['version'];
            }
        }
        foreach (self::themes_in($hook_extra) as $slug) {
            $data = self::theme_header($slug);
            if ($data) {
                self::$before['theme:' . $slug] = $data['version'];
            }
        }
        return $response;
    }

    public static function after_update($upgrader, $hook_extra) {
        if (!is_array($hook_extra) || 'update' !== ($hook_extra['action'] ?? '')) {
            return;
        }
        $type = (string) ($hook_extra['type'] ?? '');
        if ('plugin' === $type) {
            foreach (self::plugins_in($hook_extra) as $file) {
                $after = self::plugin_header($file);
                $from = self::$before['plugin:' . $file] ?? '';
                unset(self::$before['plugin:' . $file]);
                if ($after) {
                    self::record_update('plugin', dirname($file) !== '.' ? dirname($file) : basename($file, '.php'), $after['name'], $from, $after['version']);
                }
            }
        } elseif ('theme' === $type) {
            foreach (self::themes_in($hook_extra) as $slug) {
                $after = self::theme_header($slug);
                $from = self::$before['theme:' . $slug] ?? '';
                unset(self::$before['theme:' . $slug]);
                if ($after) {
                    self::record_update('theme', $slug, $after['name'], $from, $after['version']);
                }
            }
        } elseif ('core' === $type) {
            self::check_core_version(true);
        }
    }

    /**
     * A core version change, however it happened (the updater, WP-CLI, files replaced by hand or
     * by the host): the version on disk is compared with the last one this site saw.
     */
    public static function check_core_version($from_disk = false) {
        if (defined('SSPA_PROFILED_REQUEST') && SSPA_PROFILED_REQUEST) {
            return;
        }
        $current = $from_disk ? self::core_version_on_disk() : (string) get_bloginfo('version');
        if ('' === $current) {
            return;
        }
        $seen = (string) get_option(self::CORE_VERSION, '');
        if ($seen === $current) {
            return;
        }
        update_option(self::CORE_VERSION, $current, true);
        if ('' !== $seen) {
            self::record_update('core', 'wordpress', 'WordPress', $seen, $current);
        }
    }

    private static function core_version_on_disk() {
        $file = ABSPATH . WPINC . '/version.php';
        $source = is_readable($file) ? (string) file_get_contents($file) : '';
        return preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m) ? SSPA_Version::normalise($m[1]) : '';
    }

    /**
     * Add one update to the pending batch and push the watchdog run back to SETTLE_SECONDS after it,
     * so twelve updates in a row give one run.
     */
    public static function record_update($type, $slug, $name, $from, $to) {
        if (!self::enabled() || (defined('SSPA_PROFILED_REQUEST') && SSPA_PROFILED_REQUEST)) {
            return null;
        }
        $type = in_array($type, array('plugin', 'theme', 'core'), true) ? $type : '';
        $slug = sanitize_key((string) $slug);
        $from = SSPA_Version::normalise((string) $from);
        $to = SSPA_Version::normalise((string) $to);
        if ('' === $type || '' === $slug || '' === $to || $from === $to) {
            return null;
        }
        // Updating Performance Analysis itself changes the measuring instrument, not the site.
        if ('plugin' === $type && 0 === strpos($slug, 'super-speedy-performance-analysis')) {
            return null;
        }
        $state = self::state();
        $now = time();
        $pending = is_array($state['pending']) ? $state['pending'] : array('first_at' => $now, 'updates' => array());
        $key = $type . ':' . $slug;
        $existing = isset($pending['updates'][$key]) ? $pending['updates'][$key] : null;
        $pending['updates'][$key] = array(
            'type' => $type,
            'slug' => $slug,
            'name' => sanitize_text_field((string) $name),
            // Two updates of one component in a batch read as one: first from, last to.
            'from' => $existing && '' !== $existing['from'] ? $existing['from'] : $from,
            'to' => $to,
            'at' => $now,
        );
        $pending['updates'] = array_slice($pending['updates'], -50, null, true);
        $pending['last_at'] = $now;
        $state['pending'] = $pending;
        self::save_state($state);

        wp_clear_scheduled_hook(self::RUN_HOOK);
        wp_schedule_single_event($now + self::SETTLE_SECONDS, self::RUN_HOOK);
        return $pending;
    }

    private static function plugins_in($hook_extra) {
        $files = array();
        if (!empty($hook_extra['plugin']) && is_string($hook_extra['plugin'])) {
            $files[] = $hook_extra['plugin'];
        }
        if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            $files = array_merge($files, $hook_extra['plugins']);
        }
        $out = array();
        foreach ($files as $file) {
            $file = ltrim(wp_normalize_path((string) $file), '/');
            if (preg_match('#^[A-Za-z0-9._/-]+\.php$#', $file) && false === strpos($file, '..')) {
                $out[$file] = $file;
            }
        }
        return array_values($out);
    }

    private static function themes_in($hook_extra) {
        $themes = array();
        if (!empty($hook_extra['theme']) && is_string($hook_extra['theme'])) {
            $themes[] = $hook_extra['theme'];
        }
        if (!empty($hook_extra['themes']) && is_array($hook_extra['themes'])) {
            $themes = array_merge($themes, $hook_extra['themes']);
        }
        return array_values(array_unique(array_filter(array_map(static function ($slug) {
            return preg_match('#^[A-Za-z0-9._-]+$#', (string) $slug) ? (string) $slug : '';
        }, $themes))));
    }

    /** Read from the file every time: an update changes it within this request. */
    private static function plugin_header($file) {
        $path = WP_PLUGIN_DIR . '/' . $file;
        if (!is_file($path)) {
            return null;
        }
        $data = get_file_data($path, array('name' => 'Plugin Name', 'version' => 'Version'));
        return array('name' => $data['name'] ? $data['name'] : $file, 'version' => SSPA_Version::normalise($data['version']));
    }

    private static function theme_header($slug) {
        $path = get_theme_root($slug) . '/' . $slug . '/style.css';
        if (!is_file($path)) {
            return null;
        }
        $data = get_file_data($path, array('name' => 'Theme Name', 'version' => 'Version'));
        return array('name' => $data['name'] ? $data['name'] : $slug, 'version' => SSPA_Version::normalise($data['version']));
    }

    // ---------------------------------------------------------------- state

    public static function state() {
        $state = get_option(self::STATE, array());
        return array_merge(array('pending' => null, 'current' => null), is_array($state) ? $state : array());
    }

    private static function save_state($state) {
        update_option(self::STATE, $state, false);
    }

    public static function log() {
        $log = get_option(self::LOG, array());
        return is_array($log) ? $log : array();
    }

    /** The verdict a watchdog measurement run belongs to, for History; null for other runs. */
    public static function entry_for_run($run_id) {
        foreach (self::log() as $entry) {
            foreach ((array) $entry['runs'] as $role => $id) {
                if ((int) $id === (int) $run_id) {
                    return array('role' => $role, 'entry' => $entry);
                }
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- the run

    public static function run_scheduled() {
        self::run('update');
    }

    public static function run_weekly($retry = false) {
        if (!self::enabled()) {
            return;
        }
        if (!$retry) {
            self::schedule_weekly(true);
        }
        $result = self::run('weekly');
        if (is_wp_error($result) && 'sspa_run_active' === $result->get_error_code()) {
            wp_schedule_single_event(time() + self::RETRY_SECONDS, self::WEEKLY_HOOK, array(true));
        }
    }

    /** Keep exactly one weekly event, on the chosen day at 03:00 site time. */
    public static function schedule_weekly($force_next = false) {
        if (!self::enabled()) {
            return;
        }
        if (!$force_next && wp_next_scheduled(self::WEEKLY_HOOK)) {
            return;
        }
        try {
            $next = new DateTimeImmutable('next ' . self::setting('weekly_day') . ' 03:00', wp_timezone());
        } catch (Exception $e) {
            return;
        }
        wp_schedule_single_event($next->getTimestamp(), self::WEEKLY_HOOK);
    }

    /**
     * Start a watchdog check. kind: update (after an update batch), weekly (the heartbeat) or
     * manual (Run watchdog now, or the run-watchdog-now ability).
     *
     * @return array|WP_Error the status
     */
    public static function run($kind = 'update') {
        $state = self::state();
        if ('manual' !== $kind && !self::enabled()) {
            return new WP_Error('sspa_watchdog_off', __('The update watchdog is switched off.', 'super-speedy-performance-analysis'));
        }
        if (is_array($state['current'])) {
            if (time() - (int) $state['current']['started_at'] < self::STALE_SECONDS) {
                if ('update' === $kind) {
                    wp_schedule_single_event(time() + self::RETRY_SECONDS, self::RUN_HOOK);
                }
                return new WP_Error('sspa_watchdog_running', __('The update watchdog is already checking this site.', 'super-speedy-performance-analysis'));
            }
            self::finish($state, __('The previous watchdog check did not finish within 4 hours and was abandoned.', 'super-speedy-performance-analysis'));
            $state = self::state();
        }
        $pending = is_array($state['pending']) && !empty($state['pending']['updates']) ? $state['pending'] : null;
        if ('update' === $kind && !$pending) {
            return new WP_Error('sspa_watchdog_nothing', __('No update is waiting to be checked.', 'super-speedy-performance-analysis'));
        }
        if ('manual' === $kind && $pending) {
            $kind = 'update';
        }
        if (SSPA_Run_Controller::active_run_id()) {
            // One analysis at a time: try again later rather than measure a busy site.
            if ('update' === $kind) {
                wp_schedule_single_event(time() + self::RETRY_SECONDS, self::RUN_HOOK);
            }
            return new WP_Error('sspa_run_active', __('Another analysis is running, so the watchdog will check again in 10 minutes.', 'super-speedy-performance-analysis'));
        }

        // An update empties WordPress's own update information, so the next wp-admin request
        // contacts WordPress.org to rebuild it: a one-off cost every administrator pays once after
        // updating, and on a host that cannot reach WordPress.org, a slow failure. Rebuild it here,
        // as WordPress's own twice-daily cron does, so wp-admin is measured as it normally runs.
        self::refresh_update_information();

        $cutoff = $pending ? (int) $pending['first_at'] : time();
        $plan = self::plan_pages($cutoff, $kind);
        $current = array(
            'id' => wp_generate_uuid4(),
            'kind' => $kind,
            'started_at' => time(),
            'cutoff' => $cutoff,
            'updates' => $pending ? array_values($pending['updates']) : array(),
            'page_keys' => $plan['page_keys'],
            'baseline_run_id' => $plan['baseline_run_id'],
            'stage' => 'pages',
            'runs' => array(),
            'candidates' => array(),
            'regressions' => array(),
            'unconfirmed' => array(),
            'problems' => array(),
            'checkout_baseline_run_id' => 0,
        );

        $change_set = get_option(SSPA_Change_Set::OPTION, array());
        $history = array('baseline_run_id' => $plan['baseline_run_id']);
        if ('update' === $kind && is_array($change_set) && !empty($change_set['id'])) {
            $history['change_set'] = SSPA_Change_Set::context($change_set);
        }
        $run_id = self::start_pages_run($plan['page_keys'], $history);
        if (is_wp_error($run_id)) {
            if ('sspa_run_active' === $run_id->get_error_code()) {
                if ('update' === $kind) {
                    wp_schedule_single_event(time() + self::RETRY_SECONDS, self::RUN_HOOK);
                }
                return $run_id;
            }
            $current['problems'][] = sprintf(
                /* translators: %s: why the analysis could not start. */
                __('The page measurement could not start: %s', 'super-speedy-performance-analysis'),
                $run_id->get_error_message()
            );
            $state['pending'] = null;
            $state['current'] = $current;
            self::save_state($state);
            self::finish(self::state());
            return self::status();
        }
        if (!empty($history['change_set']['id'])) {
            // Like the quick comparison: the change set now belongs to this run.
            SSPA_Change_Set::consume($change_set['id']);
        }
        $current['runs']['pages'] = (int) $run_id;
        $state['pending'] = null;
        $state['current'] = $current;
        self::save_state($state);
        wp_clear_scheduled_hook(self::RUN_HOOK);
        self::schedule_step();
        return self::status();
    }

    private static function refresh_update_information() {
        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . WPINC . '/update.php';
        }
        wp_version_check();
        wp_update_plugins();
        wp_update_themes();
    }

    private static function start_pages_run($page_keys, $history = array()) {
        $args = array(
            'type' => 'spot',
            'page_keys' => $page_keys,
            'trigger' => self::TRIGGER,
        );
        if ($history) {
            $args['share_context'] = array('history_comparison' => $history);
        }
        return SSPA_Run_Controller::start($args);
    }

    private static function schedule_step() {
        wp_clear_scheduled_hook(self::STEP_HOOK);
        wp_schedule_single_event(time() + self::STEP_SECONDS, self::STEP_HOOK);
    }

    /**
     * Which pages to measure, and what to compare them with.
     *
     * Pages: those the last full analysis (a baseline run) before the updates measured, without
     * the mail probe. With no full analysis the whole catalogue is measured and the watchdog run
     * becomes the first point to compare with.
     *
     * Baseline: the most recent completed analysis before the updates that measured every one of
     * those pages. The weekly check prefers the previous weekly check.
     */
    private static function plan_pages($cutoff, $kind) {
        global $wpdb;
        $runs = SSPA_Schema::table('runs');
        $before = gmdate('Y-m-d H:i:s', (int) $cutoff);
        $reference = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE status = 'done' AND run_type = 'baseline' AND started <= %s ORDER BY id DESC LIMIT 1",
            $runs,
            $before
        ));
        $keys = $reference ? self::run_page_keys($reference) : array();
        if (!$keys) {
            $keys = array_values(array_unique(wp_list_pluck(SSPA_Catalogue::build(), 'page_key')));
        }
        $keys = array_values(array_diff($keys, self::SKIPPED_PAGES));

        $baseline = 0;
        if ('weekly' === $kind) {
            foreach (self::log() as $entry) {
                if ('weekly' === $entry['kind'] && !empty($entry['runs']['pages']) && self::run_measured_all((int) $entry['runs']['pages'], $keys)) {
                    $baseline = (int) $entry['runs']['pages'];
                    break;
                }
            }
        }
        if (!$baseline && $reference) {
            $candidates = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM %i WHERE status = 'done' AND run_type IN ('baseline','spot') AND id >= %d AND started <= %s ORDER BY id DESC LIMIT 20",
                $runs,
                $reference,
                $before
            ));
            foreach ($candidates as $candidate) {
                if (self::run_measured_all((int) $candidate, $keys)) {
                    $baseline = (int) $candidate;
                    break;
                }
            }
        }
        return array('page_keys' => $keys, 'baseline_run_id' => $baseline);
    }

    private static function run_page_keys($run_id) {
        global $wpdb;
        return array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT page_key FROM %i WHERE run_id = %d AND method = 'GET' AND object_cache_mode = 'normal' AND page_key NOT LIKE %s",
            SSPA_Schema::table('profiles'),
            (int) $run_id,
            $wpdb->esc_like('write-') . '%'
        )));
    }

    private static function run_measured_all($run_id, $keys) {
        return !array_diff($keys, self::run_page_keys($run_id));
    }

    // ---------------------------------------------------------------- the chain

    /**
     * Two cron processes at once (wp-cron.php spawned by a visit while WP-CLI or another
     * wp-cron.php runs events) each save the whole event list, so an event one of them scheduled
     * can be lost. A running check whose step event is missing gets it back here, on the next
     * admin, cron or WP-CLI request, instead of stalling until the 4-hour limit.
     */
    public static function keep_chain() {
        $state = get_option(self::STATE, array());
        if (is_array($state) && !empty($state['current']) && !wp_next_scheduled(self::STEP_HOOK)) {
            wp_schedule_single_event(time() + self::STEP_SECONDS, self::STEP_HOOK);
        }
    }

    /** One link of the chain: advance when the current measurement has finished. */
    public static function step() {
        $state = self::state();
        $current = $state['current'];
        if (!is_array($current)) {
            return;
        }
        $role = self::stage_role($current['stage']);
        $run_id = isset($current['runs'][$role]) ? (int) $current['runs'][$role] : 0;
        $run = $run_id ? SSPA_Run_Controller::run_row($run_id) : null;
        if ($run && in_array($run['status'], array('queued', 'crawling', 'analysing', 'cancelling'), true)) {
            if (time() - (int) $current['started_at'] > self::STALE_SECONDS) {
                self::finish($state, __('The watchdog measurement did not finish within 4 hours.', 'super-speedy-performance-analysis'));
                return;
            }
            // The same race can lose the run's own batch event. The run controller re-kicks a run
            // after 30 minutes without progress; the watchdog does not wait that long.
            $queue = SSPA_Run_Queue::get($run_id);
            if ('crawling' === $run['status'] && is_array($queue) && time() - (int) ($queue['last_progress'] ?? 0) > 60
                && !wp_next_scheduled('sspa_process_batch_event', array($run_id))) {
                wp_schedule_single_event(time() + 2, 'sspa_process_batch_event', array($run_id));
            }
            self::schedule_step();
            return;
        }

        switch ($current['stage']) {
            case 'pages':
                if (!$run || 'done' !== $run['status']) {
                    $current['problems'][] = sprintf(
                        /* translators: %s: why the run ended. */
                        __('The page measurement did not complete: %s', 'super-speedy-performance-analysis'),
                        $run ? SSPA_Run_Controller::end_reason($run) : __('the run is missing', 'super-speedy-performance-analysis')
                    );
                } elseif ($current['baseline_run_id']) {
                    $current['candidates'] = self::page_regressions((int) $current['baseline_run_id'], $run_id);
                    if ($current['candidates']) {
                        $confirm = self::start_pages_run(array_values(array_unique(wp_list_pluck($current['candidates'], 'page_key'))));
                        if (!is_wp_error($confirm)) {
                            $current['runs']['confirm'] = (int) $confirm;
                            $current['stage'] = 'confirm';
                            return self::advance($state, $current);
                        }
                        $current['unconfirmed'] = $current['candidates'];
                        $current['problems'][] = sprintf(
                            /* translators: %s: why the re-measurement could not start. */
                            __('The slower pages could not be measured again: %s', 'super-speedy-performance-analysis'),
                            $confirm->get_error_message()
                        );
                    }
                }
                return self::start_checkout($state, $current);

            case 'confirm':
                $again = ($run && 'done' === $run['status']) ? self::page_regressions((int) $current['baseline_run_id'], $run_id) : array();
                self::confirm($current, $again);
                return self::start_checkout($state, $current);

            case 'checkout':
                $current['checkout_candidates'] = $current['checkout_baseline_run_id']
                    ? self::checkout_regressions((int) $current['checkout_baseline_run_id'], $run_id) : array();
                if (!empty($current['checkout_candidates'])) {
                    $again = self::start_checkout_run();
                    if (!is_wp_error($again)) {
                        $current['runs']['checkout_confirm'] = (int) $again;
                        $current['stage'] = 'checkout_confirm';
                        return self::advance($state, $current);
                    }
                    $current['unconfirmed'] = array_merge($current['unconfirmed'], $current['checkout_candidates']);
                    $current['problems'][] = sprintf(
                        /* translators: %s: why the checkout test could not start again. */
                        __('The checkout test could not run again: %s', 'super-speedy-performance-analysis'),
                        $again->get_error_message()
                    );
                }
                $state['current'] = $current;
                return self::finish($state);

            case 'checkout_confirm':
                $again = self::checkout_regressions((int) $current['checkout_baseline_run_id'], $run_id);
                $current['candidates'] = $current['checkout_candidates'];
                self::confirm($current, $again);
                $state['current'] = $current;
                return self::finish($state);
        }
    }

    private static function stage_role($stage) {
        return in_array($stage, array('pages', 'confirm', 'checkout', 'checkout_confirm'), true) ? $stage : 'pages';
    }

    private static function advance($state, $current) {
        $state['current'] = $current;
        self::save_state($state);
        self::schedule_step();
    }

    /**
     * Keep what regressed both times. A slowdown is reported at the smaller of its two
     * measurements, so a figure in an alert is one that both measurements reached.
     */
    private static function confirm(&$current, $again) {
        $second = array();
        foreach ($again as $finding) {
            $second[$finding['id']] = $finding;
        }
        foreach ($current['candidates'] as $finding) {
            if (!isset($second[$finding['id']])) {
                $current['unconfirmed'][] = $finding;
                continue;
            }
            $repeat = $second[$finding['id']];
            if ('slower' === $finding['kind'] && $repeat['delta_ms'] < $finding['delta_ms']) {
                $repeat['first_after_ms'] = $finding['after_ms'];
                $finding = $repeat;
            } elseif ('slower' === $finding['kind']) {
                $finding['second_after_ms'] = $repeat['after_ms'];
            }
            $current['regressions'][] = $finding;
        }
        $current['candidates'] = array();
    }

    private static function start_checkout($state, $current) {
        if (!self::setting('checkout') || !class_exists('WooCommerce')) {
            $current['checkout'] = self::setting('checkout') ? 'no_store' : 'off';
            $state['current'] = $current;
            return self::finish($state);
        }
        global $wpdb;
        $current['checkout_baseline_run_id'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE status = 'done' AND run_type = 'checkout' AND started <= %s ORDER BY id DESC LIMIT 1",
            SSPA_Schema::table('runs'),
            gmdate('Y-m-d H:i:s', (int) $current['cutoff'])
        ));
        $run_id = self::start_checkout_run();
        if (is_wp_error($run_id)) {
            // Measured again before it counts, like a page: one more attempt to start.
            $run_id = self::start_checkout_run();
        }
        if (is_wp_error($run_id)) {
            $message = sprintf(
                /* translators: %s: why the checkout test could not start. */
                __('The checkout test could not start: %s', 'super-speedy-performance-analysis'),
                $run_id->get_error_message()
            );
            if ($current['checkout_baseline_run_id']) {
                $current['regressions'][] = array(
                    'id' => 'checkout|start', 'kind' => 'checkout_failed', 'area' => 'checkout',
                    'page_key' => 'flow', 'label' => __('Checkout test', 'super-speedy-performance-analysis'),
                    'detail' => $message,
                );
            } else {
                $current['problems'][] = $message;
            }
            $state['current'] = $current;
            return self::finish($state);
        }
        $current['runs']['checkout'] = (int) $run_id;
        $current['stage'] = 'checkout';
        return self::advance($state, $current);
    }

    private static function start_checkout_run() {
        return SSPA_Run_Controller::start(array('type' => 'checkout', 'trigger' => self::TRIGGER));
    }

    // ---------------------------------------------------------------- the verdict rules

    /** Thresholds as the alert states them. */
    private static function is_slower($before, $after) {
        if (null === $before || null === $after || $before <= 0) {
            return false;
        }
        $delta = $after - $before;
        return $delta >= (float) self::setting('threshold_ms')
            && ($delta / $before) * 100 >= (float) self::setting('threshold_percent');
    }

    /**
     * Page regressions between two runs. Every finding carries a stable id (page identity and
     * kind), so the re-measurement can be matched with the first measurement.
     */
    public static function page_regressions($before_id, $after_id) {
        $include_adhoc = SSPA_History_Series::$include_adhoc;
        SSPA_History_Series::$include_adhoc = false;
        try {
            $comparison = SSPA_History::compare($before_id, $after_id);
            $before_rows = self::rows_by_identity($before_id);
            $after_rows = self::rows_by_identity($after_id);
        } finally {
            SSPA_History_Series::$include_adhoc = $include_adhoc;
        }
        if (is_wp_error($comparison)) {
            return array();
        }
        $found = array();
        foreach ((array) $comparison['pages'] as $page) {
            if (empty($page['present']['before']) || empty($page['present']['after'])) {
                continue;
            }
            $key = $page['key'];
            $base = array(
                'area' => 'page',
                'page_key' => $page['page_key'],
                'variant' => $page['variant'],
                'label' => self::page_label($page['page_key'], isset($after_rows[$key]['url']) ? $after_rows[$key]['url'] : ''),
                'url' => isset($after_rows[$key]['url']) ? esc_url_raw($after_rows[$key]['url']) : '',
            );
            if ('pass' === $page['validity']['before'] && 'fail' === $page['validity']['after']) {
                $found[] = $base + array(
                    'id' => $key . '|failed', 'kind' => 'failed',
                    'before_code' => $page['response_code']['before'], 'after_code' => $page['response_code']['after'],
                );
                continue;
            }
            if (null !== $page['response_code']['before'] && null !== $page['response_code']['after']
                && (int) $page['response_code']['before'] !== (int) $page['response_code']['after']) {
                $found[] = $base + array(
                    'id' => $key . '|status', 'kind' => 'status',
                    'before_code' => (int) $page['response_code']['before'], 'after_code' => (int) $page['response_code']['after'],
                );
            }
            if ('fail' === $page['declared']['state']) {
                $found[] = $base + array('id' => $key . '|output', 'kind' => 'output');
            }
            $gen = $page['metrics']['generation_ms'];
            if (self::is_slower($gen['before'], $gen['after'])) {
                $found[] = $base + array(
                    'id' => $key . '|slower', 'kind' => 'slower',
                    'before_ms' => round((float) $gen['before']), 'after_ms' => round((float) $gen['after']),
                    'delta_ms' => round((float) $gen['after'] - (float) $gen['before']),
                    'percent' => round((((float) $gen['after'] - (float) $gen['before']) / (float) $gen['before']) * 100),
                );
            }
            foreach (self::new_errors(isset($before_rows[$key]) ? $before_rows[$key] : null, isset($after_rows[$key]) ? $after_rows[$key] : null) as $error) {
                $found[] = $base + $error + array('id' => $key . '|error|' . $error['fingerprint']);
            }
        }
        return $found;
    }

    /** Checkout regressions: the flow failing, a step failing or slowing down, a new error. */
    public static function checkout_regressions($before_id, $after_id) {
        $before_run = SSPA_Run_Controller::run_row($before_id);
        $after_run = SSPA_Run_Controller::run_row($after_id);
        if (!$before_run || !$after_run) {
            return array();
        }
        $found = array();
        $label = __('Checkout test', 'super-speedy-performance-analysis');
        if ('done' === $before_run['status'] && 'done' !== $after_run['status']) {
            $found[] = array(
                'id' => 'checkout|flow|failed', 'kind' => 'checkout_failed', 'area' => 'checkout',
                'page_key' => 'flow', 'label' => $label,
                'detail' => SSPA_Run_Controller::end_reason($after_run),
            );
        }
        $before_steps = self::rows_by_identity($before_id, true);
        $after_steps = self::rows_by_identity($after_id, true);
        foreach ($before_steps as $step_key => $before) {
            $step_label = self::page_label($before['page_key']);
            $base = array('area' => 'checkout', 'page_key' => $before['page_key'], 'variant' => $before['variant'], 'label' => $step_label, 'url' => '');
            $after = isset($after_steps[$step_key]) ? $after_steps[$step_key] : null;
            $before_ok = self::row_valid($before);
            if (!$after) {
                if ($before_ok && 'done' === $after_run['status']) {
                    $found[] = $base + array('id' => 'checkout|' . $step_key . '|failed', 'kind' => 'failed', 'before_code' => (int) $before['response_code'], 'after_code' => null);
                }
                continue;
            }
            if ($before_ok && !self::row_valid($after)) {
                $found[] = $base + array('id' => 'checkout|' . $step_key . '|failed', 'kind' => 'failed', 'before_code' => (int) $before['response_code'], 'after_code' => null === $after['response_code'] ? null : (int) $after['response_code']);
                continue;
            }
            $b = null === $before['page_gen_ms'] ? null : (float) $before['page_gen_ms'];
            $a = null === $after['page_gen_ms'] ? null : (float) $after['page_gen_ms'];
            if (self::is_slower($b, $a)) {
                $found[] = $base + array(
                    'id' => 'checkout|' . $step_key . '|slower', 'kind' => 'slower',
                    'before_ms' => round($b), 'after_ms' => round($a), 'delta_ms' => round($a - $b),
                    'percent' => round((($a - $b) / $b) * 100),
                );
            }
            foreach (self::new_errors($before, $after) as $error) {
                $found[] = $base + $error + array('id' => 'checkout|' . $step_key . '|error|' . $error['fingerprint']);
            }
        }
        return $found;
    }

    private static function row_valid($row) {
        return empty($row['blocked_by']) && null !== $row['response_code']
            && (int) $row['response_code'] >= 200 && (int) $row['response_code'] < 400
            && null !== $row['page_gen_ms'];
    }

    /** Profiles of a run keyed by page identity (checkout steps by page key and method). */
    private static function rows_by_identity($run_id, $steps = false) {
        $out = array();
        foreach (SSPA_History_Series::profile_rows((int) $run_id) as $row) {
            $key = $steps ? sanitize_key($row['page_key']) . '|' . strtoupper(sanitize_key($row['method'])) : SSPA_History_Series::page_identity($row);
            if (!isset($out[$key])) {
                $out[$key] = $row;
            }
        }
        return $out;
    }

    /** PHP fatals and errors in the after samples that the before samples did not have. */
    private static function new_errors($before, $after) {
        $old = self::errors_in($before);
        $new = array();
        foreach (self::errors_in($after) as $fingerprint => $error) {
            if (!isset($old[$fingerprint])) {
                $new[] = $error;
            }
        }
        return $new;
    }

    private static function errors_in($row) {
        $out = array();
        if (!is_array($row) || empty($row['samples'])) {
            return $out;
        }
        foreach ((array) json_decode((string) $row['samples'], true) as $sample) {
            if (!is_array($sample)) {
                continue;
            }
            if (!empty($sample['fatal']) && is_array($sample['fatal'])) {
                $component = sanitize_key((string) ($sample['fatal']['component'] ?? ''));
                $fingerprint = 'fatal-' . md5($component . '|' . (string) ($sample['fatal']['fingerprint'] ?? '') . '|' . (string) ($sample['fatal']['type'] ?? ''));
                $out[$fingerprint] = array('kind' => 'fatal', 'component' => $component, 'message' => '', 'fingerprint' => $fingerprint);
            }
            $events = isset($sample['php_diagnostics']['events']) && is_array($sample['php_diagnostics']['events']) ? $sample['php_diagnostics']['events'] : array();
            foreach ($events as $event) {
                if (!is_array($event) || 'error' !== ($event['severity'] ?? '')) {
                    continue;
                }
                $component = sanitize_text_field((string) ($event['component'] ?? ''));
                $message = substr(sanitize_text_field((string) ($event['message'] ?? '')), 0, 300);
                $fingerprint = 'error-' . md5($component . '|' . (string) ($event['file'] ?? '') . '|' . (int) ($event['line'] ?? 0) . '|' . $message);
                $out[$fingerprint] = array('kind' => 'error', 'component' => $component, 'message' => $message, 'fingerprint' => $fingerprint);
            }
        }
        return $out;
    }

    private static function page_label($page_key, $url = '') {
        $labels = array(
            'home' => __('Home page', 'super-speedy-performance-analysis'),
            'shop' => __('Shop page', 'super-speedy-performance-analysis'),
            'customer-shop' => __('Shop page (logged-in customer)', 'super-speedy-performance-analysis'),
            'customer-product' => __('Product page (logged-in customer)', 'super-speedy-performance-analysis'),
            'customer-account' => __('My account (logged-in customer)', 'super-speedy-performance-analysis'),
            'customer-orders' => __('My orders (logged-in customer)', 'super-speedy-performance-analysis'),
            'product-single' => __('Product page', 'super-speedy-performance-analysis'),
            'product-cat' => __('Product category page', 'super-speedy-performance-analysis'),
            'wc-cart' => __('Cart page', 'super-speedy-performance-analysis'),
            'wc-checkout' => __('Checkout page', 'super-speedy-performance-analysis'),
        );
        $page_key = sanitize_key($page_key);
        return isset($labels[$page_key]) ? $labels[$page_key] : SSPA_History_Series::page_label($page_key, $url);
    }

    // ---------------------------------------------------------------- finishing and the email

    /** Record the verdict, send the email when one is due, and clear the current check. */
    private static function finish($state, $problem = '') {
        $current = $state['current'];
        if (!is_array($current)) {
            return null;
        }
        if ('' !== $problem) {
            $current['problems'][] = $problem;
        }
        $first = empty($current['baseline_run_id']) && !empty($current['runs']['pages']);
        $verdict = $current['regressions'] ? 'regressed' : ($current['problems'] ? 'incomplete' : ($first ? 'first' : 'clear'));
        $entry = array(
            'id' => $current['id'],
            'kind' => $current['kind'],
            'verdict' => $verdict,
            'started_at' => (int) $current['started_at'],
            'finished_at' => time(),
            'updates' => $current['updates'],
            'baseline_run_id' => (int) $current['baseline_run_id'],
            'checkout_baseline_run_id' => (int) $current['checkout_baseline_run_id'],
            'checkout' => isset($current['checkout']) ? $current['checkout'] : (empty($current['runs']['checkout']) ? 'not_run' : 'measured'),
            'runs' => array_map('intval', $current['runs']),
            'regressions' => array_values($current['regressions']),
            'unconfirmed' => array_values($current['unconfirmed']),
            'problems' => array_values($current['problems']),
            'thresholds' => array('percent' => (int) self::setting('threshold_percent'), 'ms' => (int) self::setting('threshold_ms')),
            'email' => null,
        );
        // No email when nothing regressed, except the weekly heartbeat, the first check (which
        // says it is now the point to compare with) and a check that could not measure.
        if ('regressed' === $verdict || 'incomplete' === $verdict || 'first' === $verdict || 'weekly' === $current['kind']) {
            $entry['email'] = self::send($entry);
        }
        $log = self::log();
        array_unshift($log, $entry);
        update_option(self::LOG, array_slice($log, 0, self::LOG_LIMIT), false);
        $state = self::state();
        $state['current'] = null;
        self::save_state($state);
        wp_clear_scheduled_hook(self::STEP_HOOK);
        return $entry;
    }

    private static function send($entry) {
        $message = self::message($entry);
        $to = self::recipients();
        $sent = wp_mail($to, $message['subject'], $message['body'], array('X-SSPA-Watchdog: ' . $entry['verdict']));
        return array('to' => $to, 'subject' => $message['subject'], 'sent' => (bool) $sent, 'at' => time());
    }

    /** The update a subject names: the only one, or the first and how many others. */
    private static function updates_phrase($updates) {
        if (!$updates) {
            return '';
        }
        $first = $updates[0];
        $name = $first['name'] . ' ' . $first['to'];
        if (1 === count($updates)) {
            return $name;
        }
        /* translators: 1: a component name and version, 2: how many other updates. */
        return sprintf(_n('%1$s and %2$d other update', '%1$s and %2$d other updates', count($updates) - 1, 'super-speedy-performance-analysis'), $name, count($updates) - 1);
    }

    /** The finding the subject names: a failure first, then a new error, then the biggest slowdown. */
    private static function worst($regressions) {
        $rank = array('checkout_failed' => 0, 'failed' => 1, 'fatal' => 2, 'error' => 3, 'status' => 4, 'output' => 5, 'slower' => 6);
        usort($regressions, static function ($a, $b) use ($rank) {
            $order = ($rank[$a['kind']] ?? 9) <=> ($rank[$b['kind']] ?? 9);
            return $order ? $order : (($b['delta_ms'] ?? 0) <=> ($a['delta_ms'] ?? 0));
        });
        return $regressions ? $regressions[0] : null;
    }

    public static function describe($finding) {
        $label = $finding['label'];
        switch ($finding['kind']) {
            case 'slower':
                return sprintf(
                    /* translators: 1: page or step, 2: ms before, 3: ms after, 4: ms difference, 5: percent difference. */
                    __('%1$s: %2$d ms before, %3$d ms after, %4$d ms slower (+%5$d%%)', 'super-speedy-performance-analysis'),
                    $label, $finding['before_ms'], $finding['after_ms'], $finding['delta_ms'], $finding['percent']
                );
            case 'failed':
                return sprintf(
                    /* translators: 1: page or step, 2: HTTP status before, 3: HTTP status after or "no response". */
                    __('%1$s no longer loads (HTTP %2$s before, %3$s now)', 'super-speedy-performance-analysis'),
                    $label, null === $finding['before_code'] ? '-' : (int) $finding['before_code'],
                    null === $finding['after_code'] ? __('no response', 'super-speedy-performance-analysis') : 'HTTP ' . (int) $finding['after_code']
                );
            case 'status':
                return sprintf(
                    /* translators: 1: page, 2: HTTP status before, 3: HTTP status after. */
                    __('%1$s answers HTTP %3$d, was HTTP %2$d', 'super-speedy-performance-analysis'),
                    $label, $finding['before_code'], $finding['after_code']
                );
            case 'output':
                /* translators: %s: page. */
                return sprintf(__('%s output no longer matches the output approved in Compare', 'super-speedy-performance-analysis'), $label);
            case 'fatal':
                return '' !== $finding['component']
                    /* translators: 1: page or step, 2: plugin or theme slug. */
                    ? sprintf(__('%1$s: new PHP fatal error in %2$s', 'super-speedy-performance-analysis'), $label, $finding['component'])
                    /* translators: %s: page or step. */
                    : sprintf(__('%s: new PHP fatal error', 'super-speedy-performance-analysis'), $label);
            case 'error':
                return sprintf(
                    /* translators: 1: page or step, 2: component, 3: the PHP error message. */
                    __('%1$s: new PHP error in %2$s: %3$s', 'super-speedy-performance-analysis'),
                    $label, '' !== $finding['component'] ? $finding['component'] : __('an unknown component', 'super-speedy-performance-analysis'), $finding['message']
                );
            case 'checkout_failed':
                /* translators: 1: "Checkout test", 2: why it failed. */
                return sprintf(__('%1$s failed: %2$s', 'super-speedy-performance-analysis'), $label, $finding['detail']);
        }
        return $label;
    }

    private static function subject_for($finding, $updates_phrase) {
        switch ($finding['kind']) {
            case 'slower':
                return '' !== $updates_phrase
                    /* translators: 1: page, 2: milliseconds, 3: the update. */
                    ? sprintf(__('%1$s %2$d ms slower after %3$s', 'super-speedy-performance-analysis'), $finding['label'], $finding['delta_ms'], $updates_phrase)
                    /* translators: 1: page, 2: milliseconds. */
                    : sprintf(__('%1$s %2$d ms slower than last week', 'super-speedy-performance-analysis'), $finding['label'], $finding['delta_ms']);
            case 'checkout_failed':
                return '' !== $updates_phrase
                    /* translators: %s: the update. */
                    ? sprintf(__('Checkout test failed after %s', 'super-speedy-performance-analysis'), $updates_phrase)
                    : __('Checkout test failed', 'super-speedy-performance-analysis');
        }
        return '' !== $updates_phrase
            /* translators: 1: the finding, 2: the update. */
            ? sprintf(__('%1$s after %2$s', 'super-speedy-performance-analysis'), self::describe($finding), $updates_phrase)
            : self::describe($finding);
    }

    /** @return array{subject: string, body: string} */
    public static function message($entry) {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $prefix = '' !== $site ? '[' . $site . '] ' : '';
        $updates_phrase = self::updates_phrase($entry['updates']);
        $worst = self::worst($entry['regressions']);
        if ($worst) {
            $subject = self::subject_for($worst, $updates_phrase);
        } elseif ('incomplete' === $entry['verdict']) {
            $subject = __('The update watchdog could not finish its check', 'super-speedy-performance-analysis');
        } elseif ('first' === $entry['verdict']) {
            $subject = __('The update watchdog has saved its first point to compare with', 'super-speedy-performance-analysis');
        } elseif ('weekly' === $entry['kind']) {
            $subject = __('Weekly check: no regressions this week', 'super-speedy-performance-analysis');
        } else {
            $subject = '' !== $updates_phrase
                /* translators: %s: the update. */
                ? sprintf(__('No regressions after %s', 'super-speedy-performance-analysis'), $updates_phrase)
                : __('No regressions', 'super-speedy-performance-analysis');
        }

        $lines = array();
        /* translators: %s: site address. */
        $lines[] = sprintf(__('Performance Analysis Pro update watchdog for %s', 'super-speedy-performance-analysis'), home_url('/'));
        $lines[] = '';
        if ($entry['updates']) {
            $lines[] = __('Updates in this batch:', 'super-speedy-performance-analysis');
            $types = array(
                'plugin' => __('Plugin', 'super-speedy-performance-analysis'),
                'theme' => __('Theme', 'super-speedy-performance-analysis'),
                'core' => __('WordPress core', 'super-speedy-performance-analysis'),
            );
            foreach ($entry['updates'] as $update) {
                $lines[] = sprintf(
                    /* translators: 1: Plugin, Theme or WordPress core, 2: name, 3: previous version, 4: new version. */
                    __('- %1$s: %2$s %3$s -> %4$s', 'super-speedy-performance-analysis'),
                    $types[$update['type']] ?? $update['type'], $update['name'], '' !== $update['from'] ? $update['from'] : '?', $update['to']
                );
            }
            $lines[] = '';
        } elseif ('weekly' === $entry['kind']) {
            $lines[] = __('This is the weekly check: no update was needed to run it.', 'super-speedy-performance-analysis');
            $lines[] = '';
        }

        if ($entry['regressions']) {
            $lines[] = __('Regressions (each one measured twice):', 'super-speedy-performance-analysis');
            foreach ($entry['regressions'] as $finding) {
                $lines[] = '- ' . self::describe($finding) . ('' !== ($finding['url'] ?? '') ? ' ' . $finding['url'] : '');
            }
            $lines[] = '';
        } elseif ('first' !== $entry['verdict'] && !$entry['problems']) {
            $lines[] = __('No regressions: no page or checkout step got slower, failed or gained an error.', 'super-speedy-performance-analysis');
            $lines[] = '';
        }
        if ($entry['unconfirmed']) {
            $lines[] = __('Slower the first time but not when measured again (not counted):', 'super-speedy-performance-analysis');
            foreach ($entry['unconfirmed'] as $finding) {
                $lines[] = '- ' . self::describe($finding);
            }
            $lines[] = '';
        }
        foreach ($entry['problems'] as $problem) {
            $lines[] = $problem;
        }
        if ($entry['problems']) {
            $lines[] = '';
        }
        if ('first' === $entry['verdict']) {
            $lines[] = __('There was no earlier analysis of these pages to compare with, so this check is now the point the next one is compared with.', 'super-speedy-performance-analysis');
            $lines[] = '';
        }

        $after = isset($entry['runs']['pages']) ? (int) $entry['runs']['pages'] : 0;
        if ($entry['baseline_run_id'] && $after) {
            $lines[] = sprintf(
                /* translators: 1: earlier analysis ID, 2: watchdog analysis ID. */
                __('Compared analysis #%1$d (before) with analysis #%2$d (after).', 'super-speedy-performance-analysis'),
                $entry['baseline_run_id'], $after
            );
            /* translators: %s: URL. */
            $lines[] = sprintf(__('Compare the two runs: %s', 'super-speedy-performance-analysis'), self::compare_url($entry['baseline_run_id'], $after));
        }
        if ($after) {
            /* translators: %s: URL. */
            $lines[] = sprintf(__('Markdown report of the watchdog run: %s', 'super-speedy-performance-analysis'), self::markdown_url($after));
        }
        if (!empty($entry['runs']['checkout'])) {
            /* translators: %s: URL. */
            $lines[] = sprintf(__('Markdown report of the checkout test: %s', 'super-speedy-performance-analysis'), self::markdown_url((int) $entry['runs']['checkout'], 'checkout'));
        }
        $lines[] = '';
        $lines[] = sprintf(
            /* translators: 1: percent, 2: milliseconds. */
            __('A page or checkout step counts as slower when it is at least %1$d%% and at least %2$d ms slower, and is still slower when measured again.', 'super-speedy-performance-analysis'),
            $entry['thresholds']['percent'], $entry['thresholds']['ms']
        );
        $last = self::log();
        if ('weekly' === $entry['kind'] && $last) {
            $lines[] = sprintf(
                /* translators: %s: date and time. */
                __('The watchdog last checked this site before this on %s.', 'super-speedy-performance-analysis'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last[0]['finished_at'])
            );
        }
        /* translators: %s: URL. */
        $lines[] = sprintf(__('Watchdog settings: %s', 'super-speedy-performance-analysis'), admin_url('admin.php?page=sspa#compare'));

        return array('subject' => $prefix . $subject, 'body' => implode("\n", $lines) . "\n");
    }

    public static function compare_url($before_id, $after_id) {
        return add_query_arg(array(
            'page' => 'sspa',
            'sspa_compare_before' => (int) $before_id,
            'sspa_compare_after' => (int) $after_id,
        ), admin_url('admin.php')) . '#compare';
    }

    public static function markdown_url($run_id, $kind = 'run') {
        return add_query_arg(array(
            'action' => 'sspa_watchdog_markdown',
            'kind' => 'checkout' === $kind ? 'checkout' : 'run',
            'id' => (int) $run_id,
        ), admin_url('admin-post.php'));
    }

    /**
     * The Markdown export as a download, for the link in the alert. Read-only and for
     * administrators only; it carries no nonce because an email link outlives one.
     */
    public static function download_markdown() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to export performance data.', 'super-speedy-performance-analysis'), 403);
        }
        $kind = isset($_GET['kind']) && 'checkout' === $_GET['kind'] ? 'checkout' : 'run'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only download, capability checked above.
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only download, capability checked above.
        $built = SSPA_Markdown_Export::build($kind, $id);
        if (is_wp_error($built)) {
            wp_die(esc_html($built->get_error_message()), 404);
        }
        nocache_headers();
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($built['filename']) . '"');
        echo $built['markdown']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a Markdown file download, not HTML.
        exit;
    }

    // ---------------------------------------------------------------- status, admin and agents

    /** Everything get-watchdog-status returns. */
    public static function status() {
        $state = self::state();
        $current = $state['current'];
        $log = self::log();
        return array(
            'enabled' => self::enabled(),
            'settings' => self::settings(),
            'recipients' => self::recipients(),
            'running' => is_array($current),
            'current' => is_array($current) ? array(
                'kind' => $current['kind'],
                'stage' => $current['stage'],
                'started_at' => gmdate('c', (int) $current['started_at']),
                'runs' => array_map('intval', $current['runs']),
                'baseline_run_id' => (int) $current['baseline_run_id'],
                'page_keys' => $current['page_keys'],
                'updates' => $current['updates'],
            ) : null,
            'pending_updates' => is_array($state['pending']) ? array_values($state['pending']['updates']) : array(),
            'next_update_check' => self::next_iso(self::RUN_HOOK),
            'next_weekly_check' => self::next_iso(self::WEEKLY_HOOK),
            'last' => $log ? $log[0] : null,
            'recent' => array_map(static function ($entry) {
                return array(
                    'kind' => $entry['kind'],
                    'verdict' => $entry['verdict'],
                    'finished_at' => gmdate('c', (int) $entry['finished_at']),
                    'runs' => $entry['runs'],
                    'regressions' => count($entry['regressions']),
                    'emailed' => !empty($entry['email']['sent']),
                );
            }, array_slice($log, 0, 10)),
        );
    }

    private static function next_iso($hook) {
        $next = wp_next_scheduled($hook);
        return $next ? gmdate('c', $next) : null;
    }

    /**
     * run-watchdog-now: start a check (the pending update batch when there is one), or, while a
     * check is running, move it on: the current measurement's next batch, then the next stage.
     * Calling it in a loop runs a whole check on a site where WP-Cron does not fire.
     */
    public static function run_now() {
        $state = self::state();
        if (!is_array($state['current'])) {
            $started = self::run('manual');
            return is_wp_error($started) ? $started : self::status();
        }
        $current = $state['current'];
        $run_id = (int) ($current['runs'][self::stage_role($current['stage'])] ?? 0);
        if ($run_id) {
            SSPA_Run_Controller::process_batch($run_id);
        }
        self::step();
        return self::status();
    }

    public static function ajax_settings() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        $input = array();
        foreach (array('enabled', 'checkout') as $flag) {
            if (isset($_POST[$flag])) {
                $input[$flag] = '1' === sanitize_text_field(wp_unslash($_POST[$flag]));
            }
        }
        foreach (array('recipients', 'threshold_percent', 'threshold_ms', 'weekly_day') as $field) {
            if (isset($_POST[$field])) {
                $input[$field] = sanitize_text_field(wp_unslash($_POST[$field]));
            }
        }
        $settings = self::update_settings($input);
        if (is_wp_error($settings)) {
            wp_send_json_error($settings->get_error_message());
        }
        wp_send_json_success(self::status());
    }

    public static function ajax_run_now() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        if (is_array(self::state()['current'])) {
            wp_send_json_error(__('The update watchdog is already checking this site.', 'super-speedy-performance-analysis'));
        }
        $result = self::run('manual');
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result);
    }

    public static function enqueue($hook) {
        if (false === strpos((string) $hook, 'sspa')) {
            return;
        }
        wp_enqueue_script('sspa-watchdog', SSPA_PLUGIN_URL . 'includes/admin/js/sspa-watchdog.js', array('jquery', 'wp-i18n', 'sspa-feedback'), sspa_asset_version('includes/admin/js/sspa-watchdog.js'), true);
        wp_set_script_translations('sspa-watchdog', 'super-speedy-performance-analysis', SSPA_PLUGIN_DIR . 'languages');
    }

    /** The settings, in the Advanced Compare settings box (Compare tab). */
    public static function render_settings() {
        $settings = self::settings();
        $status = self::status();
        ?>
        <fieldset class="sspa-watchdog-settings" id="sspa-watchdog-settings">
            <legend><strong><?php esc_html_e('Update watchdog', 'super-speedy-performance-analysis'); ?></strong></legend>
            <p class="description"><?php esc_html_e('After any plugin, theme or WordPress update, the watchdog waits 10 minutes for further updates, measures the pages your last full analysis measured (logged-out, logged-in customer and wp-admin) and runs the checkout test, compares them with the analysis before the updates, and emails you only when something got worse. Every regressed page or step is measured a second time before it counts. A weekly check does the same with no update.', 'super-speedy-performance-analysis'); ?></p>
            <p><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>> <?php esc_html_e('Watch for updates that slow the site down', 'super-speedy-performance-analysis'); ?></label></p>
            <p><label><input type="checkbox" name="checkout" value="1" <?php checked(!empty($settings['checkout'])); ?>> <?php esc_html_e('Include the checkout test', 'super-speedy-performance-analysis'); ?></label><br>
                <span class="description"><?php esc_html_e('A hidden zero-price order is placed, completed, refunded and moved to Trash, exactly as the checkout analysis does; its order emails go to the site administrator.', 'super-speedy-performance-analysis'); ?></span></p>
            <p><label><?php esc_html_e('Email alerts to', 'super-speedy-performance-analysis'); ?>
                <input type="text" class="regular-text" name="recipients" value="<?php echo esc_attr($settings['recipients']); ?>" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>"></label><br>
                <span class="description"><?php esc_html_e('Leave empty for the site administrator. Separate several addresses with commas.', 'super-speedy-performance-analysis'); ?></span></p>
            <p><?php esc_html_e('A page or checkout step counts as slower when it is at least', 'super-speedy-performance-analysis'); ?>
                <input type="number" class="small-text" name="threshold_percent" min="1" max="1000" value="<?php echo (int) $settings['threshold_percent']; ?>" aria-label="<?php esc_attr_e('Percent', 'super-speedy-performance-analysis'); ?>"> %
                <?php esc_html_e('and at least', 'super-speedy-performance-analysis'); ?>
                <input type="number" class="small-text" name="threshold_ms" min="1" max="60000" value="<?php echo (int) $settings['threshold_ms']; ?>" aria-label="<?php esc_attr_e('Milliseconds', 'super-speedy-performance-analysis'); ?>"> <?php esc_html_e('ms slower.', 'super-speedy-performance-analysis'); ?></p>
            <p><label><?php esc_html_e('Weekly check on', 'super-speedy-performance-analysis'); ?>
                <select name="weekly_day">
                    <?php foreach (self::days() as $day => $label) : ?>
                        <option value="<?php echo esc_attr($day); ?>" <?php selected($settings['weekly_day'], $day); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select></label></p>
            <p>
                <button type="button" class="button sspa-watchdog-save"><?php esc_html_e('Save watchdog settings', 'super-speedy-performance-analysis'); ?></button>
                <button type="button" class="button sspa-watchdog-run-now" <?php disabled($status['running']); ?>><?php esc_html_e('Run watchdog now', 'super-speedy-performance-analysis'); ?></button>
                <span class="spinner" aria-hidden="true"></span>
                <span class="sspa-watchdog-feedback" role="status" aria-live="polite"></span>
            </p>
            <p class="description sspa-watchdog-last"><?php echo esc_html(self::last_sentence($status)); ?></p>
        </fieldset>
        <?php
    }

    public static function last_sentence($status) {
        if ($status['running']) {
            return __('The watchdog is checking the site now.', 'super-speedy-performance-analysis');
        }
        if (empty($status['last'])) {
            return __('The watchdog has not checked this site yet.', 'super-speedy-performance-analysis');
        }
        return sprintf(
            /* translators: 1: date and time, 2: the verdict. */
            __('Last check: %1$s, %2$s.', 'super-speedy-performance-analysis'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $status['last']['finished_at']),
            self::verdict_label($status['last'])
        );
    }

    public static function verdict_label($entry) {
        switch ($entry['verdict']) {
            case 'regressed':
                /* translators: %d: number of regressions. */
                return sprintf(_n('%d regression', '%d regressions', count($entry['regressions']), 'super-speedy-performance-analysis'), count($entry['regressions']));
            case 'incomplete':
                return __('could not finish', 'super-speedy-performance-analysis');
            case 'first':
                return __('first point to compare with', 'super-speedy-performance-analysis');
        }
        return __('no regressions', 'super-speedy-performance-analysis');
    }

    /** The History (Compare tab) note on a run the watchdog made. */
    public static function run_badge($run) {
        if (!is_array($run) || self::TRIGGER !== ($run['trigger_source'] ?? '')) {
            return '';
        }
        $found = self::entry_for_run((int) $run['id']);
        $roles = array(
            'pages' => __('Update watchdog', 'super-speedy-performance-analysis'),
            'confirm' => __('Update watchdog re-measurement', 'super-speedy-performance-analysis'),
            'checkout' => __('Update watchdog checkout test', 'super-speedy-performance-analysis'),
            'checkout_confirm' => __('Update watchdog checkout re-test', 'super-speedy-performance-analysis'),
        );
        if (!$found) {
            return __('Update watchdog: checking', 'super-speedy-performance-analysis');
        }
        return $roles[$found['role']] . ': ' . self::verdict_label($found['entry']);
    }
}
