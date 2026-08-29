<?php
$configured_database = getenv( 'SSPA_OBSERVATORY_DATABASE' );
$database            = $configured_database ? $configured_database : dirname( __DIR__, 3 ) . '/.data/e2e-observatory/observatory.sqlite';

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
			'SELECT s.*, t.feature_id, t.area, t.page_type, t.path, t.site_id, t.role, si.url AS site_url, si.plugin_version, si.theme AS theme_json, si.characteristics_json
			 FROM samples s JOIN targets t ON t.run_id=s.run_id AND t.id=s.target_id
			 JOIN sites si ON si.run_id=s.run_id AND si.id=t.site_id
			 WHERE s.run_id=:run ORDER BY t.area,t.id,s.sequence'
		);
	} elseif ( 'faults' === $api ) {
		$stmt = $db->prepare(
			'SELECT f.*, t.feature_id, t.area, t.page_type, t.site_id, si.plugin_version, si.characteristics_json
			 FROM faults f JOIN targets t ON t.run_id=f.run_id AND t.id=f.target_id
			 JOIN sites si ON si.run_id=f.run_id AND si.id=t.site_id
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
			<div class="explorer-heading">
				<div>
					<nav class="breadcrumb" id="breadcrumb" aria-label="Chart level"></nav>
					<h2 id="explorer-title">Feature overview</h2>
				</div>
				<div class="area-switch" aria-label="Request area">
					<button type="button" data-area="all">All</button>
					<button type="button" data-area="frontend">Front end</button>
					<button type="button" data-area="backend">Back end</button>
				</div>
			</div>
			<p>Every point is one request. Click a feature along the bottom to open its key pages.</p>
			<div class="keys" aria-label="Chart keys">
				<div class="key-group"><strong>Build/version</strong><div id="build-key"></div></div>
				<div class="key-group"><strong>Point state</strong><div id="state-key"></div><button type="button" id="clear-filters">Clear filters</button></div>
			</div>
			<div class="chart" id="explorer-chart"></div>
			<div class="evidence-tools">
				<p id="evidence-heading">Select a feature, page or request to inspect its evidence.</p>
				<div class="evidence-actions">
					<label for="evidence-sort">Order</label>
					<select id="evidence-sort">
						<option value="plot">Plot order</option>
						<option value="errors">Errors first</option>
						<option value="slowest">Slowest first</option>
						<option value="fastest">Fastest first</option>
						<option value="feature">Feature A-Z</option>
						<option value="version">Version then page</option>
						<option value="recorded">Recorded order</option>
					</select>
					<button type="button" id="clear-selection">Clear selection</button>
				</div>
			</div>
			<div class="evidence-list" id="evidence"></div>
		</section>
		<section class="panel">
			<h2>All faults in this execution</h2>
			<div id="faults"></div>
		</section>
	</main>
	<div class="sr-only" id="live" aria-live="polite"></div>
	<div id="tooltip" role="status"></div>
	<div class="point-chooser" id="point-chooser" hidden></div>
	<script type="module" src="app.js"></script>
</body>
</html>
