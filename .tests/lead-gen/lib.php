<?php
// Shared helpers for the lead-generation offer assertions. Everything goes through the
// authenticated admin-ajax endpoints the PA screens use, so the assertions read exactly the
// HTML and Markdown an administrator receives.
require_once __DIR__ . '/../lib/native-guard.php';

function lg_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    return (bool) $ok;
}

function lg_cookies() {
    static $cookies = null;
    if (null === $cookies) {
        wp_set_current_user(1);
        $cookies = SSPA_Auth::cookies_for('admin', 1);
        $_COOKIE[LOGGED_IN_COOKIE] = $cookies[LOGGED_IN_COOKIE];
    }
    return $cookies;
}

function lg_ajax($action, $body = array()) {
    $cookies = lg_cookies();
    $response = wp_remote_post(admin_url('admin-ajax.php'), array(
        'cookies' => $cookies,
        'timeout' => 120,
        'body' => array_merge(array('action' => $action, 'nonce' => wp_create_nonce('sspa_admin')), $body),
    ));
    if (is_wp_error($response)) {
        return array('success' => false, 'data' => $response->get_error_message());
    }
    $document = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($document) ? $document : array('success' => false, 'data' => wp_remote_retrieve_body($response));
}

function lg_tab($tab) {
    $document = lg_ajax('sspa_render_tab', array('tabs' => $tab));
    return isset($document['data']['tabs'][$tab]) ? (string) $document['data']['tabs'][$tab] : '';
}

function lg_panel($profile_id, $pa_screen = 1) {
    $document = lg_ajax('sspa_profile_panel', array('profile_id' => (int) $profile_id, 'pa_screen' => $pa_screen));
    return !empty($document['success']) ? (string) $document['data']['html'] : '';
}

function lg_markdown($kind, $id) {
    $document = lg_ajax('sspa_markdown_export', array('kind' => $kind, 'id' => (int) $id));
    return !empty($document['success']) ? (string) $document['data']['markdown'] : '';
}

/** Every rendered offer block: [offer, trigger, role, html]. */
function lg_offers($html) {
    preg_match_all('#<div class="sspa-offer sspa-offer-[a-z]+"([^>]*)>(.*?)<!-- /sspa-offer -->#s', $html, $blocks, PREG_SET_ORDER);
    $out = array();
    foreach ($blocks as $block) {
        preg_match('#data-offer="([^"]+)"#', $block[1], $offer);
        preg_match('#data-trigger="([^"]+)"#', $block[1], $trigger);
        preg_match('#data-role="([^"]+)"#', $block[1], $role);
        $out[] = array('offer' => $offer[1] ?? '', 'trigger' => $trigger[1] ?? '', 'role' => $role[1] ?? '', 'html' => $block[0]);
    }
    return $out;
}

function lg_offer_ids($html) {
    return array_column(lg_offers($html), 'offer');
}

/** The newest profile of a page in a run. */
function lg_profile_id($run_id, $page_key) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE run_id = %d AND page_key = %s ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), $run_id, $page_key));
}

/**
 * Every superspeedyplugins.com link carries only the static placement tag: no host name,
 * user, site or run data. Returns the offending links.
 */
function lg_identifying_links($text) {
    preg_match_all('#https?://[^\s"\')<>]*superspeedyplugins\.com[^\s"\')<>]*#', html_entity_decode($text), $links);
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $bad = array();
    foreach (array_unique($links[0]) as $link) {
        parse_str((string) wp_parse_url($link, PHP_URL_QUERY), $query);
        $ok = !array_diff(array_keys($query), array('utm_source', 'utm_medium', 'utm_campaign'))
            && (!$query || ('performance-analysis' === ($query['utm_source'] ?? '') && 'plugin' === ($query['utm_medium'] ?? '') && preg_match('/^[a-z_]+$/', $query['utm_campaign'] ?? '')))
            && false === stripos($link, $host)
            && false === stripos($link, 'user') && false === stripos($link, 'run_id');
        if (!$ok) {
            $bad[] = $link;
        }
    }
    return $bad;
}

function lg_campaign($html, $offer) {
    foreach (lg_offers($html) as $block) {
        if ($block['offer'] === $offer && preg_match('#utm_campaign=([a-z_]+)#', $block['html'], $m)) {
            return $m[1];
        }
    }
    return '';
}

function lg_reset_dismissals() {
    delete_user_meta(1, 'sspa_dismissed_offers');
}
