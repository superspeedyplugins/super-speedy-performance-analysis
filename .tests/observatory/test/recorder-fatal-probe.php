<?php
define( 'SSPA_OBSERVATORY_SPOOL', getenv( 'OBS_SPOOL' ) );
define( 'SSPA_OBSERVATORY_SITE_ID', 'recorder-test' );
define( 'SSPA_OBSERVATORY_SECRET', getenv( 'OBS_SECRET' ) );
$_SERVER['HTTP_X_SSPA_OBSERVATORY']       = getenv( 'OBS_ID' );
$_SERVER['HTTP_X_SSPA_OBSERVATORY_TOKEN'] = getenv( 'OBS_TOKEN' );
$_SERVER['REQUEST_METHOD']                 = 'GET';
$_SERVER['HTTP_HOST']                      = 'recorder.test';
$_SERVER['REQUEST_URI']                    = '/fatal-probe';
$_SERVER['REQUEST_TIME_FLOAT']             = microtime( true );
require dirname( __DIR__ ) . '/recorder/sspa-e2e-observatory.php';
throw new TypeError( 'Deliberate observatory fatal probe' );
