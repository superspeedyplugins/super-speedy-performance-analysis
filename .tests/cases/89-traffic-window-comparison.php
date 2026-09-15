<?php
// Issue 2. Two real traffic collection windows, each fed the same bounded set of visitor
// requests, with a known, controlled slowdown between them. The comparison must report the
// change normalised for duration, say what it could not measure, keep every privacy rule,
// and mean the same thing from the domain call, the real CLI and the Abilities callback.
// Both windows stay on the site for inspection.
require __DIR__ . '/../lib/fleet.php';

function sspa_89_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_89_failures']++; }
}
$GLOBALS['sspa_89_failures'] = 0;
function sspa_89_window($label, $requests) {
    $started = SSPA_Traffic_Collection::start('15m', 'test');
    if (is_wp_error($started)) { throw new RuntimeException($label . ': ' . $started->get_error_message()); }
    $id = (int) $started['collection']['id'];
    $row = SSPA_Traffic_Collection::get($id);
    // Every origin request is recorded (no sampling), so the two windows are comparable.
    SSPA_Traffic_Helper::install(array(
        'collection_id' => $id,
        'collect_until' => strtotime($row['collect_until'] . ' UTC'),
        'outcomes_until' => strtotime($row['outcomes_until'] . ' UTC'),
        'event_id_stop' => (int) $row['event_id_stop'],
        'origin_sample_modulus' => 1,
        'key_option' => SSPA_Traffic_Collection::key_option($id),
    ));
    sleep(3); // the generated observer must reach php-fpm's opcache
    $codes = array();
    foreach ($requests as $i => $request) {
        $response = wp_remote_get(add_query_arg('sspa_window', $label . '-' . $i, $request['url']), array('timeout' => 30, 'sslverify' => false, 'user-agent' => $request['ua']));
        $codes[] = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    }
    // Hold both windows open for at least 30 seconds before stopping them.
    $finish_at = strtotime($row['started_at'] . ' UTC') + 30;
    $remaining = $finish_at - microtime(true);
    if ($remaining <= 0) { throw new RuntimeException($label . ': requests exceeded the fixed 30-second observation budget'); }
    usleep((int) ceil($remaining * 1000000));
    // A normal stop keeps the collection active for its outcome window (days), and only one
    // collection may be active, so two windows within one session - the way a person
    // actually compares before and after a change - end with the emergency stop.
    $stopped = SSPA_Traffic_Collection::stop($id, true);
    if (is_wp_error($stopped)) { throw new RuntimeException($label . ' stop: ' . $stopped->get_error_message()); }
    return array('id' => $id, 'codes' => $codes);
}

wp_set_current_user(1);
global $wpdb;
sspa_89_t(class_exists('WooCommerce'), 'WooCommerce is active on the test site');

// On the way in: no running collection, no leftover delay, the delay fixture installed.
$active = SSPA_Traffic_Collection::active();
if ($active) { SSPA_Traffic_Collection::stop((int) $active['id'], true); }
update_option('sspa_traffic_delay_ms', 0, false);
wp_mkdir_p(WPMU_PLUGIN_DIR);
$fixture = WPMU_PLUGIN_DIR . '/sspa-traffic-delay-fixture.php';
if (!file_exists($fixture) || md5_file($fixture) !== md5_file(__DIR__ . '/../fixtures/traffic-delay-fixture.php')) {
    copy(__DIR__ . '/../fixtures/traffic-delay-fixture.php', $fixture);
    sleep(3);
}

// The same bounded, deterministic visit both times: a browser and a bot, on the same pages.
$product = wc_get_products(array('status' => 'publish', 'type' => 'simple', 'stock_status' => 'instock', 'limit' => 1, 'return' => 'ids'));
$pages = array(home_url('/'), wc_get_page_permalink('shop'), $product ? get_permalink((int) $product[0]) : home_url('/'), wc_get_page_permalink('cart'));
$requests = array();
foreach (array('Mozilla/5.0 (Windows NT 10.0) Chrome/128 Safari/537.36', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)') as $ua) {
    foreach ($pages as $url) {
        $requests[] = array('url' => $url, 'ua' => $ua);
    }
}
$delay_ms = 150;

try {
    $before = sspa_89_window('before', $requests);
    sspa_89_t(count(array_filter($before['codes'], function ($c) { return 200 === $c; })) === count($requests), 'the first window received its ' . count($requests) . ' requests (' . implode(',', $before['codes']) . ')');

    update_option('sspa_traffic_delay_ms', $delay_ms, false);
    wp_cache_flush();
    $after = sspa_89_window('after', $requests);
    update_option('sspa_traffic_delay_ms', 0, false);
    wp_cache_flush();
    sspa_89_t(count(array_filter($after['codes'], function ($c) { return 200 === $c; })) === count($requests), 'the second window received the same ' . count($requests) . ' requests with a ' . $delay_ms . 'ms measured slowdown');

    // The comparison from the domain call.
    $comparison = SSPA_Traffic_Collection::comparison($before['id'], $after['id']);
    sspa_89_t(!is_wp_error($comparison), 'the comparison builds and passes its privacy allowlist' . (is_wp_error($comparison) ? ' (' . $comparison->get_error_message() . ')' : ''));
    if (is_wp_error($comparison)) { throw new RuntimeException('no comparison'); }
    $origin = $comparison['changes']['origin_page_generation'];
    $b_avg = $comparison['before']['origin_page_generation']['wall_ms_average'] ?? null;
    $a_avg = $comparison['after']['origin_page_generation']['wall_ms_average'] ?? null;
    sspa_89_t(null !== $b_avg && null !== $a_avg && 'normalised_comparison' === $origin['wall_ms_average']['quality'], 'both windows carry a measured per-request average (' . var_export($b_avg, true) . ' -> ' . var_export($a_avg, true) . ')');
    $rise = (float) $origin['wall_ms_average']['absolute'];
    sspa_89_t($rise >= $delay_ms * 0.6 && $rise <= $delay_ms * 2.5, 'the per-request average rose by about the controlled slowdown (+' . round($rise, 1) . 'ms for ' . $delay_ms . 'ms)');
    $b_duration = $comparison['before']['origin_page_generation']['observed_duration_seconds'];
    $a_duration = $comparison['after']['origin_page_generation']['observed_duration_seconds'];
    sspa_89_t($b_duration >= 30 && $a_duration >= 30, 'both real windows include the full 30-second observation period (' . $b_duration . ' -> ' . $a_duration . ')');
    foreach (array('before' => $before['id'], 'after' => $after['id']) as $label => $id) {
        $row = SSPA_Traffic_Collection::get($id);
        $duration = strtotime($row['finished_at'] . ' UTC') - strtotime($row['started_at'] . ' UTC');
        $metrics = $comparison[$label]['origin_page_generation'];
        sspa_89_t($duration === $metrics['observed_duration_seconds'], $label . ': reported duration matches the real retained start and stop');
        sspa_89_t((int) round(count($requests) * DAY_IN_SECONDS / $duration) === $metrics['projected_daily_requests'], $label . ': daily requests use the actual observation duration');
        sspa_89_t((int) round($metrics['estimated_wall_ms_sum'] * DAY_IN_SECONDS / $duration) === $metrics['projected_daily_wall_ms'], $label . ': daily processing uses the measured total and actual observation duration');
    }
    sspa_89_t(count($requests) === (int) $comparison['before']['origin_page_generation']['estimated_requests'] && count($requests) === (int) $comparison['after']['origin_page_generation']['estimated_requests'], 'both windows contain exactly the requested origin observations');
    $requests_change = $origin['projected_daily_requests'];
    sspa_89_t('normalised_comparison' === $requests_change['quality'] && $requests_change['before'] > 0 && abs((float) $requests_change['percent']) <= 60, 'projected daily requests are duration-normalised and comparable for the same visit (' . $requests_change['before'] . ' -> ' . $requests_change['after'] . ', ' . var_export($requests_change['percent'], true) . '%)');
    sspa_89_t((float) $origin['projected_daily_wall_ms']['absolute'] > 0, 'projected daily processing rose with the slowdown');
    sspa_89_t(in_array('not_identified_as_automation_does_not_mean_human', $comparison['limitations'], true), 'the comparison states its limitations');

    // Missing data is reported as unavailable, never as a zero change.
    $empty_started = SSPA_Traffic_Collection::start('15m', 'test');
    $empty_id = (int) $empty_started['collection']['id'];
    SSPA_Traffic_Collection::stop($empty_id, true);
    $against_empty = SSPA_Traffic_Collection::comparison($before['id'], $empty_id);
    sspa_89_t(!is_wp_error($against_empty) && 'unavailable' === $against_empty['changes']['origin_page_generation']['wall_ms_average']['quality'] && null === $against_empty['changes']['origin_page_generation']['wall_ms_average']['absolute'], 'a window with no requests compares as unavailable, not as a change');

    // Privacy: nothing that identifies a visitor, page or site crosses the boundary.
    $json = wp_json_encode($comparison);
    sspa_89_t(false === strpos($json, 'sspa_window') && false === strpos($json, 'Googlebot') && false === strpos($json, wp_parse_url(home_url(), PHP_URL_HOST)), 'the comparison carries no URL, user agent or host');

    // The real CLI and the Abilities callback mean the same thing.
    $cli = json_decode(sspa_fleet_cli(array('sspa', 'traffic', 'compare', (string) $before['id'], (string) $after['id'])), true);
    sspa_89_t(is_array($cli) && $cli['schema'] === $comparison['schema'] && $cli['changes'] == $comparison['changes'] && $cli['before']['collection_id'] === $comparison['before']['collection_id'], 'the real CLI reports the identical comparison');
    $ability = SSPA_Abilities::exec_compare_traffic_collections(array('before_collection_id' => $before['id'], 'after_collection_id' => $after['id']));
    sspa_89_t(is_array($ability) && $ability['changes'] == $comparison['changes'], 'the Abilities callback reports the identical comparison');

    // A finished window's normalisation must not drift with the clock. An emergency stop
    // records finished_at but leaves the planned collect_until in place; the observed
    // duration must stop at the stop.
    $drift_started = SSPA_Traffic_Collection::start('15m', 'test');
    $drift_id = (int) $drift_started['collection']['id'];
    SSPA_Traffic_Collection::stop($drift_id, true);
    $first = SSPA_Traffic_Collection::observations($drift_id);
    sleep(4);
    $second = SSPA_Traffic_Collection::observations($drift_id);
    $d1 = is_wp_error($first) ? null : ($first['origin_page_generation']['observed_duration_seconds'] ?? null);
    $d2 = is_wp_error($second) ? null : ($second['origin_page_generation']['observed_duration_seconds'] ?? null);
    sspa_89_t(null !== $d1 && $d1 === $d2, 'an emergency-stopped window keeps a fixed observed duration (' . var_export($d1, true) . 's, then ' . var_export($d2, true) . 's four seconds later)');

    echo 'RETAINED: windows ' . $before['id'] . ' (before), ' . $after['id'] . ' (after, +' . $delay_ms . 'ms), ' . $empty_id . ' (empty), ' . $drift_id . ' (emergency stop)' . "\n";
} catch (Throwable $error) {
    sspa_89_t(false, $error->getMessage());
}
update_option('sspa_traffic_delay_ms', 0, false);

if ($GLOBALS['sspa_89_failures']) { exit(1); }
