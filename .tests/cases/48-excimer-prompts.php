<?php
// Missing Excimer data must say how to add it, and an Excimer phase must be expandable even
// when the boot timer captured no wrapped component detail for that phase.

function sspa_excimer_ui_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;
wp_set_current_user(1);
$runs_table = SSPA_Schema::table('runs');
$profiles_table = SSPA_Schema::table('profiles');

$old_runs = $wpdb->get_col("SELECT id FROM $runs_table WHERE trigger_source = 'excimer-ui-test'");
if ($old_runs) {
    $ids = implode(',', array_map('intval', $old_runs));
    $wpdb->query("DELETE FROM $profiles_table WHERE run_id IN ($ids)");
    $wpdb->query("DELETE FROM $runs_table WHERE id IN ($ids)");
}

$now = gmdate('Y-m-d H:i:s');
$wpdb->insert($runs_table, array(
    'run_uuid' => wp_generate_uuid4(),
    'blog_id' => 1,
    'run_type' => 'adhoc',
    'measurement_version' => 1,
    'trigger_source' => 'excimer-ui-test',
    'status' => 'done',
    'plugin_set' => wp_json_encode(array('components' => array())),
    'plugin_set_hash' => md5('excimer-ui-test'),
    'started' => $now,
    'finished' => $now,
));
$run_id = (int) $wpdb->insert_id;

$capture = array(
    'overview' => array(),
    'boot' => array(
        'segments' => array('render_and_output' => 67.2),
        // This is the exact condition from the screenshot: the phase has a wall-clock total,
        // but the wrapper-based render component list is empty.
        'render' => array('timed_ms' => 0, 'untimed_ms' => 67.2, 'components' => array(), 'top' => array()),
    ),
    // Shape produced by SSPA_Excimer::report(): valid phase samples exist independently of
    // boot.render.components, so the phase must not be rendered as a dead plain row.
    'profile' => array(
        'collector' => 'excimer',
        'period_ms' => 1.0,
        'samples' => 67,
        'wall_ms' => 67.0,
        'components' => array('synthetic-theme' => 52.0),
        'functions' => array(array(
            'fn' => 'synthetic_theme_render', 'component' => 'synthetic-theme',
            'self_ms' => 52.0, 'incl_ms' => 52.0, 'by' => array('synthetic-theme' => 52.0),
        )),
        'phases' => array('render_and_output' => array(
            'total_ms' => 52.0,
            'functions' => array(array('fn' => 'synthetic_theme_render', 'component' => 'synthetic-theme', 'self_ms' => 52.0)),
        )),
    ),
);

$wpdb->insert($profiles_table, array(
    'run_id' => $run_id,
    'page_key' => 'url-excimer-ui-test',
    'url' => home_url('/?excimer-ui-test=1'),
    'method' => 'GET',
    'variant' => 'guest',
    'plugin_set_hash' => '',
    'object_cache_mode' => 'normal',
    'samples' => wp_json_encode(array(array('wall_ms' => 70, 'code' => 200))),
    'ttfb_ms' => 70,
    'page_gen_ms' => 67.2,
    'sql_ms' => 0,
    'sql_count' => 0,
    'http_ms' => 0,
    'http_count' => 0,
    'peak_mem_bytes' => 1048576,
    'response_code' => 200,
    'profile_blob' => gzcompress(wp_json_encode($capture), 6),
    'created' => $now,
));
$profile_id = (int) $wpdb->insert_id;

$html = SSPA_Profile_Panel::render($profile_id, array('cached' => true));
sspa_excimer_ui_t(
    false !== strpos($html, 'class="sspa-adhoc-phase" data-phase="render_and_output"'),
    'Excimer phase samples make Template render + output expandable without wrapper detail'
);
sspa_excimer_ui_t(
    false !== strpos($html, 'class="sspa-adhoc-sub sspa-adhoc-fnsub" data-parent="render_and_output"')
    && false !== strpos($html, 'data-fnparent="render_and_output"')
    && false !== strpos($html, 'synthetic_theme_render'),
    'the sampled template function is a direct child opened by the phase control'
);

// The same real panel without its Excimer report must name the missing upgrade and link to
// the plugin's own server-specific installation instructions.
unset($capture['profile']);
$wpdb->update($profiles_table, array(
    'profile_blob' => gzcompress(wp_json_encode($capture), 6),
), array('id' => $profile_id));
$missing_html = SSPA_Profile_Panel::render($profile_id, array('cached' => true));
$tools_url = admin_url('admin.php?page=sspa#tools');
sspa_excimer_ui_t(
    false !== strpos($missing_html, 'Install Excimer to improve this data')
    && false !== strpos($missing_html, esc_url($tools_url)),
    'missing function data links to the Tools tab installation instructions'
);
sspa_excimer_ui_t(
    false !== strpos($missing_html, 'sspa-excimer-phases-prompt')
    && false !== strpos($missing_html, 'sspa-excimer-render-prompt')
    && false !== strpos($missing_html, 'sspa-excimer-functions-prompt'),
    'each panel area improved by Excimer carries the prompt where its detail is missing'
);
