<?php
require SSPA_PLUGIN_DIR . '.tests/lib/fleet.php';
$crawler = new SSPA_Crawler();
$rows = array();
foreach (array('none','selected','all','all_streams','selected','none','all','all_streams') as $mode) {
    $result = $crawler->profile_job(array('page_key'=>'summary-overhead','url'=>home_url('/summary-fixture/'),'variant'=>'anon','flags'=>array('ah'=>$mode === 'all_streams' ? 'all' : $mode,'xp'=>'0','rs'=>$mode === 'all_streams' ? '1' : '0'),'samples'=>3), 1);
    foreach ($result['samples'] as $sample) {
        sspa_fleet_assert(!empty($sample['capture']['boot']) && $sample['code'] === 200, 'overhead request captured for ' . $mode);
        if (empty($sample['capture'])) throw new RuntimeException('Missing overhead capture');
        $c = $sample['capture'];
        $rows[] = array('mode'=>$mode,'generation_ms'=>$c['overview']['gen_ms'],'response_wall_ms'=>$sample['wall_ms'],'cpu'=>$c['boot']['cpu'],'callback_count'=>$c['boot']['callback_count'], 'diagnostics'=>$c['php_diagnostics']['count'], 'excimer_sampling'=>!empty($c['profile']));
    }
}
file_put_contents(SSPA_PLUGIN_DIR . '.data/summary/overhead.json',wp_json_encode($rows, JSON_PRETTY_PRINT));
sspa_fleet_done();
