<?php
$database = dirname( __DIR__, 3 ) . '/.data/e2e-observatory/observatory.sqlite';

if ( isset( $_GET['api'] ) ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if ( ! file_exists( $database ) ) {
		http_response_code( 404 );
		echo json_encode( array( 'error' => 'No observatory database exists yet.' ) );
		exit;
	}
	$db = new PDO( 'sqlite:' . $database, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
	$api = (string) $_GET['api'];
	if ( 'runs' === $api ) {
		$rows = $db->query( 'SELECT id, plugin_slug, started_at, finished_at, status FROM runs ORDER BY started_at DESC LIMIT 100' )->fetchAll( PDO::FETCH_ASSOC );
		echo json_encode( $rows );
		exit;
	}
	if ( ! in_array( $api, array( 'samples', 'faults', 'features' ), true ) ) {
		http_response_code( 400 );
		echo json_encode( array( 'error' => 'Unknown API operation.' ) );
		exit;
	}
	$run = isset( $_GET['run'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $_GET['run'] ) : '';
	if ( '' === $run ) {
		$run = (string) $db->query( 'SELECT id FROM runs ORDER BY started_at DESC LIMIT 1' )->fetchColumn();
	}
	if ( 'samples' === $api ) {
		$stmt = $db->prepare(
			'SELECT s.*, t.feature_id, t.area, t.page_type, t.path, t.site_id, t.role, si.url AS site_url, si.plugin_version, si.theme AS theme_json
			 FROM samples s JOIN targets t ON t.run_id=s.run_id AND t.id=s.target_id
			 JOIN sites si ON si.run_id=s.run_id AND si.id=t.site_id
			 WHERE s.run_id=:run ORDER BY t.area,t.id,s.sequence'
		);
	} elseif ( 'faults' === $api ) {
		$stmt = $db->prepare(
			'SELECT f.*, t.feature_id, t.area, t.page_type, t.site_id
			 FROM faults f JOIN targets t ON t.run_id=f.run_id AND t.id=f.target_id
			 WHERE f.run_id=:run ORDER BY f.severity,f.target_id,f.id'
		);
	} else {
		$stmt = $db->prepare(
			'SELECT rf.feature_id, COUNT(s.id) AS attempts, COALESCE(SUM(s.valid),0) AS valid_samples,
			 ROUND(AVG(CASE WHEN s.valid=1 THEN s.php_wall_ms END),3) AS mean_php_ms,
			 COALESCE(SUM(s.fault_error_count),0) AS errors, COALESCE(SUM(s.fault_warning_count),0) AS warnings,
			 COALESCE(SUM(s.fault_notice_count),0) AS notices, COUNT(DISTINCT t.id) AS targets
			 FROM run_features rf LEFT JOIN targets t ON t.run_id=rf.run_id AND t.feature_id=rf.feature_id
			 LEFT JOIN samples s ON s.run_id=t.run_id AND s.target_id=t.id
			 WHERE rf.run_id=:run GROUP BY rf.feature_id ORDER BY rf.feature_id'
		);
	}
	$stmt->execute( array( ':run' => $run ) );
	echo json_encode( array( 'run' => $run, 'rows' => $stmt->fetchAll( PDO::FETCH_ASSOC ) ) );
	exit;
}
?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Super Speedy E2E observatory</title>
	<link rel="stylesheet" href="app.css">
</head>
<body>
	<header>
		<div>
			<p class="eyebrow">Local development evidence</p>
			<h1>E2E observatory</h1>
			<p>PHP speed and faults across pages and features. Every dot is one request.</p>
		</div>
		<label>Run <select id="run"></select></label>
	</header>
	<main>
		<section class="summary" id="summary"></section>
		<section class="panel">
			<h2>Front-end pages</h2>
			<div class="chart" id="frontend-chart"></div>
		</section>
		<section class="panel">
			<h2>Back-end pages</h2>
			<div class="chart" id="backend-chart"></div>
		</section>
		<section class="panel">
			<h2>Features</h2>
			<p class="hint">Speed and faults stay separate. There is no combined health score.</p>
			<div class="chart" id="feature-chart"></div>
		</section>
		<section class="panel">
			<h2>Faults</h2>
			<div id="faults"></div>
		</section>
	</main>
	<div id="tooltip" role="status"></div>
	<script src="app.js"></script>
</body>
</html>
