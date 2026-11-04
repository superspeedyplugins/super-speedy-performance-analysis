<?php
// GridPane's nginx page cache reports itself only as X-Grid-Cache ($upstream_cache_status). The cache
// check used to discard that header, so a GridPane site serving every page from cache was reported as
// "Page-cache reuse was not observed" (reproduced on a real GridPane site, plan
// .docs/2026-11-04-gridpane-cache-header.md).
//
// An MU fixture sends the header exactly as GridPane's wpfc.conf does, on front-end requests only.
// Two real anonymous loopback requests through the cache check must keep it and name the layer. The
// fixture stays installed for inspection and is rewritten on the next entry.

function sspa_gp_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}

$fixture = WPMU_PLUGIN_DIR . '/sspa-case155-gridpane-header.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
file_put_contents($fixture, <<<'PHP'
<?php
/**
 * Plugin Name: SSPA case 155 fixture - GridPane cache header
 * Description: Test-only. Sends X-Grid-Cache and X-Grid-Cache-TTL on front-end pages, as GridPane's nginx does.
 */
add_action('send_headers', static function () {
    header('X-Grid-Cache-TTL: 2592000');
    header('X-Grid-Cache: HIT');
});
PHP
);
sleep(3); // php-fpm opcache revalidation

$prepared = SSPA_Cache_Delivery::prepare_check();
sspa_gp_t(!is_wp_error($prepared), 'a check can be prepared');
if (is_wp_error($prepared)) {
    return;
}
$id = $prepared['assessment_id'];
$first = SSPA_Cache_Delivery::probe_server_request($id, 'home', 'anonymous', 1);
$second = SSPA_Cache_Delivery::probe_server_request($id, 'home', 'anonymous', 2);
sspa_gp_t(!is_wp_error($first) && !is_wp_error($second), 'two anonymous requests to Home ran');
if (is_wp_error($first) || is_wp_error($second)) {
    return;
}
sspa_gp_t(isset($second['headers']['x-grid-cache']) && 'HIT' === $second['headers']['x-grid-cache'], 'the X-Grid-Cache header is kept (' . wp_json_encode($second['headers']) . ')');
$layer = null;
foreach ((array) $second['cache_layers'] as $candidate) {
    if ('x-grid-cache' === $candidate['evidence_name']) {
        $layer = $candidate;
    }
}
sspa_gp_t($layer && 'gridpane_nginx' === $layer['layer'] && 'reverse_proxy_server' === $layer['position'] && 'HIT' === $layer['status'], 'the response is classified as a GridPane nginx cache HIT (' . wp_json_encode($layer) . ')');
