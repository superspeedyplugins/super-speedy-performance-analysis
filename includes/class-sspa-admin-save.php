<?php
defined('ABSPATH') || exit;

/**
 * Profiles the administrator's real update/save request from an edit screen.
 *
 * Classic editors submit a POST and then redirect back to the editor. Block editors send
 * the update through REST. In both cases JavaScript asks this class for a URL-bound token,
 * attaches it to that one write request, then hands the resulting capture back here. The
 * editor reload is never part of the stored profile.
 */
class SSPA_Admin_Save {

    const FIELD_NAME = '_sspa_profile_token';

    public static function register() {
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_node'), 91);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('wp_ajax_sspa_admin_save_prepare', array(__CLASS__, 'ajax_prepare'));
        add_action('wp_ajax_sspa_admin_save_finish', array(__CLASS__, 'ajax_finish'));
    }

    /** The existing-object edit screens where an Update/Save action exists. */
    private static function screen_context() {
        if (!is_admin() || !current_user_can('manage_options') || !function_exists('get_current_screen')) {
            return null;
        }
        $screen = get_current_screen();
        if (!$screen) {
            return null;
        }

        $post = get_post();
        if ('post' === $screen->base && $post && $post->ID) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display context only.
            return array(
                'screen_id' => sanitize_key($screen->id),
                'object_type' => sanitize_key($screen->post_type ? $screen->post_type : 'post'),
                'object_id' => (int) $post->ID, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display context only.
            );
        }

        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::is_order_edit_screen()
            && !empty($GLOBALS['theorder']) && is_object($GLOBALS['theorder'])) {
            return array('screen_id' => sanitize_key($screen->id), 'object_type' => 'order', 'object_id' => (int) $GLOBALS['theorder']->get_id());
        }
        return null;
    }

    public static function admin_bar_node($bar) {
        if (!is_admin_bar_showing() || !self::screen_context()) {
            return;
        }
        $bar->add_node(array(
            'id' => 'sspa-admin-save',
            'parent' => 'sspa-measure',
            'title' => esc_html__('Analyse update/save', 'super-speedy-performance-analysis'),
            'href' => '#',
            'meta' => array(
                'title' => __('Save the current edits and profile the write request itself, excluding the editor reload', 'super-speedy-performance-analysis'),
            ),
        ));
    }

    public static function enqueue() {
        $context = self::screen_context();
        if (!$context) {
            return;
        }
        SSPA_Browser_Transport::register_script();
        SSPA_Adhoc::enqueue(true);
        wp_enqueue_script(
            'sspa-admin-save',
            SSPA_PLUGIN_URL . 'includes/admin/js/sspa-admin-save.js',
            array('jquery', 'sspa-adhoc', 'wp-api-fetch'),
            sspa_asset_version('includes/admin/js/sspa-admin-save.js'),
            true
        );
        $workflow = class_exists('SSPA_Workflow_Analysis')
            ? SSPA_Workflow_Analysis::editor_config($context)
            : array('active' => false);
        wp_localize_script('sspa-admin-save', 'sspa_admin_save', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sspa_admin'),
            'field_name' => self::FIELD_NAME,
            'screen_id' => $context['screen_id'],
            'object_type' => $context['object_type'],
            'object_id' => $context['object_id'],
            'workflow' => $workflow,
            'i18n' => array(
                'saving' => __('Saving and profiling the update request…', 'super-speedy-performance-analysis'),
                'editor_button' => __('Analyse update/save', 'super-speedy-performance-analysis'),
                'editor_button_title' => __('Save the current edits and profile the write request itself', 'super-speedy-performance-analysis'),
                'detail' => __('This is the real save. Normal emails, webhooks and integrations still run. The editor reload is not measured.', 'super-speedy-performance-analysis'),
                'workflow_detail' => __('This saves a temporary Performance Analysis object through the selected editor transport. Emails go to the site administrator and send/queue time is measured.', 'super-speedy-performance-analysis'),
                'no_control' => __('No supported Update/Save control was found on this editor.', 'super-speedy-performance-analysis'),
                'no_request' => __('The editor did not send an update request. Make a change, then try again.', 'super-speedy-performance-analysis'),
                'failed' => __('The update/save request could not be profiled.', 'super-speedy-performance-analysis'),
            ),
        ));
    }

    /**
     * Validate and classify one interactive write target.
     *
     * @return array|WP_Error {url,page_key,variant,method,screen_id,object_type,object_id}
     */
    public static function job_for($url, $method, $context = array()) {
        $url = esc_url_raw(trim((string) $url));
        $method = strtoupper(sanitize_key((string) $method));
        if (!in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
            return new WP_Error('sspa_admin_save_method', __('Only an update/save write request can be profiled here.', 'super-speedy-performance-analysis'));
        }

        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!$url || !is_array($parts) || empty($parts['host']) || 0 !== strcasecmp($parts['host'], $home['host'])) {
            return new WP_Error('sspa_admin_save_origin', __('The update request must stay on this site.', 'super-speedy-performance-analysis'));
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $admin_path = (string) wp_parse_url(admin_url('/'), PHP_URL_PATH);
        $rest_path = (string) wp_parse_url(rest_url('/'), PHP_URL_PATH);
        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $is_admin = $admin_path && 0 === strpos($path, $admin_path);
        $is_rest = ($rest_path && '/' !== $rest_path && 0 === strpos($path, $rest_path)) || isset($query['rest_route']);
        if (!$is_admin && !$is_rest) {
            return new WP_Error('sspa_admin_save_target', __('That URL is not a WordPress admin or REST save endpoint.', 'super-speedy-performance-analysis'));
        }

        $object_type = !empty($context['object_type']) ? sanitize_key($context['object_type']) : 'content';
        if ('shop_order' === $object_type || false !== strpos((string) (isset($context['screen_id']) ? $context['screen_id'] : ''), 'wc-orders')) {
            $object_type = 'order';
        }
        $key_type = substr($object_type ? $object_type : 'content', 0, 48);
        $workflow_transport = !empty($context['workflow_transport']) && in_array($context['workflow_transport'], array('classic', 'rest'), true)
            ? $context['workflow_transport']
            : '';
        $transport = $workflow_transport ? $workflow_transport : ($is_rest ? 'rest' : 'classic');
        return array(
            'url' => $url,
            'page_key' => 'admin-save-' . $key_type . ($workflow_transport ? '-' . $workflow_transport : ''),
            'variant' => 'admin',
            'method' => $method,
            'screen_id' => !empty($context['screen_id']) ? sanitize_key($context['screen_id']) : '',
            'object_type' => $object_type,
            'object_id' => !empty($context['object_id']) ? (int) $context['object_id'] : 0,
            'workflow_transport' => $workflow_transport,
            'transport' => $transport,
        );
    }

    /** Coarse outbound classification. A custom post type's actual slug never leaves the site. */
    private static function community_object_type($object_type) {
        $object_type = sanitize_key($object_type);
        if (in_array($object_type, array('post', 'page', 'product', 'order'), true)) {
            return $object_type;
        }
        if (in_array($object_type, array('shop_order', 'shop_order_refund'), true)) {
            return 'order';
        }
        if ('product_variation' === $object_type) {
            return 'product';
        }
        return 'custom-post-type';
    }

    /** Arm a single, exact save request. Public so the e2e suite drives the real post.php path. */
    public static function prepare($url, $method, $context = array(), $options = array()) {
        $mail_mode = 'deliver';
        $trigger = isset($options['trigger']) && 'workflow' === sanitize_key($options['trigger']) ? 'workflow' : 'adminbar';
        $workflow_transport = isset($options['workflow_transport']) ? sanitize_key($options['workflow_transport']) : '';
        if (in_array($workflow_transport, array('classic', 'rest'), true)) {
            $context['workflow_transport'] = $workflow_transport;
        }
        $job = self::job_for($url, $method, $context);
        if (is_wp_error($job)) {
            return $job;
        }
        if ('workflow' === $trigger && !SSPA_Workflow_Analysis::owns($job['object_type'], $job['object_id'])) {
            return new WP_Error('sspa_workflow_owned', __('Workflows can only save the temporary object created by Performance Analysis.', 'super-speedy-performance-analysis'));
        }
        $run_id = SSPA_Run_Controller::start(array(
            'type' => 'admin_save',
            'url' => $job['url'],
            'method' => $job['method'],
            'admin_save_context' => $job,
            'trigger' => $trigger,
            'share_context' => array(
                'admin_save' => array(
                    'object_type' => self::community_object_type($job['object_type']),
                    'transport' => $job['transport'],
                    'save_mode' => ('workflow' === $trigger) ? 'no-change' : 'editor-update',
                    'mail_mode' => $mail_mode,
                ),
            ),
        ));
        if (is_wp_error($run_id)) {
            return $run_id;
        }

        if ('workflow' === $trigger) {
            SSPA_Workflow_Analysis::bind_run($run_id);
        }
        $flags = array('v' => 'admin', 'as' => '1', 'mail' => 'd');
        $flags['workflow' === $trigger ? 'wf' : 'real_save'] = '1';
        $token = SSPA_Token::mint($job['url'], $flags);
        $queue = SSPA_Run_Queue::get($run_id);
        $queue['interactive'] = array(
            'token_id' => $token['id'],
            'job' => $job,
            'flags' => $flags,
            'mail_mode' => $mail_mode,
        );
        $queue['last_progress'] = time();
        SSPA_Run_Queue::save($run_id, $queue);

        return array(
            'run_id' => $run_id,
            'token_id' => $token['id'],
            'token' => $token['header'],
            'header_name' => SSPA_Token::HEADER,
            'field_name' => self::FIELD_NAME,
            'page_key' => $job['page_key'],
            'mail_mode' => $mail_mode,
        );
    }

    /** Consume the capture made by the real write request and store it as one POST/REST profile. */
    public static function finish($run_id, $token_id, $report = array()) {
        global $wpdb;
        $run_id = (int) $run_id;
        $token_id = preg_replace('/[^a-f0-9]/', '', (string) $token_id);
        $run = SSPA_Run_Controller::run_row($run_id);
        if (!$run || 'admin_save' !== $run['run_type']) {
            return new WP_Error('sspa_admin_save_run', __('That update/save analysis no longer exists.', 'super-speedy-performance-analysis'));
        }

        // Idempotent after a lost AJAX response: the capture has already been consumed, but
        // the completed profile is the exact answer the caller was waiting for.
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1',
            SSPA_Schema::table('profiles'),
            $run_id
        ));
        if ($existing && 'done' === $run['status']) {
            return array('profile_id' => $existing, 'run_id' => $run_id);
        }

        $queue = SSPA_Run_Queue::get($run_id);
        if (!is_array($queue) || empty($queue['interactive']['token_id'])
            || !hash_equals((string) $queue['interactive']['token_id'], $token_id)) {
            return new WP_Error('sspa_admin_save_token', __('The update/save measurement token does not match this run.', 'super-speedy-performance-analysis'));
        }
        $job = $queue['interactive']['job'];
        $flags = !empty($queue['interactive']['flags']) && is_array($queue['interactive']['flags'])
            ? $queue['interactive']['flags']
            : array('v' => 'admin', 'mail' => 'd', 'as' => '1');
        $code = isset($report['code']) ? (int) $report['code'] : 200;
        $duration = isset($report['duration_ms']) ? max(0, (float) $report['duration_ms']) : 0;
        $sample = SSPA_Crawler::evaluate_sample(array(
            'wall_ms' => $duration,
            'code' => $code,
            'headers' => array('x-sspa-profiled' => $token_id),
            'body' => 'sspa-admin-save',
            'error' => null,
            'error_message' => null,
            'cookies_present' => true,
        ), $token_id, $flags);
        if (empty($sample['capture'])) {
            return new WP_Error('sspa_admin_save_pending', __('The save completed but its diagnostic capture is not ready yet.', 'super-speedy-performance-analysis'));
        }
        if ($sample['wall_ms'] <= 0 && isset($sample['capture']['overview']['gen_ms'])) {
            $sample['wall_ms'] = (float) $sample['capture']['overview']['gen_ms'];
        }

        $profile_id = SSPA_Profile_Store::save($run_id, array(
            'page_key' => $job['page_key'],
            'url' => $job['url'],
            'method' => $job['method'],
            'variant' => 'admin',
            'samples' => array($sample),
            'blocked_by' => null,
            'plugin_set_hash' => '',
            'object_cache_mode' => 'normal',
        ));
        $queue['idx'] = 1;
        $queue['last_progress'] = time();
        SSPA_Run_Queue::save($run_id, $queue);
        SSPA_Run_Controller::complete_run($run_id, 'admin_save');
        return array('profile_id' => $profile_id, 'run_id' => $run_id);
    }

    /* ---------------- server-side replay (profile-admin-save) ---------------- */

    /**
     * Profile an editor save without a browser: the Workflows tab's flow, with the requests the
     * editor's JavaScript would send built and sent here over loopback.
     *
     * 1. Create the marked temporary object (SSPA_Workflow_Analysis::launch()).
     * 2. Load its edit screen with the acting user's login cookies, as the browser does.
     * 3. Build the editor's save request: REST, what apiFetch sends with the screen's nonce;
     *    classic, the editor form serialised as a browser submits it, with its save control.
     * 4. prepare() mints the token for that exact URL, the request is sent once with it, and
     *    finish() stores the profile and completes the run, which removes the object.
     *
     * Nothing is retried: any failure after the object exists cancels the run, removes the
     * object and returns the real error.
     *
     * @return array|WP_Error
     */
    public static function replay($object_type, $transport = '') {
        $object_type = sanitize_key((string) $object_type);
        if ('shop_order' === $object_type) {
            $object_type = 'order';
        }
        if (SSPA_Run_Controller::active_run_id()) {
            return new WP_Error('sspa_run_active', __('An analysis is already running.', 'super-speedy-performance-analysis'));
        }
        if (!SSPA_Helper_Files::health()['mu']) {
            return new WP_Error('sspa_no_mu', __('The profiler is off. Enable it (set-profiler) before profiling a save.', 'super-speedy-performance-analysis'));
        }
        $transports = wp_list_pluck(SSPA_Workflow_Analysis::transports($object_type), 'key');
        $transport = '' === (string) $transport ? (string) reset($transports) : sanitize_key((string) $transport);

        $probe = SSPA_Run_Controller::loopback_preflight();
        if (!$probe['healthy']) {
            return new WP_Error('sspa_loopback_blocked', sprintf(
                /* translators: %s: preflight failure reason */
                __('This site cannot fetch its own pages: %s. A save is replayed over loopback, so nothing was created.', 'super-speedy-performance-analysis'),
                $probe['reason']
            ));
        }

        $launched = SSPA_Workflow_Analysis::launch($object_type, 0, $transport);
        if (is_wp_error($launched)) {
            return $launched;
        }
        $object_id = (int) $launched['object_id'];
        $run_id = 0;
        try {
            $result = self::replay_owned($object_type, $object_id, $transport, $launched['editor_url'], $run_id);
        } catch (Throwable $error) {
            $result = new WP_Error('sspa_admin_save_replay', $error->getMessage());
        }
        if (is_wp_error($result)) {
            if ($run_id) {
                SSPA_Run_Controller::cancel($run_id);
            }
            SSPA_Workflow_Analysis::cleanup();
            return $result;
        }
        $result['temporary_object_removed'] = !self::object_exists($object_type, $object_id);
        return $result;
    }

    private static function object_exists($object_type, $object_id) {
        if ('order' === $object_type) {
            return function_exists('wc_get_order') && (bool) wc_get_order($object_id);
        }
        return (bool) get_post($object_id);
    }

    private static function replay_owned($object_type, $object_id, $transport, $editor_url, &$run_id) {
        // The workflow arguments drive the browser script, which is not running here.
        $editor_url = remove_query_arg(array('sspa_workflow', 'sspa_workflow_transport', 'sspa_workflow_mail', 'sspa_workflow_nonce'), $editor_url);
        $cookies = SSPA_Auth::cookies_for('admin', get_current_user_id());
        if (!$cookies) {
            return new WP_Error('sspa_admin_save_user', __('The acting user has no login that the editor would accept.', 'super-speedy-performance-analysis'));
        }

        $started = microtime(true);
        $editor = SSPA_Crawler::request($editor_url, array('redirection' => 0, 'sslverify' => true, 'cookies' => $cookies));
        $editor_load_ms = round((microtime(true) - $started) * 1000, 1);
        $editor_error = self::http_failure($editor, array(200), __('Loading the editor', 'super-speedy-performance-analysis'));
        if ($editor_error) {
            return $editor_error;
        }
        $html = (string) wp_remote_retrieve_body($editor);

        if ('rest' === $transport) {
            if (!preg_match('/createNonceMiddleware\(\s*"([A-Za-z0-9]+)"\s*\)/', $html, $nonce)) {
                return new WP_Error('sspa_admin_save_nonce', __('The editor screen did not include the REST nonce its save would use.', 'super-speedy-performance-analysis'));
            }
            $rest_url = SSPA_Workflow_Analysis::rest_url_for($object_type, $object_id);
            if (!$rest_url) {
                return new WP_Error('sspa_admin_save_target', __('This object type has no REST route to save through.', 'super-speedy-performance-analysis'));
            }
            // apiFetch({url, method: 'POST', data: {}}): its locale middleware adds _locale=user,
            // its nonce middleware the screen's nonce, and the body is the JSON of {}.
            $url = add_query_arg('_locale', 'user', $rest_url);
            $request = array(
                'body' => '{}',
                'headers' => array('Accept' => 'application/json, */*;q=0.1', 'Content-Type' => 'application/json', 'X-WP-Nonce' => $nonce[1]),
            );
            $expected = array(200);
        } else {
            $form = self::classic_form($html, $editor_url);
            if (is_wp_error($form)) {
                return $form;
            }
            $url = $form['action'];
            $request = array(
                'fields' => $form['fields'],
                'headers' => array('Content-Type' => 'application/x-www-form-urlencoded', 'Referer' => $editor_url),
            );
            $expected = array(302);
        }

        $prepared = self::prepare($url, 'POST', array(
            'screen_id' => self::screen_id($object_type),
            'object_type' => $object_type,
            'object_id' => $object_id,
        ), array('trigger' => 'workflow', 'workflow_transport' => $transport));
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        $run_id = (int) $prepared['run_id'];

        $headers = $request['headers'];
        if ('rest' === $transport) {
            $headers[$prepared['header_name']] = $prepared['token'];
            $body = $request['body'];
        } else {
            $fields = $request['fields'];
            $fields[] = array($prepared['field_name'], $prepared['token']);
            $body = implode('&', array_map(static function ($pair) {
                return urlencode($pair[0]) . '=' . urlencode($pair[1]);
            }, $fields));
        }
        $started = microtime(true);
        $response = SSPA_Crawler::request($url, array(
            'method' => 'POST',
            'redirection' => 0,
            'sslverify' => true,
            'headers' => $headers,
            'body' => $body,
            'cookies' => $cookies,
        ));
        $wall_ms = round((microtime(true) - $started) * 1000, 1);
        $save_error = self::http_failure($response, $expected, __('The save request', 'super-speedy-performance-analysis'));
        if ($save_error) {
            return $save_error;
        }
        $code = (int) wp_remote_retrieve_response_code($response);

        // The capture is written as the save request shuts down; wait as the browser does.
        for ($attempt = 0; ; $attempt++) {
            $finished = self::finish($run_id, $prepared['token_id'], array('code' => $code, 'duration_ms' => $wall_ms));
            if (!is_wp_error($finished) || 'sspa_admin_save_pending' !== $finished->get_error_code() || $attempt >= 12) {
                break;
            }
            usleep(250000);
        }
        if (is_wp_error($finished)) {
            return $finished;
        }
        return array(
            'run_id' => (int) $finished['run_id'],
            'profile_id' => (int) $finished['profile_id'],
            'page_key' => (string) $prepared['page_key'],
            'object_type' => $object_type,
            'transport' => $transport,
            'response_code' => $code,
            'wall_ms' => $wall_ms,
            'editor_load_ms' => $editor_load_ms,
        );
    }

    private static function screen_id($object_type) {
        if ('order' === $object_type && class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            return sanitize_key(\Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_screen());
        }
        return $object_type;
    }

    /** @return WP_Error|null The request's failure, with its status and a body excerpt. */
    private static function http_failure($response, $expected, $label) {
        if (is_wp_error($response)) {
            /* translators: 1: which request, 2: transport error */
            return new WP_Error('sspa_admin_save_http', sprintf(__('%1$s failed before reaching the site: %2$s', 'super-speedy-performance-analysis'), $label, $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $location = (string) wp_remote_retrieve_header($response, 'location');
        if (in_array($code, $expected, true) && false === strpos($location, 'wp-login.php')) {
            return null;
        }
        $excerpt = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) wp_remote_retrieve_body($response))));
        return new WP_Error('sspa_admin_save_http', sprintf(
            /* translators: 1: which request, 2: HTTP status, 3: redirect target or body excerpt */
            __('%1$s answered HTTP %2$d: %3$s', 'super-speedy-performance-analysis'),
            $label,
            $code,
            '' !== $location ? 'redirect to ' . $location : substr($excerpt, 0, 300)
        ), array('status' => $code));
    }

    /**
     * The classic editor form, serialised the way a browser submits it when the save control
     * the Workflows script clicks is clicked: named, enabled controls; checked boxes and radios;
     * selected options; textareas; then the submit control's own name and value.
     *
     * @return array{action:string,fields:array}|WP_Error
     */
    public static function classic_form($html, $page_url) {
        if (!class_exists('DOMDocument')) {
            return new WP_Error('sspa_admin_save_dom', __('PHP\'s DOM extension is needed to read the editor form.', 'super-speedy-performance-analysis'));
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);

        // The same controls, in the same order, as classicControl() in sspa-admin-save.js.
        $submitter = null;
        foreach (array(
            '//*[@id="publish"]',
            '//button[contains(concat(" ", normalize-space(@class), " "), " save_order ")]',
            '//button[@name="save"]',
            '//input[@name="save"]',
        ) as $query) {
            foreach ($xpath->query($query) as $node) {
                if (!$node->hasAttribute('disabled') && self::form_of($node)) {
                    $submitter = $node;
                    break 2;
                }
            }
        }
        if (!$submitter) {
            return new WP_Error('sspa_admin_save_control', __('No supported Update/Save control was found on this editor.', 'super-speedy-performance-analysis'));
        }
        $form = self::form_of($submitter);
        $form_id = $form->getAttribute('id');

        $fields = array();
        foreach ($xpath->query('//input | //select | //textarea | //button') as $control) {
            $owner = $control->hasAttribute('form')
                ? ($control->getAttribute('form') === $form_id && '' !== $form_id)
                : (self::form_of($control) === $form);
            $name = $control->getAttribute('name');
            if (!$owner || '' === $name || $control->hasAttribute('disabled') || self::in_disabled_fieldset($control)) {
                continue;
            }
            $tag = strtolower($control->nodeName);
            if ('textarea' === $tag) {
                $fields[] = array($name, str_replace(array("\r\n", "\r"), "\n", $control->textContent));
            } elseif ('select' === $tag) {
                $options = $xpath->query('.//option', $control);
                $chosen = array();
                foreach ($options as $option) {
                    if ($option->hasAttribute('selected') && !$option->hasAttribute('disabled')) {
                        $chosen[] = $option;
                    }
                }
                if (!$chosen && !$control->hasAttribute('multiple') && $options->length) {
                    $chosen[] = $options->item(0);
                }
                foreach ($chosen as $option) {
                    $fields[] = array($name, $option->hasAttribute('value') ? $option->getAttribute('value') : trim($option->textContent));
                }
            } elseif ('button' === $tag) {
                continue; // only the submitter's own pair is sent, below
            } else {
                $type = strtolower($control->getAttribute('type') ? $control->getAttribute('type') : 'text');
                if (in_array($type, array('submit', 'image', 'button', 'reset', 'file'), true)) {
                    continue;
                }
                if (in_array($type, array('checkbox', 'radio'), true)) {
                    if ($control->hasAttribute('checked')) {
                        $fields[] = array($name, $control->hasAttribute('value') ? $control->getAttribute('value') : 'on');
                    }
                    continue;
                }
                $fields[] = array($name, $control->getAttribute('value'));
            }
        }
        if ('' !== $submitter->getAttribute('name')) {
            $fields[] = array($submitter->getAttribute('name'), $submitter->getAttribute('value'));
        }

        $action = $submitter->hasAttribute('formaction') ? $submitter->getAttribute('formaction') : $form->getAttribute('action');
        return array('action' => self::resolve_url($action, $page_url), 'fields' => $fields);
    }

    private static function form_of($node) {
        for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && 'form' === strtolower($parent->nodeName)) {
                return $parent;
            }
        }
        return null;
    }

    private static function in_disabled_fieldset($node) {
        for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && 'fieldset' === strtolower($parent->nodeName) && $parent->hasAttribute('disabled')) {
                return true;
            }
        }
        return false;
    }

    /** Resolve a form action against the page URL, as a browser does. */
    private static function resolve_url($action, $page_url) {
        $action = trim((string) $action);
        if ('' === $action) {
            return $page_url;
        }
        if (preg_match('#^https?://#i', $action)) {
            return $action;
        }
        $base = wp_parse_url($page_url);
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (0 === strpos($action, '//')) {
            return $base['scheme'] . ':' . $action;
        }
        if ('/' === $action[0]) {
            return $origin . $action;
        }
        if ('?' === $action[0]) {
            return $origin . (isset($base['path']) ? $base['path'] : '/') . $action;
        }
        $dir = isset($base['path']) ? preg_replace('#/[^/]*$#', '/', $base['path']) : '/';
        return $origin . $dir . $action;
    }

    private static function guard() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
    }

    public static function ajax_prepare() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        $prepared = self::prepare(
            isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '',
            isset($_POST['method']) ? sanitize_key(wp_unslash($_POST['method'])) : '',
            array(
                'screen_id' => isset($_POST['screen_id']) ? sanitize_key(wp_unslash($_POST['screen_id'])) : '',
                'object_type' => isset($_POST['object_type']) ? sanitize_key(wp_unslash($_POST['object_type'])) : '',
                'object_id' => isset($_POST['object_id']) ? (int) $_POST['object_id'] : 0,
            ),
            array(
                    'trigger' => isset($_POST['trigger']) ? sanitize_key(wp_unslash($_POST['trigger'])) : 'adminbar',
                'workflow_transport' => isset($_POST['workflow_transport']) ? sanitize_key(wp_unslash($_POST['workflow_transport'])) : '',
            )
        );
        if (is_wp_error($prepared)) {
            wp_send_json_error(array('message' => $prepared->get_error_message(), 'code' => $prepared->get_error_code()));
        }
        wp_send_json_success($prepared);
    }

    public static function ajax_finish() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        $finished = self::finish(
            isset($_POST['run_id']) ? (int) $_POST['run_id'] : 0,
            isset($_POST['token_id']) ? sanitize_text_field(wp_unslash($_POST['token_id'])) : '',
            array(
                'code' => isset($_POST['code']) ? (int) $_POST['code'] : 200,
                'duration_ms' => isset($_POST['duration_ms']) ? (float) $_POST['duration_ms'] : 0,
            )
        );
        if (is_wp_error($finished)) {
            wp_send_json_error(array('message' => $finished->get_error_message(), 'code' => $finished->get_error_code()));
        }
        wp_send_json_success($finished);
    }
}
