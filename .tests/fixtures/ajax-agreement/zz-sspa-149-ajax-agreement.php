<?php
/**
 * Plugin Name: SSPA case 149 admin-ajax agreement fixture
 * Description: Two logged-out admin-ajax actions for case 149. Inert for every other request.
 */
defined('ABSPATH') || exit;
foreach (array('sspa149_first', 'sspa149_second') as $sspa149_action) {
	add_action('wp_ajax_nopriv_' . $sspa149_action, function () use ($sspa149_action) {
		wp_send_json_success(array('handled' => $sspa149_action));
	});
}
