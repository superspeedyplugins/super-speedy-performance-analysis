<?php
// Phase 5 (2026-10-21-quote-request-plan.md): "Request a fix quote" through the real screens,
// the real admin-ajax handlers and PA's real wp_remote_post, against the real Super Speedy
// Contact route on the receiver site. Run by .tests/quote-request/run.sh, which points the
// test-only SSPA_QUOTE_REQUEST_TEST_ENDPOINT constant at the receiver and installs the observer
// fixture that records (and never answers) every request to a quote-request route.
require_once SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
global $wpdb;

$receiver_dir = getenv('SSPA_QUOTE_RECEIVER_DIR');
$receiver_url = getenv('SSPA_QUOTE_RECEIVER_URL');
$endpoint = getenv('SSPA_QUOTE_ENDPOINT');
$out = SSPA_PLUGIN_DIR . '.data/quote-request/';
wp_mkdir_p($out);
if (!$receiver_dir || !$receiver_url || !$endpoint) {
    sspa_fleet_assert(false, 'run.sh provides the receiver site and endpoint');
    sspa_fleet_done();
    return;
}
// Read through class_exists so a run against code without the feature reports each missing
// behaviour instead of stopping at the first reference.
$feature = class_exists('SSPA_Quote_Request');
$consent_version = $feature ? SSPA_Quote_Request::CONSENT_VERSION : '(feature missing)';
$max_markdown = $feature ? SSPA_Quote_Request::MAX_MARKDOWN_BYTES : 204800;
sspa_fleet_assert($feature && defined('SSPA_QUOTE_REQUEST_TEST_ENDPOINT') && SSPA_QUOTE_REQUEST_TEST_ENDPOINT === $endpoint && SSPA_Quote_Request::endpoint() === $endpoint,
    'this regression site posts to the local receiver ' . $endpoint . ', never to superspeedyplugins.com');

/** One wp-cli call on the receiver site with the real wp binary. */
function sspa_quote_rwp($code) {
    $command = array(getenv('SSPA_TEST_REAL_WP') ?: 'wp', 'eval', $code, '--path=' . getenv('SSPA_QUOTE_RECEIVER_DIR'), '--url=' . getenv('SSPA_QUOTE_RECEIVER_URL'));
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (0 !== proc_close($process)) {
        throw new RuntimeException('receiver wp eval failed: ' . $error);
    }
    return $output;
}
/** The receiver's stored leads, each with its full meta. */
function sspa_quote_leads() {
    $json = sspa_quote_rwp('$out = array(); foreach (get_posts(array("post_type" => "ssp_contact_lead", "post_status" => "any", "numberposts" => -1, "orderby" => "ID", "order" => "ASC")) as $p) { $m = array(); foreach (get_post_meta($p->ID) as $k => $v) { $m[$k] = $v[0]; } $out[] = $m; } echo wp_json_encode($out);');
    $leads = json_decode($json, true);
    return is_array($leads) ? $leads : array();
}
function sspa_quote_log() {
    // Written by php-fpm requests; drop this process's cached copy, including "notoptions".
    wp_cache_delete('sspa_test_quote_log', 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete('alloptions', 'options');
    $log = get_option('sspa_test_quote_log', array());
    return is_array($log) ? $log : array();
}
function sspa_quote_last() {
    $log = sspa_quote_log();
    return $log ? $log[count($log) - 1] : array('code' => null);
}

/** Authenticated sessions for real HTTP requests; one session token per user, so nonces hold. */
$sessions = array();
$session = static function ($user_id) use (&$sessions) {
    if (!isset($sessions[$user_id])) {
        $expiry = time() + 7200;
        $token = WP_Session_Tokens::get_instance($user_id)->create($expiry);
        $cookies = array(
            LOGGED_IN_COOKIE => wp_generate_auth_cookie($user_id, $expiry, 'logged_in', $token),
            AUTH_COOKIE => wp_generate_auth_cookie($user_id, $expiry, 'auth', $token),
        );
        $_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
        wp_set_current_user($user_id);
        $sessions[$user_id] = array('cookies' => $cookies, 'nonce' => wp_create_nonce('sspa_admin'));
        unset($_COOKIE[LOGGED_IN_COOKIE]);
        wp_set_current_user(1);
    }
    return $sessions[$user_id];
};
$ajax = static function ($user_id, $action, $body) use ($session) {
    $s = $session($user_id);
    $response = wp_remote_post(admin_url('admin-ajax.php'), array('timeout' => 120, 'cookies' => $s['cookies'], 'body' => array_merge(array('action' => $action, 'nonce' => $s['nonce']), $body)));
    return array(
        'status' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
        'json' => is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true),
        'body' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response),
    );
};
$page_html = static function ($user_id, $path) use ($session) {
    $s = $session($user_id);
    return wp_remote_retrieve_body(wp_remote_get(admin_url($path), array('timeout' => 120, 'cookies' => $s['cookies'])));
};
/** The dialog's Markdown exactly as a browser's textarea would hold it. */
$previewed = static function ($html) {
    if (!preg_match('#<textarea id="sspa-quote-markdown"[^>]*>(.*?)</textarea>#s', (string) $html, $m)) {
        return null;
    }
    return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$preview = static function ($profile_id, $source = 'page-analysis') use ($ajax) {
    return $ajax(1, 'sspa_quote_preview', array('profile_id' => $profile_id, 'source' => $source));
};
$contact = array('name' => 'Quote Regression', 'email' => 'quote-regression@example.com', 'site_url' => home_url('/'), 'message' => 'Please fix the slow home page.', 'ongoing_care' => '1', 'marketing_opt_in' => '0');
$send = static function ($uuid, $fields) use ($ajax) {
    return $ajax(1, 'sspa_quote_send', array_merge(array('request_uuid' => $uuid), $fields));
};

// 1. A real analysis of the home page.
$run = SSPA_Run_Controller::start(array('type' => 'spot', 'trigger' => 'manual', 'user_id' => 1, 'page_keys' => array('home')));
if (is_wp_error($run)) {
    sspa_fleet_assert(false, 'spot analysis of home started: ' . $run->get_error_message());
    sspa_fleet_done();
    return;
}
sspa_fleet_complete($run);
$profile_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE run_id = %d AND page_key = %s ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), $run, 'home'));
sspa_fleet_assert($profile_id > 0, 'the analysis stored a home page profile (#' . $profile_id . ')');

// 2. Only on PA screens, only for administrators.
$panel_pa = $ajax(1, 'sspa_profile_panel', array('profile_id' => $profile_id, 'pa_screen' => 1));
$panel_front = $ajax(1, 'sspa_profile_panel', array('profile_id' => $profile_id, 'pa_screen' => 0));
$panel_html = isset($panel_pa['json']['data']['html']) ? $panel_pa['json']['data']['html'] : '';
sspa_fleet_assert(false !== strpos($panel_html, 'sspa-quote-open" data-profile-id="' . $profile_id . '" data-source="page-analysis"')
    && false !== strpos($panel_html, 'data-source="summary"'),
    'on a PA screen the page analysis offers Request a fix quote beside Copy Markdown and in the summary\'s next action');
sspa_fleet_assert(isset($panel_front['json']['data']['html']) && false === strpos($panel_front['json']['data']['html'], 'sspa-quote-open'),
    'the same panel opened outside PA\'s screens (the admin-bar analysis) has no quote button');
$pa_screen = $page_html(1, 'admin.php?page=sspa');
$dashboard = $page_html(1, 'index.php');
sspa_fleet_assert(false !== strpos($pa_screen, 'js/sspa-quote-request.js') && false === strpos($dashboard, 'sspa-quote-request.js'),
    'the dialog script loads on the PA screen and not on the Dashboard');
$cache_tab = $ajax(1, 'sspa_render_tab', array('tabs' => 'cache'));
sspa_fleet_assert(false !== strpos(wp_json_encode($cache_tab['json']), 'data-source=\"cache-tab\"'),
    'the Cache performance tab offers Request a fix quote beside the caching service');
file_put_contents($out . 'panel-pa-screen.html', $panel_html);

$editor = get_user_by('login', 'quote-regression-editor');
$editor_id = $editor ? (int) $editor->ID : (int) wp_insert_user(array('user_login' => 'quote-regression-editor', 'user_pass' => wp_generate_password(24), 'user_email' => 'quote-editor@example.invalid', 'role' => 'editor'));
$denied_preview = $ajax($editor_id, 'sspa_quote_preview', array('profile_id' => $profile_id, 'source' => 'page-analysis'));
$denied_send = $ajax($editor_id, 'sspa_quote_send', array('request_uuid' => wp_generate_uuid4(), 'consent' => '1'));
sspa_fleet_assert(403 === $denied_preview['status'] && 403 === $denied_send['status'] && !sspa_quote_log(),
    'an editor is refused both the preview and the send (403), and nothing is requested');

// 3. The preview: the full page Markdown, every box unticked, nothing sent.
$first = $preview($profile_id);
$dialog = isset($first['json']['data']['html']) ? $first['json']['data']['html'] : '';
$uuid = isset($first['json']['data']['request_uuid']) ? $first['json']['data']['request_uuid'] : '';
$markdown = $previewed($dialog);
file_put_contents($out . 'dialog.html', $dialog);
file_put_contents($out . 'previewed.md', (string) $markdown);
sspa_fleet_assert(!empty($first['json']['success']) && wp_is_uuid($uuid) && is_string($markdown) && 0 === strpos($markdown, '<!-- sspa/llm-report@') && false !== strpos($markdown, '# Page performance analysis'),
    'the preview shows the full page Markdown report (' . strlen((string) $markdown) . ' bytes)');
sspa_fleet_assert(false === strpos($dialog, '"analysis_json"') && false === strpos($dialog, 'Export JSON') && false === strpos((string) $markdown, '"profile_blob"'),
    'the preview is the Markdown report, never the JSON export');
preg_match_all('#<input type="checkbox"[^>]*>#', $dialog, $boxes);
sspa_fleet_assert(3 === count($boxes[0]) && !preg_match('/\bchecked\b/', implode('', $boxes[0])),
    'ongoing care, the marketing opt-in and consent are three separate unticked boxes');
sspa_fleet_assert(false !== strpos($dialog, 'Email me performance tips and offers')
    && false !== strpos($dialog, 'WP Intense OÜ') && false !== strpos($dialog, 'superspeedyplugins.com')
    && false !== strpos($dialog, 'never joined to anything shared with superspeedy.org')
    && false !== strpos($dialog, 'href="https://www.superspeedyplugins.com/privacy-policy/#performance-review-requests"'),
    'the consent text names what is sent, to whom and why, keeps it apart from superspeedy.org, and links the published privacy section');
sspa_fleet_assert(false !== strpos($dialog, 'value="' . esc_attr(get_userdata(1)->user_email) . '"') && false !== strpos($dialog, 'value="' . esc_attr(home_url('/')) . '"'),
    'name, email and site address are prefilled and editable');
sspa_fleet_assert(!sspa_quote_log() && !sspa_quote_leads(), 'opening the preview sends nothing');

// 4. Send without the consent tick: refused by PA, nothing leaves the site.
$no_consent = $send($uuid, $contact);
$zero_consent = $send($uuid, array_merge($contact, array('consent' => '0')));
sspa_fleet_assert(empty($no_consent['json']['success']) && 'consent_required' === $no_consent['json']['data']['code']
    && empty($zero_consent['json']['success']) && 'consent_required' === $zero_consent['json']['data']['code'],
    'Send without the consent tick is refused: ' . (isset($no_consent['json']['data']['message']) ? $no_consent['json']['data']['message'] : ''));
sspa_fleet_assert(!sspa_quote_log() && !sspa_quote_leads(), 'without consent no request is made and the receiver stores nothing');

// 5. With consent: the real route stores exactly what was previewed.
$sent = $send($uuid, array_merge($contact, array('consent' => '1')));
$reference = isset($sent['json']['data']['reference']) ? $sent['json']['data']['reference'] : '';
$log = sspa_quote_log();
$leads = sspa_quote_leads();
$lead = isset($leads[0]) ? $leads[0] : array();
file_put_contents($out . 'received.md', isset($lead['_sspc_markdown']) ? $lead['_sspc_markdown'] : '');
sspa_fleet_assert(!empty($sent['json']['success']) && 'received' === $sent['json']['data']['status'] && preg_match('/^SSPQ-[A-Z0-9]+$/', $reference)
    && false !== strpos($sent['json']['data']['message'], $reference),
    'with consent the receiver answers 201 and PA shows the reference ' . $reference);
sspa_fleet_assert(1 === count($log) && 'POST' === $log[0]['method'] && $endpoint === $log[0]['url'] && 201 === $log[0]['code']
    && 0 === strpos($log[0]['content_type'], 'application/json'),
    'exactly one server-side JSON POST went to the receiver');
sspa_fleet_assert(isset($log[0]['user_agent']) && false === strpos($log[0]['user_agent'], wp_parse_url(home_url(), PHP_URL_HOST)) && 0 === strpos($log[0]['user_agent'], 'Super Speedy Performance Analysis/'),
    'the request\'s User-Agent does not carry the site address (' . (isset($log[0]['user_agent']) ? $log[0]['user_agent'] : '') . ')');
sspa_fleet_assert(1 === count($leads) && isset($lead['_sspc_markdown']) && $lead['_sspc_markdown'] === $markdown,
    'the Markdown the receiver stored equals the previewed Markdown byte for byte (' . strlen((string) $markdown) . ' bytes)');
sspa_fleet_assert(isset($lead['_sspc_markdown_sha256']) && hash('sha256', (string) $markdown) === $lead['_sspc_markdown_sha256'] && $log[0]['markdown_sha256'] === $lead['_sspc_markdown_sha256'],
    'the request body, the receiver\'s record and the preview share one SHA-256');
sspa_fleet_assert(isset($lead['_sspc_reference']) && $reference === $lead['_sspc_reference'] && 'page-analysis' === $lead['_sspc_source']
    && 'Quote Regression' === $lead['_sspc_name'] && 'quote-regression@example.com' === $lead['_sspc_email'] && home_url('/') === $lead['_sspc_site_url']
    && 'Please fix the slow home page.' === $lead['_sspc_message'] && '1' === $lead['_sspc_ongoing_care'] && '0' === $lead['_sspc_marketing_opt_in']
    && $consent_version === $lead['_sspc_consent_version'] && SSPA_VERSION === $lead['_sspc_pa_version'] && $uuid === $lead['_sspc_request_uuid']
    && 'not-requested' === $lead['_sspc_sse'],
    'the receiver recorded the source, contact fields, ongoing care, no marketing opt-in, consent version, PA version and request ID');
sspa_fleet_assert(array('name', 'email', 'site_url', 'markdown', 'source', 'ongoing_care', 'marketing_opt_in', 'consent', 'consent_version', 'pa_version', 'message', 'request_uuid') === $log[0]['keys'],
    'the body carries exactly the contract\'s fields, and no JSON export');
$mail = json_decode(sspa_quote_rwp('echo wp_json_encode(get_option("sspa_test_receiver_mail", array()));'), true);
sspa_fleet_assert(is_array($mail) && 1 === count($mail) && false !== strpos($mail[0]['subject'], $reference),
    'the receiver\'s own notification path ran once for the new lead');

// 6. A retry with the same request ID is answered with the same reference and stored once.
$retry = $send($uuid, array_merge($contact, array('consent' => '1')));
sspa_fleet_assert(!empty($retry['json']['success']) && 'duplicate' === $retry['json']['data']['status'] && $reference === $retry['json']['data']['reference']
    && 1 === count(sspa_quote_leads()) && 200 === sspa_quote_log()[1]['code'],
    'Send again with the same request ID: the receiver answers 200 duplicate with ' . $reference . ' and stores nothing new');

// 7. 400: the receiver's own validation, explained in PA.
$bad = $preview($profile_id);
$bad_send = $send($bad['json']['data']['request_uuid'], array_merge($contact, array('email' => 'not-an-email', 'consent' => '1')));
sspa_fleet_assert(empty($bad_send['json']['success']) && 'invalid_field' === $bad_send['json']['data']['code'] && false !== strpos($bad_send['json']['data']['message'], 'your email address')
    && 400 === sspa_quote_last()['code'] && 1 === count(sspa_quote_leads()),
    'an invalid email is refused by the receiver (400) and PA says which field: ' . $bad_send['json']['data']['message']);

// 8. 503: the receiver cannot store the lead.
sspa_quote_rwp('update_option("sspa_test_receiver_fail_storage", 1, false);');
$down = $preview($profile_id);
// Each step uses its own address: the receiver allows three new leads per address a day.
$down_contact = array_merge($contact, array('email' => 'quote-503@example.com', 'consent' => '1'));
$down_send = $send($down['json']['data']['request_uuid'], $down_contact);
sspa_quote_rwp('delete_option("sspa_test_receiver_fail_storage");');
sspa_fleet_assert(empty($down_send['json']['success']) && 'storage_failed' === $down_send['json']['data']['code'] && 503 === sspa_quote_last()['code']
    && false !== strpos($down_send['json']['data']['message'], 'try again') && 1 === count(sspa_quote_leads()),
    'a receiver storage failure (503) tells the user to try again: ' . $down_send['json']['data']['message']);
// Pressing Send again on that same dialog, once the receiver recovers, stores it.
$recovered = $send($down['json']['data']['request_uuid'], $down_contact);
sspa_fleet_assert(!empty($recovered['json']['success']) && 'received' === $recovered['json']['data']['status'] && 2 === count(sspa_quote_leads()),
    'Send again after the 503 stores the request once the receiver recovers');

// 9. 413: a real page whose report is larger than the receiver accepts.
$bulk = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => add_query_arg('quote_raw_fixture', 'bulk', home_url('/')), 'user_id' => 1, 'raw_streams' => true));
$big_profile = 0;
if (!is_wp_error($bulk)) {
    sspa_fleet_complete($bulk);
    $big_profile = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), $bulk));
}
$big = $preview($big_profile);
$big_markdown = $previewed(isset($big['json']['data']['html']) ? $big['json']['data']['html'] : '');
sspa_fleet_assert(is_string($big_markdown) && strlen($big_markdown) > $max_markdown && false !== strpos($big['json']['data']['html'], 'larger than superspeedyplugins.com accepts'),
    'a page that read 1,700 raw streams produces a ' . strlen((string) $big_markdown) . '-byte report, and the preview warns it is over the limit');
$big_send = $send($big['json']['data']['request_uuid'], array_merge($contact, array('email' => 'quote-413@example.com', 'consent' => '1')));
sspa_fleet_assert(empty($big_send['json']['success']) && in_array($big_send['json']['data']['code'], array('markdown_too_large', 'body_too_large'), true)
    && 413 === sspa_quote_last()['code'] && false !== strpos($big_send['json']['data']['message'], '200 KB') && 2 === count(sspa_quote_leads()),
    'the receiver refuses it (413 ' . $big_send['json']['data']['code'] . ') and PA explains the size limit');

// 10. 429: the receiver's per-email limit, with its wait.
sspa_quote_rwp('update_option("sspa_test_receiver_limits", array("route_email" => array("limit" => 1, "window" => 3600)), false);');
$limited_contact = array_merge($contact, array('email' => 'quote-limit@example.com', 'consent' => '1'));
$a = $preview($profile_id);
$a_send = $send($a['json']['data']['request_uuid'], $limited_contact);
$b = $preview($profile_id);
$b_send = $send($b['json']['data']['request_uuid'], $limited_contact);
sspa_quote_rwp('delete_option("sspa_test_receiver_limits");');
sspa_fleet_assert(!empty($a_send['json']['success']) && empty($b_send['json']['success']) && 'rate_limited' === $b_send['json']['data']['code']
    && 429 === sspa_quote_last()['code'] && (bool) preg_match('/Try again in (59|60) minutes\./', $b_send['json']['data']['message']),
    'a second request inside the receiver\'s limit is refused (429) and PA shows the wait: ' . $b_send['json']['data']['message']);

// 11. A transport failure: the receiver cannot be reached.
sspa_fleet_cli(array('config', 'set', 'SSPA_QUOTE_REQUEST_TEST_ENDPOINT', 'http://127.0.0.1:9/wp-json/ssp-contact/v1/quote-request', '--type=constant'));
sleep(3); // php-fpm opcache revalidation of wp-config.php
$c = $preview($profile_id);
$c_send = $send($c['json']['data']['request_uuid'], array_merge($contact, array('email' => 'quote-transport@example.com', 'consent' => '1')));
sspa_fleet_cli(array('config', 'set', 'SSPA_QUOTE_REQUEST_TEST_ENDPOINT', $endpoint, '--type=constant'));
sleep(3);
sspa_fleet_assert(empty($c_send['json']['success']) && 'transport' === $c_send['json']['data']['code'] && false !== strpos($c_send['json']['data']['message'], 'could not reach superspeedyplugins.com'),
    'an unreachable receiver is reported as a transport error with a retry: ' . $c_send['json']['data']['message']);

// 12. An expired or foreign request ID sends nothing.
$before = count(sspa_quote_log());
$unknown = $send(wp_generate_uuid4(), array_merge($contact, array('email' => 'quote-unknown@example.com', 'consent' => '1')));
sspa_fleet_assert(empty($unknown['json']['success']) && 'expired' === $unknown['json']['data']['code'] && count(sspa_quote_log()) === $before,
    'a request ID that was never previewed sends nothing');

file_put_contents($out . 'quote-log.json', wp_json_encode(sspa_quote_log(), JSON_PRETTY_PRINT));
sspa_fleet_done();
