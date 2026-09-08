<?php
/** Standalone contract tests. Real git/validator/test commands; WP discovery is stubbed; executed test commands and validator are real. */
$source = dirname( __DIR__, 2 );
$base = $source . '/.data/release-gate-fixtures/' . bin2hex( random_bytes( 6 ) );
mkdir( $base, 0775, true );
function command( $cwd, $argv, $expected = 0 ) {
	$p = proc_open( $argv, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes, $cwd );
	fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $code = proc_close( $p );
	if ( ( 0 === $expected && 0 !== $code ) || ( 0 !== $expected && 0 === $code ) ) { throw new RuntimeException( 'Unexpected exit ' . $code . ': ' . implode( ' ', $argv ) . "\n" . $out ); }
	return trim( $out );
}
function git( $root, ...$args ) { return command( $root, array_merge( array( 'git' ), $args ) ); }
function commit( $root, $message ) { git( $root, 'add', '.' ); git( $root, 'commit', '-qm', $message ); }
function gate( $root, $mode = '', $failure = false ) {
	$args = array( PHP_BINARY, '.build/validate-feature-release-gate.php' ); if ( $mode ) { $args[] = $mode; }
	return command( $root, $args, $failure ? 1 : 0 );
}
function expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo 'PASS: ' . $message . "\n"; }
try {
	$root = $base . '/plugin'; $meta = $base . '/metadata'; $bin = $base . '/bin'; $dependency = $base . '/dependency';
	foreach ( array( $root, $meta, $bin, $dependency ) as $dir ) { mkdir( $dir ); }
	foreach ( array( $root, $meta, $dependency ) as $dir ) { git( $dir, 'init', '-q', '-b', 'main' ); git( $dir, 'config', 'user.name', 'Synthetic test' ); git( $dir, 'config', 'user.email', 'test@example.invalid' ); }
	file_put_contents( $dependency . '/library.php', '<?php // dependency' ); file_put_contents( $dependency . '/README.md', 'Dependency docs' ); commit( $dependency, 'dependency' ); putenv( 'SPRO_TEST_DEPENDENCY=' . $dependency );
	mkdir( $root . '/.data' ); mkdir( $root . '/.build' ); mkdir( $root . '/.github' );
	foreach ( array( 'release-evidence.php', 'validate-feature-release-gate.php' ) as $file ) { copy( $source . '/.build/' . $file, $root . '/.build/' . $file ); }
	foreach ( array( 'build.sh', 'build-full.sh', 'build-wporg.sh' ) as $file ) { copy( $source . '/.build/' . $file, $root . '/.build/' . $file ); }
	file_put_contents( $root . '/.build/lib.sh', "ssx_build_target() { echo reached > .data/stage-reached; }\nssx_log() { :; }\n" );
	if ( getenv( 'SSPA_GATE_TEST_OLD_BUILD' ) ) { copy( getenv( 'SSPA_GATE_TEST_OLD_BUILD' ), $root . '/.build/build.sh' ); }
	if ( getenv( 'SPRO_GATE_TEST_OLD_CLI' ) ) { copy( getenv( 'SPRO_GATE_TEST_OLD_CLI' ), $root . '/.build/validate-feature-release-gate.php' ); }
	file_put_contents( $root . '/.gitignore', ".data/\n" );
	file_put_contents( $root . '/guard.php', "<?php // SPRO_BETA_GUARD: fixture\n" );
	file_put_contents( $root . '/runtime.php', '<?php file_put_contents(".data/executed", "enabled and disabled", FILE_APPEND); if (getenv("SPRO_TEST_FAIL")) { exit(7); } if (!getenv("SPRO_TEST_NO_PROOF")) { echo "PASS: enabled\nPASS: disabled\n"; }' );
	$m = json_decode( file_get_contents( $source . '/.github/feature-release-gate.json' ), true );
	$m['plan'] = array( 'repository_environment' => 'SSPA_GATE_METADATA_REPOSITORY', 'ref' => 'main', 'path' => 'plan.md' );

	$m['release'] = array( 'runtime_sites' => array( 'SPRO_TEST_SITE_PATH' ), 'core_bugs' => array(), 'sql_reviews' => array( 'sql.md' ), 'dependencies' => array( array( 'id' => 'fixture', 'environment' => 'SPRO_TEST_DEPENDENCY', 'paths' => array( 'library.php' ) ) ), 'tests' => array( array( 'id' => 'runtime', 'command' => PHP_BINARY . ' runtime.php', 'covers' => array( 'focused', 'collector_active', 'collector_inactive' ), 'required_output' => array( 'PASS: enabled', 'PASS: disabled' ) ) ) );
	file_put_contents( $root . '/.github/feature-release-gate.json', json_encode( $m ) );
	commit( $root, 'synthetic core' );
	git( $base, 'clone', '-q', '--bare', $root, $base . '/origin.git' );
	git( $root, 'remote', 'add', 'origin', 'https://github.com/example/plugin.git' );
	git( $root, 'config', 'url.' . $base . '/origin.git.insteadOf', 'https://github.com/example/plugin.git' );
	git( $root, 'checkout', '-qb', 'feature' );
	file_put_contents( $root . '/feature.txt', "feature\n" ); commit( $root, 'feature change' );
	$sha = git( $root, 'rev-parse', 'HEAD' );
	file_put_contents( $meta . '/plan.md', "Synthetic plan\n" );
	file_put_contents( $meta . '/sql.md', "SQL-Approval: pending\n" ); commit( $meta, 'pending SQL' );
	git( $base, 'clone', '-q', '--bare', $meta, $base . '/meta-origin.git' );
	git( $meta, 'remote', 'add', 'origin', 'https://github.com/example/metadata.git' );
	git( $meta, 'config', 'url.' . $base . '/meta-origin.git.insteadOf', 'https://github.com/example/metadata.git' );
	file_put_contents( $bin . '/gh', "#!/usr/bin/env php\n<?php if (\$argv[1] === 'repo') { echo json_encode(['isPrivate'=>getenv('SPRO_TEST_PUBLIC') ? false : true]); } else { echo json_encode(['state'=>getenv('SPRO_TEST_OPEN') ? 'OPEN' : 'CLOSED','body'=>'Affected core version: Reproduction: Red test: Green result: Exposed by:']); }\n" ); chmod( $bin . '/gh', 0755 );
	mkdir( $root . '/.tests' ); mkdir( $dependency . '/bin' ); file_put_contents( $dependency . '/bin/lib.sh', '# synthetic runtime binding' ); commit( $dependency, 'runtime binding' );
	file_put_contents( $root . '/.tests/env.sh', 'SSPA_SITE_DIR=' . escapeshellarg( $base . '/site' ) . "\nPD_LIB=" . escapeshellarg( $dependency . '/bin/lib.sh' ) . "\n" ); commit( $root, 'runtime binding' );
	$sha = git( $root, 'rev-parse', 'HEAD' );
	mkdir( $base . '/site' ); putenv( 'SSPA_GATE_SITE_PATH=' . $base . '/site' ); putenv( 'SSPA_GATE_PARALLEL_DEV_ROOT=' . $dependency ); putenv( 'SPRO_TEST_SITE_PATH=' . $base . '/site' ); putenv( 'SPRO_TEST_PLUGIN_ROOT=' . $root );
	file_put_contents( $bin . '/wp', "#!/usr/bin/env php\n<?php echo json_encode(['site'=>getenv('SPRO_TEST_SITE_PATH'),'pa'=>getenv('SPRO_TEST_WRONG_CHECKOUT') ? '/wrong/checkout' : getenv('SPRO_TEST_PLUGIN_ROOT'),'spro'=>false,'wordpress'=>'6.9','php'=>PHP_VERSION,'plugins'=>['fixture.php'=>getenv('SPRO_TEST_PLUGIN_VERSION') ?: '1.0']]);\n" ); chmod( $bin . '/wp', 0755 );
	putenv( 'PATH=' . $bin . ':' . getenv( 'PATH' ) ); putenv( 'SSPA_GATE_METADATA_ROOT=' . $meta ); putenv( 'SSPA_GATE_METADATA_REPOSITORY=example/metadata' );
	expect( false !== strpos( gate( $root, '', true ), 'Missing or stale' ), 'default validator rejects missing executed evidence' );
	foreach ( array( 'full', 'wporg' ) as $edition ) { command( $root, array( 'bash', '.build/build-' . $edition . '.sh', '.data/unused-output' ), 1 ); expect( ! is_file( $root . '/.data/stage-reached' ), $edition . ' existing build stops before staging without executed evidence' ); }
	gate( $root, '--manifest-only' );
	gate( $root, '--record-tests' );
	expect( file_get_contents( $root . '/.data/executed' ) === 'enabled and disabled', 'record mode actually executes declared command' );
	expect( false !== strpos( gate( $root, '', true ), 'SQL approval pending' ), 'recorded passing tests cannot bypass pending SQL approval' );
	file_put_contents( $meta . '/sql.md', "SQL-Approval: approved\nSQL-Approved-By: Dave Hilditch\nSQL-Approved-Commit: $sha\n" ); commit( $meta, 'synthetic approval' ); git( $meta, 'push', '-q', 'origin', 'main' );
	file_put_contents( $dependency . '/README.md', 'Unrelated documentation edit' ); file_put_contents( $dependency . '/unused-script.ps1', '# unused' );
	gate( $root ); echo "PASS: unrelated dependency documentation and unused files do not invalidate evidence\n";
	file_put_contents( $dependency . '/library.php', '<?php // uncommitted runtime change' ); expect( false !== strpos( gate( $root, '', true ), 'Declared dependency code changed' ), 'dirty declared runtime code is rejected' ); git( $dependency, 'checkout', '--', 'library.php' );
	gate( $root ); echo "PASS: current evidence, core and SQL approval pass\n";
	file_put_contents( $dependency . '/library.php', '<?php // changed dependency' ); commit( $dependency, 'dependency changed' ); expect( false !== strpos( gate( $root, '', true ), 'Missing or stale' ), 'dependency commit changes invalidate evidence' ); gate( $root, '--record-tests' );
	putenv( 'SPRO_TEST_WRONG_CHECKOUT=1' ); expect( false !== strpos( gate( $root, '', true ), 'exact PA checkout' ), 'tests pointing to another checkout cannot certify this commit' ); putenv( 'SPRO_TEST_WRONG_CHECKOUT' );
	putenv( 'SPRO_TEST_PLUGIN_VERSION=2.0' ); expect( false !== strpos( gate( $root, '', true ), 'plugin versions changed' ), 'runtime plugin version change invalidates evidence' ); putenv( 'SPRO_TEST_PLUGIN_VERSION' );
	putenv( 'SSPA_GATE_SITE_PATH=' . $root ); expect( false !== strpos( gate( $root, '', true ), 'existing test runner configuration' ), 'declared site must match the runner scenario' ); putenv( 'SSPA_GATE_SITE_PATH=' . $base . '/site' );
	$manifest_file = $root . '/.github/feature-release-gate.json'; $original_manifest = file_get_contents( $manifest_file ); $private_manifest = json_decode( $original_manifest, true ); $private_manifest['release']['core_bugs'] = array( 99 ); file_put_contents( $manifest_file, json_encode( $private_manifest ) ); expect( false !== strpos( gate( $root, '', true ), 'not supported in this public repository' ), 'private issue declarations are rejected without querying public issues' ); file_put_contents( $manifest_file, $original_manifest );
	putenv( 'SPRO_TEST_PUBLIC=1' ); gate( $root ); echo "PASS: public PA repository does not require private repository status\n";
	file_put_contents( $bin . '/gh', "#!/bin/sh\necho 'GitHub issue API must not be called' >&2\nexit 99\n" ); gate( $root ); echo "PASS: public release gate does not call private issue APIs\n";
	file_put_contents( $root . '/.data/release-gate/runtime.log', 'tamper' ); expect( false !== strpos( gate( $root, '', true ), 'changed evidence' ), 'altered test log invalidates evidence' );
	putenv( 'SPRO_TEST_NO_PROOF=1' ); expect( false !== strpos( gate( $root, '--record-tests', true ), 'required assertion' ), 'zero exit without enabled and disabled assertion proof is rejected' ); putenv( 'SPRO_TEST_NO_PROOF' );
	gate( $root, '--record-tests' ); putenv( 'SPRO_TEST_FAIL=1' ); gate( $root, '--record-tests', true ); putenv( 'SPRO_TEST_FAIL' ); expect( ! is_file( $root . '/.data/release-gate/evidence.json' ), 'failed actual command invalidates previous success' );
	gate( $root, '--record-tests' ); file_put_contents( $root . '/untracked.php', '<?php' ); expect( false !== strpos( gate( $root, '', true ), 'Commit tracked changes' ), 'untracked source blocks release' ); unlink( $root . '/untracked.php' ); file_put_contents( $root . '/feature.txt', "changed\n" ); expect( false !== strpos( gate( $root, '', true ), 'Commit tracked changes' ), 'dirty tracked source blocks release' ); commit( $root, 'new untested source' ); expect( false !== strpos( gate( $root, '', true ), 'Missing or stale' ), 'new commit invalidates previous passing evidence' );
	gate( $root, '--record-tests' );
	git( $base, 'clone', '-q', $base . '/origin.git', $base . '/core' ); git( $base . '/core', 'config', 'user.name', 'Synthetic test' ); git( $base . '/core', 'config', 'user.email', 'test@example.invalid' ); file_put_contents( $base . '/core/new-core.txt', 'new core' ); commit( $base . '/core', 'advance core' ); git( $base . '/core', 'push', '-q', 'origin', 'main' );
	expect( false !== strpos( gate( $root, '', true ), 'merge-base' ), 'fresh fetch detects core advancing after recorded tests' );
	echo 'Fixtures retained: ' . $base . "\n";
} catch ( Throwable $e ) { fwrite( STDERR, 'FAIL: ' . $e->getMessage() . "\nFixtures: " . $base . "\n" ); exit( 1 ); }
