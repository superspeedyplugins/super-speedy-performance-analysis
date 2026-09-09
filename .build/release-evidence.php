<?php
/** Execution-evidence engine adapted from the established plugin release gate.
 * Same commit/tree/dependency/log contract; PA uses opt-in collector coverage and has no private issue API.
 */
function sspa_gate_command( $root, array $command ) {
	$pipes = array();
	$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes, $root );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot run ' . $command[0] ); }
	fclose( $pipes[0] );
	$output = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$exit = proc_close( $process );
	if ( 0 !== $exit ) { throw new RuntimeException( implode( ' ', $command ) . ' failed: ' . trim( $output ) ); }
	return trim( $output );
}
function sspa_gate_git( $root, ...$args ) { return sspa_gate_command( $root, array_merge( array( 'git' ), $args ) ); }
function sspa_gate_snapshot( $root ) {
	if ( '' !== sspa_gate_git( $root, 'status', '--porcelain', '--untracked-files=all', '--ignore-submodules=none', '--', '.', ':(exclude).gate-metadata' ) ) {
		throw new RuntimeException( 'Commit tracked changes and clean submodules before recording or releasing.' );
	}
	$submodules = sspa_gate_git( $root, 'submodule', 'status', '--recursive' );
	if ( preg_match( '/^[+U-]/m', $submodules ) ) { throw new RuntimeException( 'Initialise submodules at their recorded commits.' ); }
	return array( 'head' => sspa_gate_git( $root, 'rev-parse', 'HEAD' ), 'tree' => sspa_gate_git( $root, 'rev-parse', 'HEAD^{tree}' ), 'submodules' => $submodules );
}
function sspa_gate_release_contract( $manifest ) {
	$release = $manifest['release'] ?? array();
	foreach ( array( 'core_bugs', 'sql_reviews', 'dependencies', 'tests' ) as $key ) {
		if ( ! isset( $release[ $key ] ) || ! is_array( $release[ $key ] ) ) { throw new RuntimeException( 'release.' . $key . ' must be an explicit array.' ); }
	}
	if ( empty( $release['tests'] ) ) { throw new RuntimeException( 'release.tests must execute focused and inactive/active collector checks.' ); }
	$coverage = array();
	$ids = array();
	foreach ( $release['tests'] as $test ) {
		if ( ! is_array( $test ) || ! preg_match( '/^[a-z0-9_-]+$/', $test['id'] ?? '' ) || isset( $ids[ $test['id'] ] ) || empty( $test['command'] ) || ! is_string( $test['command'] ) || ! is_array( $test['covers'] ?? null ) || empty( $test['required_output'] ) || ! is_array( $test['required_output'] ) ) { throw new RuntimeException( 'Invalid release test declaration.' ); }
		$ids[ $test['id'] ] = true;
		$coverage = array_merge( $coverage, $test['covers'] );
		foreach ( $test['required_output'] as $proof ) { if ( ! is_string( $proof ) || '' === trim( $proof ) ) { throw new RuntimeException( 'Required output must name a successful runtime assertion.' ); } }
	}
	foreach ( array( 'focused', 'collector_active', 'collector_inactive' ) as $required ) {
		if ( ! in_array( $required, $coverage, true ) ) { throw new RuntimeException( 'Missing executed coverage: ' . $required ); }
	}
	return $release;
}
/** Scope only a shared harness dependency; plugin source keeps the full clean snapshot. */
function sspa_gate_dependency_files( $root, $paths ) {
	if ( ! is_array( $paths ) || empty( $paths ) ) { throw new RuntimeException( 'Dependency paths must explicitly name tracked runtime files.' ); }
	$files = array();
	foreach ( $paths as $path ) {
		if ( ! is_string( $path ) || ! preg_match( '~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~', $path ) || in_array( '..', explode( '/', $path ), true ) || ! is_file( $root . '/' . $path ) || is_link( $root . '/' . $path ) ) { throw new RuntimeException( 'Invalid declared dependency file.' ); }
		$entry = sspa_gate_git( $root, 'ls-files', '--stage', '--', $path );
		if ( ! preg_match( '/^(100644|100755) ([0-9a-f]{40,64}) 0\t/', $entry, $match ) ) { throw new RuntimeException( 'Dependency runtime file must be tracked: ' . $path ); }
		if ( '' !== sspa_gate_git( $root, 'status', '--porcelain', '--', $path ) ) { throw new RuntimeException( 'Declared dependency code changed: ' . $path ); }
		$head_blob = sspa_gate_git( $root, 'rev-parse', 'HEAD:' . $path );
		$actual_blob = sspa_gate_git( $root, 'hash-object', '--no-filters', '--', $path );
		if ( $match[2] !== $head_blob || $actual_blob !== $head_blob ) { throw new RuntimeException( 'Declared dependency code changed: ' . $path ); }
		$files[ $path ] = array( 'blob' => $head_blob, 'sha256' => hash_file( 'sha256', $root . '/' . $path ), 'mode' => $match[1] );
	}
	ksort( $files );
	return array( 'head' => sspa_gate_git( $root, 'rev-parse', 'HEAD' ), 'files' => $files );
}
function sspa_gate_dependencies( $release ) {
	$result = array();
	foreach ( $release['dependencies'] as $dependency ) {
		if ( ! preg_match( '/^[A-Z][A-Z0-9_]+$/', $dependency['environment'] ?? '' ) || ! preg_match( '/^[a-z0-9_-]+$/', $dependency['id'] ?? '' ) ) { throw new RuntimeException( 'Invalid release dependency declaration.' ); }
		$path = getenv( $dependency['environment'] );
		if ( ! $path || ! is_dir( $path ) ) { throw new RuntimeException( 'Set ' . $dependency['environment'] . ' to the tested dependency repository.' ); }
		$result[ $dependency['id'] ] = array_key_exists( 'paths', $dependency ) ? sspa_gate_dependency_files( $path, $dependency['paths'] ) : sspa_gate_snapshot( $path );
	}
	return $result;
}
function sspa_gate_runtime( $root, $release ) {
	$result = array();
	$binding = sspa_gate_command( $root, array( 'bash', '-c', 'source .tests/env.sh; printf "%s\n%s" "$SSPA_SITE_DIR" "$PD_LIB"' ) );
	$binding = explode( "\n", $binding );
	if ( count( $binding ) !== 2 || realpath( $binding[0] ) !== realpath( getenv( 'SSPA_GATE_SITE_PATH' ) ?: '' ) || realpath( $binding[1] ) !== realpath( ( getenv( 'SSPA_GATE_PARALLEL_DEV_ROOT' ) ?: '' ) . '/bin/lib.sh' ) ) { throw new RuntimeException( 'Recorded site/harness must match the existing test runner configuration.' ); }

	if ( ! is_array( $release['runtime_sites'] ?? null ) ) { throw new RuntimeException( 'release.runtime_sites must explicitly declare the tested sites.' ); }
	foreach ( $release['runtime_sites'] as $variable ) {
		if ( ! is_string( $variable ) || ! preg_match( '/^[A-Z][A-Z0-9_]+$/', $variable ) || ! getenv( $variable ) ) { throw new RuntimeException( 'Set the dedicated runtime site path: ' . $variable ); }
		$path = realpath( getenv( $variable ) );
		if ( ! $path ) { throw new RuntimeException( 'Runtime site does not exist: ' . $variable ); }
		$probe = 'global $wp_version; require_once ABSPATH . "wp-admin/includes/plugin.php"; $versions = array(); foreach ( get_plugins() as $file => $plugin ) { $versions[$file] = $plugin["Version"]; } ksort($versions); echo json_encode(array("site" => realpath(ABSPATH), "spro" => realpath(WP_PLUGIN_DIR . "/scalability-pro"), "pa" => realpath(WP_PLUGIN_DIR . "/super-speedy-performance-analysis"), "wordpress" => $wp_version, "plugins" => $versions, "php" => PHP_VERSION));';
		$data = json_decode( sspa_gate_command( $root, array( 'wp', '--path=' . $path, '--skip-plugins', '--skip-themes', 'eval', $probe ) ), true );
		if ( ! is_array( $data ) || ( $data['site'] ?? '' ) !== $path || ( $data['pa'] ?? '' ) !== realpath( $root ) ) { throw new RuntimeException( 'Runtime site must execute this exact PA checkout: ' . $variable ); }
		if ( ! empty( $data['spro'] ) && $data['spro'] !== realpath( getenv( 'SSPA_GATE_SPRO_ROOT' ) ?: '' ) ) { throw new RuntimeException( 'Runtime companion does not match SSPA_GATE_SPRO_ROOT.' ); }
		$result[ $variable ] = $data;
	}
	return $result;
}
function sspa_gate_record( $root, $manifest, $manifest_path ) {
	$release = sspa_gate_release_contract( $manifest );
	$dir = $root . '/.data/release-gate';
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0775, true ) ) { throw new RuntimeException( 'Cannot create release evidence directory.' ); }
	$file = $dir . '/evidence.json';
	// Invalidate earlier success before any command, including commands that fail or are interrupted.
	if ( is_file( $file ) && ! unlink( $file ) ) { throw new RuntimeException( 'Cannot invalidate previous release evidence.' ); }
	$snapshot = sspa_gate_snapshot( $root );
	$dependencies = sspa_gate_dependencies( $release );
	sspa_gate_runtime( $root, $release );
	$results = array();
	foreach ( $release['tests'] as $test ) {
		fwrite( STDOUT, 'Running ' . $test['id'] . ': ' . $test['command'] . PHP_EOL );
		$log = $dir . '/' . $test['id'] . '.log';
		$pipes = array();
		$process = proc_open( array( 'bash', '-o', 'pipefail', '-c', $test['command'] ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $log, 'w' ), 2 => array( 'redirect', 1 ) ), $pipes, $root );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start ' . $test['id'] ); }
		fclose( $pipes[0] );
		$exit = proc_close( $process );
		if ( 0 !== $exit ) { throw new RuntimeException( $test['id'] . ' failed with exit ' . $exit . '; see ' . $log ); }
		$contents = file_get_contents( $log );
		foreach ( $test['required_output'] as $proof ) { if ( false === strpos( $contents, $proof ) ) { throw new RuntimeException( $test['id'] . ' did not execute its required assertion: ' . $proof ); } }
		$results[] = array( 'id' => $test['id'], 'command' => $test['command'], 'covers' => $test['covers'], 'required_output' => $test['required_output'], 'exit' => $exit, 'log_sha256' => hash_file( 'sha256', $log ) );
	}
	if ( $snapshot !== sspa_gate_snapshot( $root ) || $dependencies !== sspa_gate_dependencies( $release ) ) { throw new RuntimeException( 'Source or dependency changed during the tests.' ); }
	$evidence = array( 'schema' => 1, 'source' => $snapshot, 'dependencies' => $dependencies, 'runtime' => sspa_gate_runtime( $root, $release ), 'manifest_sha256' => hash_file( 'sha256', $root . '/' . $manifest_path ), 'recorded_at' => gmdate( 'c' ), 'results' => $results );
	if ( false === file_put_contents( $file, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL ) ) { throw new RuntimeException( 'Cannot write release evidence.' ); }
	fwrite( STDOUT, 'Executed evidence recorded for ' . $snapshot['head'] . '. SQL and release approval have not been granted.' . PHP_EOL );
}
function sspa_gate_remote_repository( $root ) {
	$url = sspa_gate_git( $root, 'config', '--get', 'remote.origin.url' );
	if ( preg_match( '~(?:github\.com[:/])([^/]+/[^/]+?)(?:\.git)?$~', $url, $m ) ) { return $m[1]; }
	throw new RuntimeException( 'Release requires a verified GitHub origin.' );
}
function sspa_gate_strict( $root, $manifest, $manifest_path, $metadata_root ) {
	$release = sspa_gate_release_contract( $manifest );
	$source = sspa_gate_snapshot( $root );
	$evidence_path = $root . '/.data/release-gate/evidence.json';
	$evidence = is_file( $evidence_path ) ? json_decode( file_get_contents( $evidence_path ), true ) : null;
	if ( ! is_array( $evidence ) || 1 !== ( $evidence['schema'] ?? null ) || ( $evidence['source'] ?? null ) !== $source || ( $evidence['dependencies'] ?? null ) !== sspa_gate_dependencies( $release ) || ( $evidence['manifest_sha256'] ?? '' ) !== hash_file( 'sha256', $root . '/' . $manifest_path ) ) { throw new RuntimeException( 'Missing or stale executed evidence for current commit/source/dependencies. Run --record-tests.' ); }
	if ( count( $evidence['results'] ?? array() ) !== count( $release['tests'] ) ) { throw new RuntimeException( 'Missing test results.' ); }
	foreach ( $release['tests'] as $index => $test ) {
		$result = $evidence['results'][ $index ] ?? array();
		$log = $root . '/.data/release-gate/' . $test['id'] . '.log';
		if ( ( $result['id'] ?? '' ) !== $test['id'] || ( $result['command'] ?? '' ) !== $test['command'] || ( $result['covers'] ?? null ) !== $test['covers'] || ( $result['required_output'] ?? null ) !== $test['required_output'] || 0 !== ( $result['exit'] ?? null ) || ! is_file( $log ) || ( $result['log_sha256'] ?? '' ) !== hash_file( 'sha256', $log ) ) { throw new RuntimeException( 'Missing, failed or changed evidence: ' . $test['id'] ); }
	}
	if ( ( $evidence['runtime'] ?? null ) !== sspa_gate_runtime( $root, $release ) ) { throw new RuntimeException( 'Test runtime or plugin versions changed; record tests again.' ); }
	$core = $manifest['core_branch'];
	if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $core ) ) { throw new RuntimeException( 'Invalid core ref.' ); }
	sspa_gate_git( $root, 'fetch', '--quiet', 'origin', '+refs/heads/' . $core . ':refs/remotes/origin/' . $core );
	sspa_gate_git( $root, 'merge-base', '--is-ancestor', 'refs/remotes/origin/' . $core, $source['head'] );
	if ( '' !== sspa_gate_git( $root, 'rev-list', '--merges', 'refs/remotes/origin/' . $core . '..' . $source['head'] ) ) { throw new RuntimeException( 'Feature contains merge commits; rebase onto current core.' ); }
	// This plugin is public. Private bug evidence belongs in its owner's private repository.
	if ( ! empty( $release['core_bugs'] ) ) { throw new RuntimeException( 'Private core issue declarations are not supported in this public repository.' ); }
	if ( ! $metadata_root || ! is_dir( $metadata_root ) ) { throw new RuntimeException( 'Set SSPA_GATE_METADATA_ROOT to the private plan repository.' ); }
	if ( sspa_gate_remote_repository( $metadata_root ) !== $manifest['plan']['repository'] ) { throw new RuntimeException( 'Wrong metadata repository.' ); }
	$metadata = sspa_gate_snapshot( $metadata_root );
	$ref = $manifest['plan']['ref'];
	if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $ref ) ) { throw new RuntimeException( 'Invalid metadata ref.' ); }
	sspa_gate_git( $metadata_root, 'fetch', '--quiet', 'origin', '+refs/heads/' . $ref . ':refs/remotes/origin/' . $ref );
	if ( $metadata['head'] !== sspa_gate_git( $metadata_root, 'rev-parse', 'refs/remotes/origin/' . $ref ) ) { throw new RuntimeException( 'Metadata checkout is not current ' . $ref . '.' ); }
	foreach ( array_merge( array( $manifest['plan']['path'] ), $release['sql_reviews'] ) as $path ) {
		if ( ! is_string( $path ) || false !== strpos( $path, '..' ) || '/' === substr( $path, 0, 1 ) || ! is_file( $metadata_root . '/' . $path ) ) { throw new RuntimeException( 'Missing plan/review: ' . $path ); }
	}
	foreach ( $release['sql_reviews'] as $path ) {
		$body = file_get_contents( $metadata_root . '/' . $path );
		foreach ( array( 'SQL-Approval: approved', 'SQL-Approved-By: Dave Hilditch', 'SQL-Approved-Commit: ' . $source['head'] ) as $line ) {
			if ( ! preg_match( '/^' . preg_quote( $line, '/' ) . '$/m', $body ) ) { throw new RuntimeException( 'SQL approval pending for current commit: ' . $path . ' (requires ' . $line . ')' ); }
		}
	}
	fwrite( STDOUT, 'Release checks passed for ' . $source['head'] . '.' . PHP_EOL );
}
