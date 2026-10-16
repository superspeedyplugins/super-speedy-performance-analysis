<?php
require __DIR__ . '/120-actionable-summary.php';
update_option('sspa_summary_first_cost', 1, true);
file_put_contents(ABSPATH . 'summary-sample-counter', '0');
wp_cache_flush();
try {
    // A two-second known first cost clears the production ratio threshold even
    // when retained-suite CPU load shifts ordinary requests by hundreds of ms.
    // Keep the original >600 ms assertion and the actual report flag assertion.
    $run = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'user_id' => 1, 'all_hooks' => true));
    if (is_wp_error($run)) throw new RuntimeException($run->get_error_message());
    sspa_fleet_complete($run);
    $profile = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d ORDER BY id DESC LIMIT 1', SSPA_Schema::table('profiles'), $run), ARRAY_A);
    $samples = json_decode($profile['samples'], true);
    sspa_fleet_assert($samples[0]['gen_ms'] - max($samples[1]['gen_ms'], $samples[2]['gen_ms']) > 600, 'real first measured request alone pays the planted cold cost');
    $built = SSPA_Markdown_Export::build('page', (int) $profile['id']);
    sspa_fleet_assert(strpos($built['markdown'], 'First-sample-only cost observed') !== false, 'sample report flags first-only cost without claiming its cause');
    file_put_contents($out . 'first-sample.md', $built['markdown']);
    file_put_contents($out . 'first-sample.json', wp_json_encode($samples, JSON_PRETTY_PRINT));
} finally {
    update_option('sspa_summary_first_cost', 0, true);
    wp_cache_flush();
}
sspa_fleet_done();
