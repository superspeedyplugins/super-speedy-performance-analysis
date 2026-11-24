<?php
// Per-query cost of SSPA_Query_Context::observe(), on the real wpdb of this site: N cheap queries
// with and without the observer installed, in the order off, on (PA wpdb: no backtrace), on
// (foreign db.php: bounded backtrace), repeated 5 times; medians reported. Inside an open shortcode
// and a 4-deep hook stack, so the stack copy and shortcode merge are paid as on a real page.
require_once SSPA_PLUGIN_DIR . 'profiler/class-sspa-query-context.php';
global $wpdb;
$n = 4000;
$time = static function () use ($wpdb, $n) {
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $wpdb->query('SELECT 1');
    }
    return (hrtime(true) - $start) / 1e6;
};
$GLOBALS['wp_current_filter'] = array('template_redirect', 'wp_footer', 'sspa_outer', 'sspa_inner');
$results = array('off' => array(), 'on_no_frames' => array(), 'on_frames' => array());
for ($round = 0; $round < 5; $round++) {
    foreach (array('off' => null, 'on_no_frames' => false, 'on_frames' => true) as $mode => $frames) {
        $context = null;
        if (null !== $frames) {
            $context = new SSPA_Query_Context();
            $context->install($frames);
            $context->shortcode_open(false, 'sspa_micro');
        }
        $results[$mode][] = $time();
        if ($context) {
            remove_all_filters('query');
            remove_all_filters('pre_do_shortcode_tag');
            remove_all_filters('do_shortcode_tag');
        }
    }
}
$median = static function ($values) { sort($values); return $values[(int) floor(count($values) / 2)]; };
$off = $median($results['off']);
foreach ($results as $mode => $values) {
    $m = $median($values);
    printf("%-13s median %.1f ms for %d queries = %.2f us/query (%+.2f us/query over off)\n", $mode, $m, $n, $m * 1000 / $n, ($m - $off) * 1000 / $n);
}
