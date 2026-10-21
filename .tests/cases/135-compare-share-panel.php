<?php
// Phase 4 (2026-10-21-compare-and-share-panel-plan.md): History renamed Compare, the share panel on
// PA's own screens, and community figures fetched only when the user asks for them.
//
// Everything goes through authenticated HTTP into the real admin pages and admin-ajax handlers.
// superspeedy.org is observed by the compare-http-observer MU fixture: it logs every request and,
// in 'fixture' mode, answers the two public documents with the bytes the superspeedy.org build
// emitted (.tests/fixtures/superspeedy-org/provenance.json). In 'live' mode it fetches them from the
// real superspeedy.org, so the plugin meets whatever is really served today.
require_once __DIR__ . '/../lib/native-guard.php';

function sspa_135_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

global $wpdb;
$sspa_135_observer = WPMU_PLUGIN_DIR . '/sspa-compare-http-observer.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
if (!file_exists($sspa_135_observer) || md5_file($sspa_135_observer) !== md5_file(__DIR__ . '/../fixtures/compare-http-observer.php')) {
    copy(__DIR__ . '/../fixtures/compare-http-observer.php', $sspa_135_observer);
    sleep(3); // php-fpm opcache revalidation
}

// Clear on the way in: sharing off, no prompt choices, empty request log.
update_option('sspa_share_optin', 0, false);
delete_user_meta(1, 'sspa_share_prompt_snoozed_until');
delete_user_meta(1, 'sspa_share_prompt_never');
delete_option('sspa_test_ssorg_log');
delete_option('sspa_test_ssorg_mode');

// A completed spot check of the page classes the fixture documents describe: five key pages and
// three that Compare keeps behind "Show all".
$sspa_135_run = SSPA_Run_Controller::start(array('type' => 'spot', 'trigger' => 'manual', 'user_id' => 1, 'page_keys' => array('admin-dashboard', 'home', 'post-cat', 'post-single', 'product-cat', 'product-single', 'shop', 'wc-checkout')));
if (is_wp_error($sspa_135_run)) {
    sspa_135_t(false, 'spot analysis started: ' . $sspa_135_run->get_error_message());
    return;
}
$sspa_135_deadline = time() + 900;
do {
    SSPA_Run_Controller::process_batch($sspa_135_run);
    $sspa_135_status = SSPA_Run_Controller::status($sspa_135_run);
} while ($sspa_135_status && 'crawling' === $sspa_135_status['status'] && time() < $sspa_135_deadline);
sspa_135_t($sspa_135_status && 'done' === $sspa_135_status['status'], 'spot analysis ' . $sspa_135_run . ' completed');

wp_set_current_user(1);
// wp eval-file runs this file in function scope: the helpers read these through $GLOBALS.
$GLOBALS['sspa_135_cookies'] = SSPA_Auth::cookies_for('admin', 1);
$_COOKIE[LOGGED_IN_COOKIE] = $GLOBALS['sspa_135_cookies'][LOGGED_IN_COOKIE];
$GLOBALS['sspa_135_nonce'] = wp_create_nonce('sspa_admin');

function sspa_135_get($url) {
    $response = wp_remote_get($url, array('cookies' => $GLOBALS['sspa_135_cookies'], 'timeout' => 60, 'redirection' => 0));
    return is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
}
function sspa_135_ajax($body, $timeout = 60) {
    $response = wp_remote_post(admin_url('admin-ajax.php'), array(
        'cookies' => $GLOBALS['sspa_135_cookies'], 'timeout' => $timeout,
        'body' => array_merge(array('nonce' => $GLOBALS['sspa_135_nonce']), $body),
    ));
    return is_wp_error($response) ? array('wp_error' => $response->get_error_message()) : json_decode((string) wp_remote_retrieve_body($response), true);
}
function sspa_135_tabs($tabs) {
    $document = sspa_135_ajax(array('action' => 'sspa_render_tab', 'tabs' => implode(',', $tabs)));
    return isset($document['data']['tabs']) && is_array($document['data']['tabs']) ? $document['data']['tabs'] : array();
}
function sspa_135_prompted($html) {
    return false !== strpos($html, 'sspa-share-prompt"') || false !== strpos($html, 'class="sspa-share-prompt ');
}
function sspa_135_never_box($html) {
    if (!preg_match('/<input[^>]*id="sspa-share-prompt-never"[^>]*>/', $html, $match)) return 'missing';
    return preg_match('/\schecked\b/', $match[0]) ? 'ticked' : 'unticked';
}
// Written by the PHP-FPM requests: drop this process's cached copy before reading it.
function sspa_135_ssorg_log() {
    wp_cache_delete('sspa_test_ssorg_log', 'options');
    wp_cache_delete('notoptions', 'options');
    return (array) get_option('sspa_test_ssorg_log', array());
}
function sspa_135_ssorg_api_requests() {
    $requests = array();
    foreach (sspa_135_ssorg_log() as $entry) {
        if (false !== strpos((string) $entry['url'], '/api/v1/')) $requests[] = $entry;
    }
    return $requests;
}

// ---- 1. History is now Compare --------------------------------------------------------------
$sspa_135_page = sspa_135_get(admin_url('admin.php?page=sspa'));
sspa_135_t(false !== strpos($sspa_135_page, 'data-tab="compare">Compare</a>'), 'the PA tab bar has a Compare tab');
sspa_135_t(false === strpos($sspa_135_page, 'data-tab="history"'), 'the PA tab bar has no History tab any more');
sspa_135_t(file_exists(SSPA_PLUGIN_DIR . 'includes/admin/tabs/compare.php') && !file_exists(SSPA_PLUGIN_DIR . 'includes/admin/tabs/history.php'), 'the tab template is compare.php');
sspa_135_t('#compare' === substr(SSPA_History_Run_View::url(7), -8), 'saved-report links open the Compare tab');
$sspa_135_compare_tab = sspa_135_tabs(array('compare'));
$sspa_135_compare = isset($sspa_135_compare_tab['compare']) ? $sspa_135_compare_tab['compare'] : '';
sspa_135_t(false !== strpos($sspa_135_compare, 'sspa-history-compare-picker'), 'Compare keeps the own-run comparison');
$sspa_135_share_tab = sspa_135_tabs(array('share'));
$sspa_135_share = isset($sspa_135_share_tab['share']) ? $sspa_135_share_tab['share'] : '';
sspa_135_t(false !== strpos($sspa_135_share, 'href="#compare" data-tab="compare"'), 'the Share tab links to Compare');

// ---- 2. The share panel -----------------------------------------------------------------------
$sspa_135_rendered = sspa_135_tabs(array('overview', 'compare', 'plugins', 'cache', 'share'));
sspa_135_t(sspa_135_prompted($sspa_135_page), 'sharing off: the PA page shows the share panel after a completed analysis');
sspa_135_t(isset($sspa_135_rendered['overview']) && sspa_135_prompted($sspa_135_rendered['overview']), 'sharing off: the Overview result view carries the share panel');
sspa_135_t(isset($sspa_135_rendered['compare']) && sspa_135_prompted($sspa_135_rendered['compare']), 'sharing off: Compare carries the share panel');
sspa_135_t(isset($sspa_135_rendered['share']) && !sspa_135_prompted($sspa_135_rendered['share']), 'the Share tab itself has no panel, only its own controls');
// The panel itself, not the rest of the tab around it.
$sspa_135_panel = isset($sspa_135_rendered['compare']) && preg_match('/<section class="sspa-share-prompt".*?<\/section>/s', $sspa_135_rendered['compare'], $sspa_135_match) ? $sspa_135_match[0] : '';
sspa_135_t(2 === substr_count($sspa_135_panel, '<button'), 'the panel has exactly two buttons');
sspa_135_t(false !== strpos($sspa_135_panel, 'Share anonymised results automatically') && false !== strpos($sspa_135_panel, 'Don&#039;t share for 24 hours'), 'the panel offers exactly the two buttons');
sspa_135_t(false !== strpos($sspa_135_panel, 'operated by WP Intense OÜ, which also runs superspeedyplugins.com') && false !== strpos($sspa_135_panel, 'https://superspeedy.org/privacy-and-terms/'), 'the panel names the operator and links the privacy page');
sspa_135_t(false === stripos($sspa_135_panel, 'must share') && false === stripos($sspa_135_panel, 'unlock') && false === stripos($sspa_135_panel, 'checked'), 'the panel never says sharing is required and has nothing pre-ticked');
sspa_135_t(isset($sspa_135_rendered['share']) && 'unticked' === sspa_135_never_box($sspa_135_rendered['share']), 'the Share tab has an unticked Don\'t ask me again');

// Snooze: the handler stores a time 24 hours ahead; moving that stored time is what brings it back.
$sspa_135_before = time();
$sspa_135_snooze = sspa_135_ajax(array('action' => 'sspa_share_prompt_snooze'));
$sspa_135_until = (int) get_user_meta(1, 'sspa_share_prompt_snoozed_until', true);
sspa_135_t(!empty($sspa_135_snooze['success']) && $sspa_135_until >= $sspa_135_before + DAY_IN_SECONDS && $sspa_135_until <= time() + DAY_IN_SECONDS + 5, 'Don\'t share for 24 hours stores a time 24 hours ahead (' . ($sspa_135_until - $sspa_135_before) . ' s)');
$sspa_135_rendered = sspa_135_tabs(array('overview', 'compare'));
sspa_135_t(!sspa_135_prompted(implode('', $sspa_135_rendered)) && !sspa_135_prompted(sspa_135_get(admin_url('admin.php?page=sspa'))), 'while snoozed the panel is gone from every PA screen');
update_user_meta(1, 'sspa_share_prompt_snoozed_until', $sspa_135_until - DAY_IN_SECONDS - 1);
$sspa_135_rendered = sspa_135_tabs(array('compare'));
sspa_135_t(isset($sspa_135_rendered['compare']) && sspa_135_prompted($sspa_135_rendered['compare']), 'once the stored snooze time has passed, the panel returns');
wp_cache_delete('sspa_share_optin', 'options');
sspa_135_t('1' !== (string) get_option('sspa_share_optin') && array() === sspa_135_ssorg_log(), 'snoozing shares nothing and contacts no server');

// Don't ask me again, from the Share tab, and undo.
$sspa_135_never = sspa_135_ajax(array('action' => 'sspa_share_prompt_never', 'never' => 1));
$sspa_135_rendered = sspa_135_tabs(array('overview', 'compare', 'share'));
sspa_135_t(!empty($sspa_135_never['success']) && !sspa_135_prompted($sspa_135_rendered['overview'] . $sspa_135_rendered['compare']), 'Don\'t ask me again stops the panel');
sspa_135_t('ticked' === sspa_135_never_box($sspa_135_rendered['share']), 'the Share tab shows the choice so it can be undone');
sspa_135_ajax(array('action' => 'sspa_share_prompt_never', 'never' => 0));
$sspa_135_rendered = sspa_135_tabs(array('compare'));
sspa_135_t(sspa_135_prompted($sspa_135_rendered['compare']), 'unticking it on the Share tab brings the panel back');

// Never anywhere but PA's own screens.
foreach (array('index.php', 'edit.php', 'plugins.php', 'options-general.php', 'edit.php?post_type=product', 'admin.php?page=wc-settings') as $sspa_135_screen) {
    $sspa_135_html = sspa_135_get(admin_url($sspa_135_screen));
    sspa_135_t('' !== $sspa_135_html && false !== strpos($sspa_135_html, 'id="wpadminbar"') && !sspa_135_prompted($sspa_135_html), "no share panel on $sspa_135_screen");
}
$sspa_135_front = sspa_135_get(home_url('/'));
sspa_135_t('' !== $sspa_135_front && !sspa_135_prompted($sspa_135_front), 'no share panel on the front end');
$sspa_135_panel_doc = sspa_135_ajax(array('action' => 'sspa_profile_panel', 'profile_id' => (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE run_id = %d ORDER BY id LIMIT 1', SSPA_Schema::table('profiles'), $sspa_135_run))));
sspa_135_t(isset($sspa_135_panel_doc['data']['html']) && !sspa_135_prompted($sspa_135_panel_doc['data']['html']), 'a profile panel opened outside the PA screen (admin bar) has no share panel');
$sspa_135_panel_doc = sspa_135_ajax(array('action' => 'sspa_profile_panel', 'pa_screen' => 1, 'profile_id' => (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE run_id = %d ORDER BY id LIMIT 1', SSPA_Schema::table('profiles'), $sspa_135_run))));
sspa_135_t(isset($sspa_135_panel_doc['data']['html']) && sspa_135_prompted($sspa_135_panel_doc['data']['html']), 'the page-analysis result on the PA screen carries the share panel');

// ---- 3. Community figures: nothing before the click ------------------------------------------
sspa_135_t(array() === sspa_135_ssorg_api_requests(), 'opening PA and rendering every tab made no request to superspeedy.org');
sspa_135_t(false !== strpos($sspa_135_compare, 'id="sspa-community-figures-load"'), 'Compare offers Load community figures');
sspa_135_t(false === strpos($sspa_135_compare, 'sspa-community-figures-table'), 'no figures are shown before the click');
$sspa_135_figures_at = strpos($sspa_135_compare, 'sspa-community-figures');
$sspa_135_runs_at = strpos($sspa_135_compare, 'sspa-pages-table');
sspa_135_t(false !== $sspa_135_figures_at && false !== $sspa_135_runs_at && $sspa_135_figures_at < $sspa_135_runs_at, 'Compared with other sites comes before the run table, not below it');

// The click, against the documents the superspeedy.org build emits.
update_option('sspa_test_ssorg_mode', 'fixture', false);
delete_option('sspa_test_ssorg_log');
$sspa_135_figures = sspa_135_ajax(array('action' => 'sspa_community_figures'), 90);
$sspa_135_requests = sspa_135_ssorg_api_requests();
$sspa_135_urls = array_map(function ($entry) { return $entry['method'] . ' ' . $entry['url']; }, $sspa_135_requests);
sspa_135_t(array('GET https://superspeedy.org/api/v1/cohorts', 'GET https://superspeedy.org/api/v1/plugin-impact') === $sspa_135_urls, 'one click makes exactly one request per document, with no query string: ' . implode(', ', $sspa_135_urls));
$sspa_135_agents = array_unique(array_map(function ($entry) { return $entry['user_agent']; }, $sspa_135_requests));
sspa_135_t(1 === count($sspa_135_agents) && false === strpos(reset($sspa_135_agents), wp_parse_url(home_url(), PHP_URL_HOST)), 'the requests carry no site address in the User-Agent (' . implode('|', $sspa_135_agents) . ')');
$sspa_135_html = isset($sspa_135_figures['data']['html']) ? $sspa_135_figures['data']['html'] : '';
sspa_135_t(!empty($sspa_135_figures['success']) && 'available' === ($sspa_135_figures['data']['state'] ?? ''), 'the figures loaded');

function sspa_135_cells($row) {
    preg_match_all('/<td class="sspa-figure-([a-z0-9]+)">(.*?)<\/td>/s', $row, $matches, PREG_SET_ORDER);
    $cells = array();
    foreach ($matches as $match) $cells[$match[1]] = trim(html_entity_decode(wp_strip_all_tags($match[2])));
    return $cells;
}
// A row of the page table (plugin rows carry data-plugin and no rung).
function sspa_135_page_row($html, $page_class) {
    return preg_match_all('/<tr data-page-class="' . preg_quote($page_class, '/') . '" data-rung="[0-5]"[^>]*>(.*?)<\/tr>/s', $html, $matches) ? $matches[1] : array();
}
function sspa_135_row($html, $attributes) {
    $pattern = '/<tr[^>]*' . implode('[^>]*', array_map(function ($name, $value) { return preg_quote($name . '="' . $value . '"', '/'); }, array_keys($attributes), $attributes)) . '[^>]*>(.*?)<\/tr>/s';
    return preg_match_all($pattern, $html, $matches) ? $matches[1] : array();
}
// Home: the site's own cohort is published at the most specific rung, from one installation.
$sspa_135_home = sspa_135_page_row($sspa_135_html, 'home');
$sspa_135_home_cells = $sspa_135_home ? sspa_135_cells($sspa_135_home[0]) : array();
sspa_135_t(1 === count($sspa_135_home) && '612.4' === ($sspa_135_home_cells['median'] ?? '') && '1 installation' === ($sspa_135_home_cells['n'] ?? '') && 0 === strpos($sspa_135_home_cells['confidence'] ?? '', 'Early data') && '-' === ($sspa_135_home_cells['p75'] ?? ''), 'Home shows the exact-cohort median 612.4 ms from 1 installation, labelled Early data, with no p75: ' . wp_json_encode($sspa_135_home_cells));
sspa_135_t(false !== strpos($sspa_135_html, 'data-page-class="home" data-rung="5"'), 'Home uses rung 5, this site\'s exact cohort, not the publishing or 1,000-product cohorts');
$sspa_135_own_home = (float) $wpdb->get_var($wpdb->prepare("SELECT page_gen_ms FROM %i WHERE run_id = %d AND page_key = 'home' AND variant = 'anon' AND object_cache_mode = 'normal'", SSPA_Schema::table('profiles'), $sspa_135_run));
sspa_135_t(number_format_i18n($sspa_135_own_home, 1) === ($sspa_135_home_cells['own'] ?? ''), 'Home shows this site\'s own latest median beside it (' . number_format_i18n($sspa_135_own_home, 1) . ' ms)');
// Shop: no figures for the exact cohort, so the closest published rung, with what was relaxed.
$sspa_135_shop = sspa_135_page_row($sspa_135_html, 'shop');
$sspa_135_shop_cells = $sspa_135_shop ? sspa_135_cells($sspa_135_shop[0]) : array();
sspa_135_t(1 === count($sspa_135_shop) && '820.0' === ($sspa_135_shop_cells['median'] ?? '') && '5 installations' === ($sspa_135_shop_cells['n'] ?? '') && 0 === strpos($sspa_135_shop_cells['confidence'] ?? '', 'Descriptive'), 'Shop falls back to its closest published cohort: 820.0 ms, 5 installations, Descriptive: ' . wp_json_encode($sspa_135_shop_cells));
sspa_135_t(false !== strpos($sspa_135_shop_cells['cohort'] ?? '', 'active plugins') && false !== strpos($sspa_135_shop_cells['cohort'] ?? '', 'object cache') && false !== strpos($sspa_135_shop_cells['cohort'] ?? '', 'database'), 'Shop says which dimensions were relaxed');
// Product page: all sites only; 21 installations publish p75 and p95.
$sspa_135_product = sspa_135_page_row($sspa_135_html, 'product-single');
$sspa_135_product_cells = $sspa_135_product ? sspa_135_cells($sspa_135_product[0]) : array();
sspa_135_t(1 === count($sspa_135_product) && array('700.0', '910.0', '1,330.0', '21 installations') === array($sspa_135_product_cells['median'] ?? '', $sspa_135_product_cells['p75'] ?? '', $sspa_135_product_cells['p95'] ?? '', $sspa_135_product_cells['n'] ?? '') && 0 === strpos($sspa_135_product_cells['confidence'] ?? '', 'Ranking') && 'All sites' === ($sspa_135_product_cells['cohort'] ?? ''), 'Product page shows all sites: median 700.0, p75 910.0, p95 1,330.0 ms, 21 installations, Ranking: ' . wp_json_encode($sspa_135_product_cells));
// Coordinator, 21 October 2026: key pages first, in a fixed order; the rest behind an expander.
$sspa_135_key = preg_match('/<tbody class="sspa-figures-key">(.*?)<\/tbody>/s', $sspa_135_html, $sspa_135_m) ? $sspa_135_m[1] : '';
$sspa_135_more = preg_match('/<tbody class="sspa-figures-more" hidden>(.*?)<\/tbody>/s', $sspa_135_html, $sspa_135_m) ? $sspa_135_m[1] : '';
preg_match_all('/<tr data-page-class="([^"]+)" data-rung/', $sspa_135_key, $sspa_135_key_classes);
preg_match_all('/<tr data-page-class="([^"]+)" data-rung/', $sspa_135_more, $sspa_135_more_classes);
sspa_135_t(array('home', 'shop', 'product-cat', 'product-single', 'wc-checkout') === $sspa_135_key_classes[1], 'key pages are shown first, in order: ' . implode(', ', $sspa_135_key_classes[1]));
$sspa_135_rest = $sspa_135_more_classes[1];
sort($sspa_135_rest);
sspa_135_t(array('admin-dashboard', 'post-cat', 'post-single') === $sspa_135_rest, 'the other page types are hidden until expanded: ' . implode(', ', $sspa_135_more_classes[1]));
sspa_135_t(1 === preg_match('/<button type="button" class="button-link sspa-figures-show-all"[^>]*>Show all 8 page types<\/button>/', $sspa_135_html), 'an expander offers Show all 8 page types');
// Plugin isolation evidence, only for plugins active here.
$sspa_135_woo = sspa_135_row($sspa_135_html, array('data-plugin' => 'woocommerce'));
sspa_135_t(2 === count($sspa_135_woo) && false !== strpos(implode('', $sspa_135_woo), '85.3') && false !== strpos(implode('', $sspa_135_woo), '142.6') && false !== strpos(implode('', $sspa_135_woo), '4 installations'), 'WooCommerce isolation deltas are shown for Home and Shop with their n');
sspa_135_t(1 === count(sspa_135_row($sspa_135_html, array('data-plugin' => 'redis-cache'))), 'Redis Object Cache (active) is shown');
sspa_135_t(array() === sspa_135_row($sspa_135_html, array('data-plugin' => 'akismet')) && false === strpos($sspa_135_html, 'akismet'), 'a plugin that is not active here is not shown');
sspa_135_t(false !== strpos($sspa_135_html, 'operated by WP Intense OÜ, which also runs superspeedyplugins.com'), 'the figures name who publishes them');

// ---- 4. What superspeedy.org really serves today ---------------------------------------------
// The observer fetches the real documents and hands back their real status and body.
update_option('sspa_test_ssorg_mode', 'live', false);
delete_option('sspa_test_ssorg_log');
$sspa_135_live = sspa_135_ajax(array('action' => 'sspa_community_figures'), 90);
$sspa_135_live_requests = sspa_135_ssorg_api_requests();
$sspa_135_codes = array_map(function ($entry) { return $entry['code']; }, $sspa_135_live_requests);
echo 'INFO: superspeedy.org answered ' . wp_json_encode($sspa_135_codes) . "\n";
if (array(404, 404) === $sspa_135_codes) {
    $sspa_135_live_html = isset($sspa_135_live['data']['html']) ? $sspa_135_live['data']['html'] : '';
    sspa_135_t('not_published' === ($sspa_135_live['data']['state'] ?? '') && false !== stripos($sspa_135_live_html, 'not published') && false !== strpos($sspa_135_live_html, 'href="https://superspeedy.org/"'), 'superspeedy.org returns 404 today, shown as not published yet with a link to superspeedy.org');
    sspa_135_t(false === strpos($sspa_135_live_html, 'sspa-community-figures-table'), 'no figures are invented for the 404');
} elseif (array(200, 200) === $sspa_135_codes) {
    $sspa_135_live_html = isset($sspa_135_live['data']['html']) ? $sspa_135_live['data']['html'] : '';
    sspa_135_t('available' === ($sspa_135_live['data']['state'] ?? ''), 'superspeedy.org now publishes both documents and they render');
    preg_match_all('/<tr data-page-class="[^"]+" data-rung="[0-5]"[^>]*>(.*?)<\/tr>/s', $sspa_135_live_html, $sspa_135_live_rows);
    $sspa_135_live_ok = array_filter($sspa_135_live_rows[1], function ($row) {
        $cells = sspa_135_cells($row);
        return preg_match('/^[0-9,]+ installations?$/', $cells['n'] ?? '') && '' !== ($cells['confidence'] ?? '') && '' !== ($cells['median'] ?? '');
    });
    echo 'INFO: live figures show ' . count($sspa_135_live_rows[1]) . " page rows\n";
    sspa_135_t(count($sspa_135_live_rows[1]) > 0 && count($sspa_135_live_ok) === count($sspa_135_live_rows[1]), 'every live page row shows its median, installation count and confidence label');
} else {
    sspa_135_t(2 === count($sspa_135_codes) && 'error' === ($sspa_135_live['data']['state'] ?? '') && '' !== ($sspa_135_live['data']['html'] ?? ''), 'an unexpected answer is reported as an error with its detail');
}

// ---- 5. Sharing hides it for good ------------------------------------------------------------
$sspa_135_optin = sspa_135_ajax(array('action' => 'sspa_share_optin', 'optin' => 1));
$sspa_135_rendered = sspa_135_tabs(array('overview', 'compare'));
sspa_135_t(!empty($sspa_135_optin['success']) && !sspa_135_prompted(implode('', $sspa_135_rendered)), 'with sharing on, no share panel anywhere');
update_option('sspa_share_optin', 0, false);
delete_option('sspa_test_ssorg_mode');
delete_option('sspa_test_ssorg_log');
