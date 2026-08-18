<?php
// Phase 4 e2e: cache-impact runs distinguish a cache-blind plugin from a cache-friendly
// one; the mail probe measures construction without sending; write profiles leave zero
// residue.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

if (!wp_using_ext_object_cache()) {
    echo "FAIL: the test site has no persistent object cache - install a Redis/Memcached object-cache.php drop-in on it\n";
    return;
}
sspa_t(true, 'persistent object cache active');

// --- Plant a cache-blind and a cache-friendly plugin ---
$plant = function ($slug, $code) {
    $dir = WP_PLUGIN_DIR . '/' . $slug;
    if (!is_dir($dir)) {
        mkdir($dir);
    }
    file_put_contents($dir . '/' . $slug . '.php', $code);
    activate_plugin($slug . '/' . $slug . '.php');
    // Activating from CLI with Redis enabled can leave web requests reading a stale
    // alloptions - flush so apache sees the new active_plugins immediately.
    wp_cache_flush();
};
$remove = function ($slug) {
    deactivate_plugins($slug . '/' . $slug . '.php');
    unlink(WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php');
    rmdir(WP_PLUGIN_DIR . '/' . $slug);
};

$plant('sspa-blind-plugin', <<<'PHP'
<?php
/** Plugin Name: SSPA Blind Plugin (test fixture) */
add_action('wp_footer', function () {
    global $wpdb;
    for ($i = 1; $i <= 30; $i++) {
        $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_id = " . $i);
    }
});
PHP
);
$plant('sspa-friendly-plugin', <<<'PHP'
<?php
/** Plugin Name: SSPA Friendly Plugin (test fixture) */
add_action('wp_footer', function () {
    global $wpdb;
    for ($i = 1; $i <= 30; $i++) {
        $value = wp_cache_get('sspa_friendly_' . $i, 'sspa');
        if (false === $value) {
            $value = $wpdb->get_var("SELECT option_name FROM {$wpdb->options} WHERE option_id = " . $i);
            wp_cache_set('sspa_friendly_' . $i, (string) $value, 'sspa', 300);
        }
    }
});
PHP
);
sleep(3); // opcache
sspa_t(in_array('sspa-blind-plugin/sspa-blind-plugin.php', get_option('active_plugins'), true)
    && in_array('sspa-friendly-plugin/sspa-friendly-plugin.php', get_option('active_plugins'), true), 'both fixtures active');

// --- Cache impact run on home ---
$run_id = SSPA_Run_Controller::start(array('type' => 'cache_impact', 'page_keys' => array('home'), 'user_id' => 1));
if (is_wp_error($run_id)) {
    echo 'FAIL: cache_impact start: ' . $run_id->get_error_message() . "\n";
    $remove('sspa-blind-plugin');
    $remove('sspa-friendly-plugin');
    return;
}
$deadline = time() + 240;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
sspa_t($status && 'done' === $status['status'], 'cache impact run done: ' . ($status ? $status['status'] : 'null'));

$run = SSPA_Run_Controller::run_row($run_id);
$notes = json_decode((string) $run['notes'], true);
sspa_t(is_array($notes) && !empty($notes['verified']), 'cache-off mode verified from captures');

$findings = $wpdb->get_results($wpdb->prepare(
    'SELECT * FROM ' . SSPA_Schema::table('findings') . ' WHERE run_id = %d',
    $run_id
), ARRAY_A);
$blind = null;
$friendly = null;
foreach ($findings as $f) {
    if ('cache_blind' === $f['finding_type'] && 'sspa-blind-plugin' === $f['component']) {
        $blind = json_decode($f['evidence'], true);
    }
    if ('cache_friendly' === $f['finding_type'] && 'sspa-friendly-plugin' === $f['component']) {
        $friendly = json_decode($f['evidence'], true);
    }
}
sspa_t($blind !== null, 'cache-blind plugin caught and named');
if ($blind) {
    sspa_t($blind['saved_pct'] < 15, 'blind plugin saves ~nothing (' . $blind['saved_pct'] . '%)');
}
sspa_t($friendly !== null, 'cache-friendly plugin recognised');
if ($friendly) {
    sspa_t($friendly['saved_pct'] >= 50, 'friendly plugin saves most queries (' . $friendly['saved_pct'] . '%)');
}

$false_blind = false;
foreach ($findings as $f) {
    if ('cache_blind' === $f['finding_type'] && 'sspa-friendly-plugin' === $f['component']) {
        $false_blind = true;
    }
}
sspa_t(!$false_blind, 'friendly plugin not falsely called blind');
sspa_t(!file_exists(WP_CONTENT_DIR . '/object-cache.php.sspa-hold'), 'no object-cache hold left behind');
sspa_t(file_exists(WP_CONTENT_DIR . '/object-cache.php'), 'object-cache.php still in place');

$remove('sspa-blind-plugin');
$remove('sspa-friendly-plugin');

// --- Mail probe + write profiles (zero residue) ---
$posts_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$orders_before = function_exists('wc_get_orders') ? count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'all'))) : 0;

$run_id = SSPA_Run_Controller::start(array(
    'type' => 'spot',
    'page_keys' => array('home', 'mail-probe'),
    'include_writes' => true,
    'user_id' => 1,
));
if (is_wp_error($run_id)) {
    echo 'FAIL: writes spot start: ' . $run_id->get_error_message() . "\n";
    return;
}
$deadline = time() + 240;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
sspa_t($status && 'done' === $status['status'], 'writes+mail spot run done');

$profiles = array();
foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d', $run_id), ARRAY_A) as $p) {
    $profiles[$p['page_key']] = $p;
}

sspa_t(isset($profiles['mail-probe']), 'mail probe profiled');
if (isset($profiles['mail-probe'])) {
    sspa_t((int) $profiles['mail-probe']['mail_count'] >= 1, 'probe email intercepted (count ' . $profiles['mail-probe']['mail_count'] . ')');
    sspa_t($profiles['mail-probe']['mail_ms'] !== null, 'construction cost measured (' . $profiles['mail-probe']['mail_ms'] . 'ms)');
    sspa_t((int) $profiles['mail-probe']['response_code'] === 200, 'probe responded 200');
}

sspa_t(isset($profiles['write-save-post']), 'save-post write profile captured');
sspa_t(isset($profiles['write-save-product']), 'save-product write profile captured');
sspa_t(isset($profiles['write-order-processing']), 'order-processing write profile captured');
if (isset($profiles['write-order-processing'])) {
    $p = $profiles['write-order-processing'];
    sspa_t((int) $p['sql_count'] > 20, 'order transitions ran a real cascade (' . $p['sql_count'] . ' queries)');
    sspa_t((int) $p['mail_count'] >= 1, 'transition emails intercepted, not sent (count ' . $p['mail_count'] . ')');
}

$posts_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$orders_after = function_exists('wc_get_orders') ? count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'all'))) : 0;
sspa_t($posts_after === $posts_before, "zero post residue ($posts_before -> $posts_after)");
sspa_t($orders_after === $orders_before, "zero order residue ($orders_before -> $orders_after)");
$temp_left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sspa_temp'");
sspa_t(0 === $temp_left, 'no _sspa_temp markers left behind');
