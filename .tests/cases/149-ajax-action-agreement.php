<?php
// An observed admin-ajax action must be unambiguous. Real HTTP requests to admin-ajax.php in a
// real AJAX window: GET and POST `action` that disagree create no observation (even though both
// name registered actions and WordPress runs the POST one); agreeing GET+POST, POST-only and
// GET-only requests are observed under their action; an array action is never observed.
// Plan: .docs/2026-10-29-ajax-action-get-post-agreement.md
// The fixture MU plugin stays installed as evidence; it is inert for every other request.

function sspa_149_t($ok, $label) {
	echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

copy(SSPA_PLUGIN_DIR . '.tests/fixtures/ajax-agreement/zz-sspa-149-ajax-agreement.php', WPMU_PLUGIN_DIR . '/zz-sspa-149-ajax-agreement.php');
$sspa_149_active = SSPA_Traffic_Collection::active();
if ($sspa_149_active) {
	SSPA_Traffic_Collection::stop((int) $sspa_149_active['id'], true);
}
$sspa_149_window = SSPA_Ajax_Profile::start(array('scenario' => 'Action agreement', 'label' => 'Case 149 window'));
sspa_149_t(!is_wp_error($sspa_149_window), 'an AJAX window starts' . (is_wp_error($sspa_149_window) ? ': ' . $sspa_149_window->get_error_message() : ''));
if (is_wp_error($sspa_149_window)) {
	return;
}
sleep(3); // php-fpm revalidates changed PHP files at most every 2s.

$sspa_149_ajax = admin_url('admin-ajax.php');
$sspa_149_requests = array(
	'conflicting GET and POST' => array(add_query_arg('action', 'sspa149_second', $sspa_149_ajax), array('action' => 'sspa149_first'), 'sspa149_first'),
	'agreeing GET and POST'    => array(add_query_arg('action', 'sspa149_first', $sspa_149_ajax), array('action' => 'sspa149_first'), 'sspa149_first'),
	'POST only'                => array($sspa_149_ajax, array('action' => 'sspa149_first'), 'sspa149_first'),
	'GET only'                 => array(add_query_arg('action', 'sspa149_second', $sspa_149_ajax), null, 'sspa149_second'),
	'array POST action'        => array($sspa_149_ajax, array('action' => array('sspa149_first')), null),
);
foreach ($sspa_149_requests as $sspa_149_label => $sspa_149_request) {
	list($sspa_149_url, $sspa_149_body, $sspa_149_handled) = $sspa_149_request;
	$sspa_149_r = null === $sspa_149_body
		? wp_remote_get($sspa_149_url, array('timeout' => 30))
		: wp_remote_post($sspa_149_url, array('body' => $sspa_149_body, 'timeout' => 30));
	$sspa_149_json = is_wp_error($sspa_149_r) ? null : json_decode(wp_remote_retrieve_body($sspa_149_r), true);
	if ($sspa_149_handled) {
		sspa_149_t(isset($sspa_149_json['data']['handled']) && $sspa_149_handled === $sspa_149_json['data']['handled'], "$sspa_149_label: WordPress runs $sspa_149_handled");
	} else {
		sspa_149_t(!is_wp_error($sspa_149_r) && 400 === wp_remote_retrieve_response_code($sspa_149_r), "$sspa_149_label: WordPress refuses it with 400");
	}
}
SSPA_Ajax_Profile::stop($sspa_149_window['uuid']);

$sspa_149_counts = array();
foreach (SSPA_Ajax_Profile::rows($sspa_149_window) as $sspa_149_row) {
	$sspa_149_key = $sspa_149_row['transport'] . ':' . $sspa_149_row['endpoint'] . ':' . $sspa_149_row['http_method'];
	$sspa_149_counts[$sspa_149_key] = ($sspa_149_counts[$sspa_149_key] ?? 0) + 1;
}
ksort($sspa_149_counts);
echo 'observed: ' . wp_json_encode($sspa_149_counts) . "\n";
sspa_149_t(2 === ($sspa_149_counts['admin_ajax:sspa149_first:POST'] ?? 0), 'agreeing GET+POST and POST-only are observed as sspa149_first; the conflicting request is not (expected 2 POST observations)');
sspa_149_t(1 === ($sspa_149_counts['admin_ajax:sspa149_second:GET'] ?? 0), 'GET-only request is observed as sspa149_second');
sspa_149_t(!isset($sspa_149_counts['admin_ajax:sspa149_second:POST']), 'the conflicting GET value is never recorded either');
sspa_149_t(3 === array_sum($sspa_149_counts), 'exactly three observations in the window');
