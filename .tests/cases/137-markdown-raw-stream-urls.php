<?php
// Phase 5 (2026-10-21-quote-request-plan.md, scope 2): the page Markdown is what a quote request
// sends, so a URL a page reads through PHP's raw http:// stream must reach the export with the
// same hygiene as every other URL there. A real opt-in raw-stream analysis of a page whose PHP
// reads this site's own static file through a URL carrying a query-string token, an email
// address and a numeric identifier in its path (.tests/fixtures/quote-raw-stream.php).
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
wp_set_current_user(1);

$fixture = WPMU_PLUGIN_DIR . '/sspa-quote-raw-stream.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
if (!file_exists($fixture) || md5_file($fixture) !== md5_file(SSPA_PLUGIN_DIR . '.tests/fixtures/quote-raw-stream.php')) {
    copy(SSPA_PLUGIN_DIR . '.tests/fixtures/quote-raw-stream.php', $fixture);
    sleep(3); // php-fpm opcache revalidation
}
$static = WP_CONTENT_DIR . '/uploads/quote-raw/customer@example.com/98765';
wp_mkdir_p($static);
file_put_contents($static . '/report.txt', 'raw stream fixture body');

$url = add_query_arg('quote_raw_fixture', '1', home_url('/'));
$run = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'user_id' => 1, 'raw_streams' => true));
if (is_wp_error($run)) {
    sspa_fleet_assert(false, 'raw-stream analysis started: ' . $run->get_error_message());
    sspa_fleet_done();
    return;
}
sspa_fleet_complete($run);
global $wpdb;
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), $run), ARRAY_A);
$capture = $row ? SSPA_Profile_Panel::capture($row) : null;
$reads = array_values(array_filter(isset($capture['raw_streams']['calls']) ? (array) $capture['raw_streams']['calls'] : array(), static function ($call) {
    return false !== strpos((string) $call['url'], '/report.txt');
}));
sspa_fleet_assert(!empty($capture['raw_streams']['enabled']) && count($reads) === 1 && !empty($reads[0]['opened']) && $reads[0]['bytes'] === strlen('raw stream fixture body'),
    'the opt-in raw-stream pass captured the page\'s real read of its own file (' . (isset($reads[0]['url']) ? $reads[0]['url'] : 'none') . ')');

$built = SSPA_Markdown_Export::build('page', (int) $row['id']);
$md = is_wp_error($built) ? '' : $built['markdown'];
$out = SSPA_PLUGIN_DIR . '.data/quote-request/';
wp_mkdir_p($out);
file_put_contents($out . 'raw-stream-page.md', $md);
$table = array_values(array_filter(explode("\n", $md), static function ($line) { return false !== strpos($line, 'report.txt'); }));
file_put_contents($out . 'raw-stream-rows.txt', implode("\n", $table) . "\n");

sspa_fleet_assert('' !== $md && false !== strpos($md, 'Opt-in raw HTTP/HTTPS stream diagnostic') && count($table) >= 1,
    'the page Markdown lists the raw read in its raw-stream table');
sspa_fleet_assert(false === strpos($md, 'QUERYSECRET123') && false === strpos($md, 'someone%40example.com') && false === strpos($md, 'someone@example.com'),
    'no query-string value of the raw read reaches the page Markdown');
sspa_fleet_assert(false === strpos($md, 'customer@example.com'),
    'the email address in the raw read\'s path does not reach the page Markdown');
sspa_fleet_assert(false === strpos(implode("\n", $table), '98765'),
    'the numeric identifier in the raw read\'s path does not reach the page Markdown');
sspa_fleet_assert((bool) preg_match('#quote-raw/\{value\}/\{value\}/report\.txt#', implode("\n", $table)),
    'the raw-stream row keeps the endpoint shape with private segments replaced, as the HTTP API table does: ' . (isset($table[0]) ? $table[0] : 'no row'));
sspa_fleet_done();
