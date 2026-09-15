<?php
// Exercise the production comparison interface without WordPress, a database or rendering.
define('ABSPATH', __DIR__);
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_json_encode($v) { return json_encode($v); }
require dirname(__DIR__) . '/includes/class-sspa-profile-store.php';
$domain = dirname(__DIR__) . '/includes/class-sspa-history-comparison.php';
if (!is_file($domain)) { fwrite(STDERR, "FAIL: independent History comparison interface is missing\n"); exit(1); }
require $domain;
function check_history($ok, $label) { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
function history_snapshot($id, $time, $code = 200, $signature = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa') {
    $page = array('page_key'=>'home', 'variant'=>'anon', 'method'=>'GET', 'object_cache_mode'=>'normal',
        'generation_ms'=>$time, 'ttfb_ms'=>$time, 'sql_ms'=>2, 'sql_count'=>3, 'rows_fetched'=>4,
        'http_ms'=>0, 'php_ms'=>5, 'peak_mem_bytes'=>100, 'duplicate_queries'=>0, 'mail_count'=>0,
        'response_code'=>$code, 'blocked_by'=>'', 'output_signature'=>$signature);
    return array('identity'=>array('id'=>$id, 'components'=>array(array('type'=>'plugin','slug'=>'fixture','version'=>'1.0')),
        'component_state'=>array()), 'pages'=>array('home|GET|anon|normal'=>$page),
        'diagnostics'=>array('fatals'=>0,'transport_errors'=>0,'http_errors'=>0,'warnings'=>0,'critical_findings'=>0));
}
try {
    $before = history_snapshot(1, 100); $after = history_snapshot(2, 150);
    $expected = array('home|GET|anon|normal'=>array('output_signature'=>str_repeat('a',32),'response_code'=>200,'source_run_uuid'=>''));
    $doc = SSPA_History_Comparison::compare($before,$after,$expected);
    check_history($doc['headline']['delta'] === 50.0 && $doc['headline']['percent'] === 50.0, 'measured headline delta and percentage');
    check_history($doc['status']==='observed' && $doc['pages'][0]['declared']['state']==='pass', 'matching approved response passes');
    $after['pages']['home|GET|anon|normal']['response_code']=503;
    $doc=SSPA_History_Comparison::compare($before,$after,$expected);
    check_history($doc['status']==='attention' && $doc['pages'][0]['declared']['signature_state']==='pass' && $doc['pages'][0]['declared']['response_code']['state']==='fail', 'HTTP expectation fails independently of body signature');
    $after['pages']['home|GET|anon|normal']['response_code']=null;
    $doc=SSPA_History_Comparison::compare($before,$after,$expected);
    check_history($doc['pages'][0]['declared']['state']==='unknown', 'missing response code stays unknown');
    $after=history_snapshot(2,150,200,str_repeat('b',32));
    $doc=SSPA_History_Comparison::compare($before,$after,array());
    check_history($doc['status']==='review' && $doc['summary']['output_changes']===1, 'unapproved body change requests review');
    $doc=SSPA_History_Comparison::compare($before,$after,$expected);
    check_history($doc['status']==='attention' && $doc['pages'][0]['declared']['response_code']['state']==='pass', 'approved body mismatch needs attention');
    $after=history_snapshot(2,0);
    $after['pages']['home|GET|anon|normal']['ttfb_ms']=null;
    $doc=SSPA_History_Comparison::compare($before,$after,array());
    check_history($doc['headline']['delta']===null && $doc['summary']['headline_pages']===0, 'missing headline evidence is not zero');
    check_history($doc['pages'][0]['metrics']['generation_ms']['after']===0.0, 'zero measurement is retained');
    $after=history_snapshot(2,150);
    $after['pages']['home|POST|anon|normal']=$after['pages']['home|GET|anon|normal'];
    $after['pages']['home|POST|anon|normal']['method']='POST';
    unset($after['pages']['home|GET|anon|normal']);
    $doc=SSPA_History_Comparison::compare($before,$after,array());
    check_history(count($doc['pages'])===2 && $doc['summary']['headline_pages']===0, 'distinct scenarios never share measurements');
    $after=history_snapshot(2,150);
    $after['identity']['components'][0]['version']='2.0';
    $doc=SSPA_History_Comparison::compare($before,$after,array('home|anon'=>$expected['home|GET|anon|normal']));
    check_history(count($doc['setup_changes'])===1 && $doc['setup_changes'][0]['after_version']==='2.0', 'component version changes retained');
    check_history($doc['pages'][0]['declared']['state']==='pass', 'legacy declared expectation retained');
    $after['identity']['components']=array();
    $doc=SSPA_History_Comparison::compare($before,$after,array());
    check_history($doc['setup_changes_available']===false, 'absent component inventory stays unavailable');
    echo "PASS: History domain contract\n";
} catch (Throwable $e) { fwrite(STDERR,'FAIL: '.$e->getMessage()."\n"); exit(1); }
