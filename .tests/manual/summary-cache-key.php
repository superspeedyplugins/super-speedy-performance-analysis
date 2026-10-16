<?php
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
$cache_dir = ABSPATH . 'summary-url-cache';
wp_mkdir_p($cache_dir);
foreach (glob($cache_dir . '/*.key') as $file) unlink($file);
file_put_contents(WPMU_PLUGIN_DIR . '/sspa-summary-url-cache.php', <<<'FIXTURE'
<?php
add_action('template_redirect', function () {
    if (!isset($_GET['summary_url_key'])) return;
    $key = ABSPATH . 'summary-url-cache/' . hash('sha256', $_SERVER['REQUEST_URI']) . '.key';
    $miss = !file_exists($key);
    if ($miss) { usleep(150000); file_put_contents($key, 'rendered'); }
    header('X-Summary-Cache-Miss: ' . ($miss ? '1' : '0'));
    echo 'Stable URL-keyed rendered content';
    exit;
});
FIXTURE
);
sleep(3);
$base = home_url('/?summary_url_key=1');
$rows = array();
foreach (array('normal','normal','signed-same-url','signed-busted','signed-busted','signed-busted') as $mode) {
    $url = 'signed-busted' === $mode ? SSPA_Crawler::bust_url($base) : $base;
    $headers = array();
    $token = null;
    if (0 === strpos($mode, 'signed')) {
        $token = SSPA_Token::mint($url, array('v'=>'anon','xp'=>'0'));
        $headers[SSPA_Token::HEADER] = $token['header'];
    }
    $start = microtime(true);
    $response = SSPA_Crawler::request($url, array('headers'=>$headers));
    if (is_wp_error($response)) throw new RuntimeException($response->get_error_message());
    sspa_fleet_assert(wp_remote_retrieve_response_code($response) === 200 && wp_remote_retrieve_body($response) === 'Stable URL-keyed rendered content', 'same real rendered output for ' . $mode);
    $rows[] = array('mode'=>$mode,'url'=>$url,'miss'=>wp_remote_retrieve_header($response,'x-summary-cache-miss'),'wall_ms'=>(microtime(true)-$start)*1000);
    if ($token) SSPA_Crawler::discard_capture($token['id']);
}
file_put_contents(SSPA_PLUGIN_DIR . '.data/summary/cache-key.json', wp_json_encode($rows, JSON_PRETTY_PRINT));
sspa_fleet_done();
