<?php
// LLM hand-off is a first-class user export, not a JSON payload relabelled as Markdown.
// The same privacy-safe document must back both Download Markdown and Copy Markdown.

function sspa_markdown_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

sspa_markdown_t(class_exists('SSPA_Markdown_Export'), 'the Markdown export service is loaded');
if (!class_exists('SSPA_Markdown_Export')) {
    return;
}

global $wpdb;
wp_set_current_user(1);
$runs_table = SSPA_Schema::table('runs');
$profiles_table = SSPA_Schema::table('profiles');

// Clear this case's previous fixtures on the way in. The finished test site remains in place.
$old_runs = $wpdb->get_col("SELECT id FROM $runs_table WHERE trigger_source = 'markdown-test'");
if ($old_runs) {
    $ids = implode(',', array_map('intval', $old_runs));
    $wpdb->query("DELETE FROM $profiles_table WHERE run_id IN ($ids)");
    $wpdb->query('DELETE FROM ' . SSPA_Schema::table('findings') . " WHERE run_id IN ($ids)");
    $wpdb->query("DELETE FROM $runs_table WHERE id IN ($ids)");
}

function sspa_markdown_run($type, $notes) {
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert(SSPA_Schema::table('runs'), array(
        'run_uuid' => wp_generate_uuid4(),
        'blog_id' => 1,
        'run_type' => $type,
        'measurement_version' => 1,
        'trigger_source' => 'markdown-test',
        'status' => 'done',
        'plugin_set' => wp_json_encode(array('components' => array(
            array('type' => 'plugin', 'slug' => 'synthetic-slow-plugin', 'version' => '1.2.3'),
        ))),
        'plugin_set_hash' => md5('markdown-test-' . $type),
        'started' => $now,
        'finished' => $now,
        'notes' => wp_json_encode($notes),
    ));
    return (int) $wpdb->insert_id;
}

function sspa_markdown_profile($run_id, $page_key, $capture, $args = array()) {
    global $wpdb;
    $wpdb->insert(SSPA_Schema::table('profiles'), array(
        'run_id' => $run_id,
        'page_key' => $page_key,
        'url' => home_url('/orders/987654321?token=markdown-secret-token&email=private%40example.test'),
        'method' => isset($args['method']) ? $args['method'] : 'GET',
        'variant' => isset($args['variant']) ? $args['variant'] : 'admin',
        'plugin_set_hash' => '',
        'object_cache_mode' => 'normal',
        'samples' => wp_json_encode(array(array('wall_ms' => 820, 'code' => 200))),
        'ttfb_ms' => 810,
        'page_gen_ms' => isset($args['gen_ms']) ? $args['gen_ms'] : 800,
        'sql_ms' => 310,
        'sql_count' => 45,
        'http_ms' => 240,
        'http_count' => 1,
        'peak_mem_bytes' => 33554432,
        'response_code' => 200,
        'profile_blob' => gzcompress(wp_json_encode($capture), 6),
        'created' => gmdate('Y-m-d H:i:s'),
    ));
    return (int) $wpdb->insert_id;
}

$secret = 'markdown-secret-token';
$capture = array(
    'schema' => 3,
    'overview' => array('is_admin' => true),
    'components' => array(
        'synthetic-slow-plugin' => array('sql_ms' => 310, 'query_count' => 45, 'rows' => 12, 'http_ms' => 240),
    ),
    'sql' => array('queries' => array(
        array(
            'event_id' => 'q-1',
            'sql' => "SELECT * FROM wp_options WHERE option_value = '$secret' AND option_id = 987654321",
            'fp' => 'SELECT * FROM wp_options WHERE option_value = ? AND option_id = ?',
            'component' => 'synthetic-slow-plugin',
            'caller' => '/srv/www/private/site/wp-content/plugins/synthetic-loop-plugin/src/Save.php:41 Synthetic_Slow_Plugin::save',
            'via' => 'synthetic-api-plugin',
            'chain' => array('plugin:synthetic-slow-plugin', 'plugin:synthetic-loop-plugin'),
            'ms' => 120,
            'rows' => 5,
        ),
        array(
            'event_id' => 'q-2',
            'sql' => null,
            'fp' => 'SELECT * FROM wp_options WHERE option_value = ? AND option_id = ?',
            'component' => 'synthetic-slow-plugin',
            'caller' => 'Synthetic_Slow_Plugin::save',
            'via' => 'synthetic-api-plugin',
            'chain' => array('plugin:synthetic-slow-plugin', 'plugin:synthetic-loop-plugin'),
            'ms' => 100,
            'rows' => 4,
        ),
        array(
            'event_id' => 'q-3',
            'sql' => null,
            'fp' => 'SELECT * FROM wp_options WHERE option_value = ? AND option_id = ?',
            'component' => 'synthetic-slow-plugin',
            'caller' => 'Synthetic_Slow_Plugin::save',
            'via' => 'synthetic-api-plugin',
            'chain' => array('plugin:synthetic-slow-plugin', 'plugin:synthetic-loop-plugin'),
            'ms' => 90,
            'rows' => 3,
        ),
    )),
    'http' => array('count' => 1, 'total_ms' => 240, 'calls' => array(array(
        'scheme' => 'https',
        'url' => 'licence.vendor.example/orders/987654321/check',
        'q' => 'token=' . $secret . '&email=private%40example.test',
        'method' => 'GET',
        'ms' => 240,
        'code' => 200,
        'blocking' => true,
        'sslverify' => true,
        'component' => 'synthetic-slow-plugin',
        'ctype' => 'plugin',
        'caller' => 'Synthetic_Slow_Plugin::licence',
    ))),
);

// Page and full-site documents are built from the real stored schemas.
$baseline_run = sspa_markdown_run('baseline', array('score' => 52, 'findings' => 0));
$profile_id = sspa_markdown_profile($baseline_run, 'wp-admin-synthetic', $capture);
$wpdb->insert(SSPA_Schema::table('findings'), array(
    'run_id' => $baseline_run,
    'severity' => 'critical',
    'finding_type' => 'slow_query',
    'component' => 'synthetic-slow-plugin',
    'page_key' => 'wp-admin-synthetic',
    'evidence' => wp_json_encode(array(
        'sql' => "SELECT * FROM wp_options WHERE option_value = '$secret' AND option_id = 987654321",
        'fp' => 'SELECT * FROM wp_options WHERE option_value = ? AND option_id = ?',
        'ms' => 310,
    )),
    'recommendation_key' => 'optimise_query',
    'confidence' => 'measured',
    'created' => gmdate('Y-m-d H:i:s'),
));
$wpdb->insert(SSPA_Schema::table('findings'), array(
    'run_id' => $baseline_run,
    'severity' => 'warn',
    'finding_type' => 'query_loop',
    'component' => 'synthetic-loop-plugin',
    'page_key' => 'wp-admin-synthetic',
    'evidence' => wp_json_encode(array(
        'query_count' => 45,
        'sql_ms' => 310,
        'rows' => 12,
        'ran_in' => array('synthetic-api-plugin' => 45),
    )),
    'recommendation_key' => 'query_loop',
    'confidence' => 'inferred',
    'created' => gmdate('Y-m-d H:i:s'),
));
$page = SSPA_Markdown_Export::build('page', $profile_id);
$site = SSPA_Markdown_Export::build('run', $baseline_run);

sspa_markdown_t(!is_wp_error($page) && '.md' === substr($page['filename'], -3), 'a page result produces a downloadable .md file');
sspa_markdown_t(!is_wp_error($site) && '.md' === substr($site['filename'], -3), 'a complete-site run produces a downloadable .md file');
if (!is_wp_error($page) && !is_wp_error($site)) {
    sspa_markdown_t(false !== strpos($page['markdown'], '<!-- sspa/llm-report@2 -->'), 'the LLM report schema is explicit and structurally versioned');
    sspa_markdown_t(false !== strpos($page['markdown'], '## Diagnostic summary') && false !== strpos($page['markdown'], '## Dominant SQL groups'), 'the page document leads with a mechanical diagnosis and aggregated SQL evidence');
    sspa_markdown_t(false !== strpos($page['markdown'], '| `synthetic-slow-plugin` | 310.0 ms | 45 | 12 | 240.0 ms |'), 'component query_count and returned rows are exported');
    sspa_markdown_t(false !== strpos($page['markdown'], '| `synthetic-slow-plugin` | `SELECT * FROM wp_options WHERE option_value = ? AND option_id = ?` | 3 | 310.0 ms | 120.0 ms | 12 |'), 'matching retained queries aggregate into one group with calls, total, worst and rows');
    sspa_markdown_t(false !== strpos($page['markdown'], 'Synthetic_Slow_Plugin::save') && false !== strpos($page['markdown'], 'synthetic-api-plugin') && false !== strpos($page['markdown'], 'Code owner'), 'caller, via and attribution mode are represented');
    sspa_markdown_t(false === strpos($page['markdown'], '/srv/www/private'), 'absolute caller paths are not exported');
    sspa_markdown_t(false !== strpos($page['markdown'], 'synthetic-loop-plugin\'s own code') && false !== strpos($page['markdown'], '45 inside synthetic-api-plugin'), 'query-loop prose keeps possessive apostrophes and measured numbers intact');
    sspa_markdown_t(false === strpos($page['markdown'], '?s own code'), 'narrative query-loop evidence is not SQL-fingerprinted');
    sspa_markdown_t(false !== strpos($page['markdown'], '- Confidence: `measured`') && false !== strpos($page['markdown'], '- Confidence: `inferred`'), 'finding confidence is explicit');
    sspa_markdown_t(false !== strpos($page['markdown'], '- WordPress: `' . get_bloginfo('version') . '`') && false !== strpos($page['markdown'], '- PHP: `' . PHP_VERSION . '`') && false !== strpos($page['markdown'], '`synthetic-slow-plugin 1.2.3`'), 'environment and measured component versions are exported');
    sspa_markdown_t(false !== strpos($page['markdown'], 'Relevant existing indexes: not captured') && false !== strpos($page['markdown'], 'Complete join plan: not captured'), 'missing plan and index evidence is stated rather than invented');
    sspa_markdown_t(false !== strpos($page['markdown'], 'enough to triage') && false === strpos($page['markdown'], 'designed to be enough for you or your developer to act on'), 'evidence sufficiency no longer makes a blanket implementation claim');
    sspa_markdown_t(false !== strpos($site['markdown'], '## Outbound WordPress HTTP API inventory'), 'the site document carries the stable HTTP API inventory');
    sspa_markdown_t(false !== strpos($site['markdown'], 'licence.vendor.example/orders/{id}/check'), 'HTTP endpoint path identifiers are normalised');
    $combined = $page['markdown'] . $site['markdown'];
    sspa_markdown_t(false === strpos($combined, $secret) && false === strpos($combined, 'private@example.test') && false === strpos($combined, '987654321'), 'SQL literals, query values and variable path IDs do not reach an LLM document');
    sspa_markdown_t(false !== strpos($combined, SSPA_Markdown_Export::CACHE_SERVICE_URL), 'the optional implementation-help section is present');
}

// Checkout uses its actual waterfall but must never export fulfilment identifiers.
$private_email = 'fulfilment-private@example.test';
$checkout_run = sspa_markdown_run('checkout', array(
    'outcome' => 'transport_failed',
    'flow' => array(
        'email' => $private_email,
        'order_id' => 7654321,
        'order_number' => 'PRIVATE-ORDER-ABC',
        'coupon_codes' => array('PRIVATE-COUPON'),
        'items' => array(array('name' => 'Synthetic Product', 'quantity' => 1)),
        'payment_mode' => 'no_payment',
        'complete_from_status' => 'processing',
        'complete_to_status' => 'completed',
        'error' => 'cURL error 28 fetching https://private.example.test/orders/7654321?token=' . $secret . '&email=' . rawurlencode($private_email),
    ),
    'safety' => array('orders_deleted' => 1, 'orders_left' => 0, 'users_deleted' => 0, 'users_left' => 0),
));
sspa_markdown_profile($checkout_run, 'flow-view-cart', $capture, array('gen_ms' => 300));
sspa_markdown_profile($checkout_run, 'flow-place-order', $capture, array('method' => 'POST', 'gen_ms' => 700));
sspa_markdown_profile($checkout_run, 'flow-order-received', $capture, array('gen_ms' => 150));
sspa_markdown_profile($checkout_run, 'flow-view-order', $capture, array('gen_ms' => 450));
sspa_markdown_profile($checkout_run, 'flow-complete-order', $capture, array('method' => 'POST', 'gen_ms' => 600));
$checkout = SSPA_Markdown_Export::build('checkout', $checkout_run);
sspa_markdown_t(!is_wp_error($checkout) && false !== strpos($checkout['markdown'], '## At risk before payment') && false !== strpos($checkout['markdown'], '## Order management'), 'checkout and order analysis exports both timing buckets');
if (!is_wp_error($checkout)) {
    sspa_markdown_t(false !== strpos($checkout['markdown'], 'Failure detail: cURL error 28'), 'checkout transport failure detail remains available for diagnosis');
    sspa_markdown_t(false === strpos($checkout['markdown'], $secret) && false === strpos($checkout['markdown'], $private_email) && false === strpos($checkout['markdown'], '7654321') && false === strpos($checkout['markdown'], 'PRIVATE-ORDER-ABC') && false === strpos($checkout['markdown'], 'PRIVATE-COUPON'), 'checkout fulfilment identifiers stay out of the LLM document');
}

// Every result surface exposes both actions, all backed by the service asserted above.
$panel = SSPA_Profile_Panel::render($profile_id, array('cached' => true));
sspa_markdown_t(is_string($panel) && false !== strpos($panel, 'sspa-markdown-download') && false !== strpos($panel, 'sspa-markdown-copy'), 'single-page results offer Download Markdown and Copy Markdown');

$wpdb->insert(SSPA_Schema::table('findings'), array(
    'run_id' => $baseline_run,
    'severity' => 'info',
    'finding_type' => 'cache_safety',
    'component' => null,
    'page_key' => null,
    'evidence' => wp_json_encode(array(
        'shared_cache_status' => 'no_visitor_specific_content_hazards_detected',
        'pages_scanned' => 1,
        'difficulty' => 'low',
        'hazards' => array(),
        'candidate_components' => array(),
        'totals' => array(),
        'source_scan' => array(),
        'pages' => array(),
    )),
    'recommendation_key' => 'cache_safety_review',
    'confidence' => 'measured',
    'created' => gmdate('Y-m-d H:i:s'),
));
ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/overview.php';
$overview = ob_get_clean();
$analysis_start = strpos($overview, 'id="sspa-run-panel"');
$analysis_end = false === $analysis_start ? false : strpos($overview, '</div>', $analysis_start);
$analysis_html = (false !== $analysis_start && false !== $analysis_end) ? substr($overview, $analysis_start, $analysis_end - $analysis_start) : '';
sspa_markdown_t(false !== strpos($analysis_html, 'data-kind="run"') && false !== strpos($analysis_html, 'Download Markdown') && false !== strpos($analysis_html, 'Copy Markdown'), 'the Analysis panel itself offers both Markdown actions');
sspa_markdown_t(false !== $analysis_start && $analysis_start < strpos($overview, 'sspa-score-row') && $analysis_start < strpos($overview, '>Health<'), 'the Analysis panel is the first Overview panel');
sspa_markdown_t(false !== strpos($overview, SSPA_Markdown_Export::CACHE_SERVICE_URL), 'cache optimisation shows the implementation-service link');

ob_start();
include SSPA_PLUGIN_DIR . 'includes/admin/tabs/history.php';
$history = ob_get_clean();
sspa_markdown_t(false !== strpos($history, 'data-kind="checkout"') && false !== strpos($history, 'data-kind="run"'), 'history exposes Markdown reports for site and checkout runs');

$checkout_js = file_get_contents(SSPA_PLUGIN_DIR . 'includes/admin/js/sspa-checkout.js');
sspa_markdown_t(false !== strpos($checkout_js, 'data-kind="checkout"') && false !== strpos($checkout_js, 'Copy Markdown'), 'the completed checkout panel exposes both Markdown actions');
