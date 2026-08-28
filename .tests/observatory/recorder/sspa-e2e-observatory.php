<?php
/**
 * Permanent request recorder for local parallel-development sites.
 *
 * The file stays installed but returns immediately unless a signed observatory
 * request arms it. It never changes application behaviour or suppresses errors.
 */

if ( ! defined( 'SSPA_OBSERVATORY_SPOOL' ) || ! defined( 'SSPA_OBSERVATORY_SECRET' ) || ! defined( 'SSPA_OBSERVATORY_SITE_ID' ) ) {
	return;
}

$sspa_observation_id = isset( $_SERVER['HTTP_X_SSPA_OBSERVATORY'] ) ? (string) $_SERVER['HTTP_X_SSPA_OBSERVATORY'] : '';
$sspa_token          = isset( $_SERVER['HTTP_X_SSPA_OBSERVATORY_TOKEN'] ) ? (string) $_SERVER['HTTP_X_SSPA_OBSERVATORY_TOKEN'] : '';

if ( ! preg_match( '/^([A-Za-z0-9_-]{8,80}):([A-Za-z0-9_-]{8,120})$/', $sspa_observation_id, $sspa_id_parts ) ) {
	return;
}
if ( ! preg_match( '/^(\d{10}):([a-f0-9]{64})$/', $sspa_token, $sspa_token_parts ) ) {
	return;
}

$sspa_run_id    = $sspa_id_parts[1];
$sspa_sample_id = $sspa_id_parts[2];
$sspa_expires   = (int) $sspa_token_parts[1];
$sspa_signature = $sspa_token_parts[2];
$sspa_method    = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
$sspa_host      = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
$sspa_path      = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '/';
$sspa_canonical = implode( "\n", array( $sspa_run_id, $sspa_sample_id, $sspa_method, $sspa_host, $sspa_path, (string) $sspa_expires ) );
$sspa_expected  = hash_hmac( 'sha256', $sspa_canonical, SSPA_OBSERVATORY_SECRET );

if ( $sspa_expires < time() || $sspa_expires > time() + 120 || ! hash_equals( $sspa_expected, $sspa_signature ) ) {
	return;
}

$sspa_run_dir = rtrim( SSPA_OBSERVATORY_SPOOL, '/\\' ) . '/inbox/' . SSPA_OBSERVATORY_SITE_ID . '/' . $sspa_run_id;
$sspa_token_dir = rtrim( SSPA_OBSERVATORY_SPOOL, '/\\' ) . '/tokens/' . SSPA_OBSERVATORY_SITE_ID;
if ( ! is_dir( $sspa_run_dir ) ) {
	@mkdir( $sspa_run_dir, 0770, true );
}
if ( ! is_dir( $sspa_token_dir ) ) {
	@mkdir( $sspa_token_dir, 0770, true );
}

$sspa_replay_path = $sspa_token_dir . '/' . hash( 'sha256', $sspa_run_id . ':' . $sspa_sample_id ) . '.used';
$sspa_replay_file = @fopen( $sspa_replay_path, 'x' );
if ( false === $sspa_replay_file ) {
	return;
}
fwrite( $sspa_replay_file, (string) time() );
fclose( $sspa_replay_file );

header( 'X-SSPA-Observatory-Canary: ' . $sspa_sample_id );

$sspa_faults = array();
$sspa_redact = static function ( $message ) {
	$message = (string) $message;
	$message = preg_replace( '~(https?://[^\s?]+)\?[^\s]+~i', '$1?[redacted]', $message );
	$message = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $message );
	$message = preg_replace( '/(password|passwd|token|secret|nonce)=([^&\s]+)/i', '$1=[redacted]', $message );
	return substr( $message, 0, 2000 );
};
$sspa_relative_file = static function ( $file ) {
	$file = (string) $file;
	if ( defined( 'ABSPATH' ) && 0 === strpos( $file, ABSPATH ) ) {
		return substr( $file, strlen( ABSPATH ) );
	}
	return basename( $file );
};
$sspa_error_name = static function ( $severity ) {
	$names = array(
		E_ERROR => 'error', E_WARNING => 'warning', E_PARSE => 'parse', E_NOTICE => 'notice',
		E_CORE_ERROR => 'core_error', E_CORE_WARNING => 'core_warning', E_COMPILE_ERROR => 'compile_error',
		E_COMPILE_WARNING => 'compile_warning', E_USER_ERROR => 'user_error', E_USER_WARNING => 'user_warning',
		E_USER_NOTICE => 'user_notice', E_STRICT => 'strict', E_RECOVERABLE_ERROR => 'recoverable_error',
		E_DEPRECATED => 'deprecated', E_USER_DEPRECATED => 'user_deprecated',
	);
	return isset( $names[ $severity ] ) ? $names[ $severity ] : 'unknown';
};

$sspa_previous_handler = set_error_handler(
	static function ( $severity, $message, $file, $line ) use ( &$sspa_faults, &$sspa_previous_handler, $sspa_redact, $sspa_relative_file, $sspa_error_name ) {
		$redacted = $sspa_redact( $message );
		$sspa_faults[] = array(
			'kind'        => 'php',
			'severity'    => $sspa_error_name( $severity ),
			'message'     => $redacted,
			'fingerprint' => hash( 'sha256', $severity . '|' . $redacted . '|' . $sspa_relative_file( $file ) . '|' . (int) $line ),
			'file'        => $sspa_relative_file( $file ),
			'line'        => (int) $line,
		);
		if ( is_callable( $sspa_previous_handler ) ) {
			return (bool) call_user_func( $sspa_previous_handler, $severity, $message, $file, $line );
		}
		return false;
	}
);

register_shutdown_function(
	static function () use ( $sspa_run_id, $sspa_sample_id, $sspa_run_dir, $sspa_method, $sspa_host, $sspa_path, &$sspa_faults, $sspa_redact, $sspa_relative_file, $sspa_error_name ) {
		$last = error_get_last();
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( is_array( $last ) && in_array( (int) $last['type'], $fatal_types, true ) ) {
			$redacted = $sspa_redact( $last['message'] );
			$fingerprint = hash( 'sha256', $last['type'] . '|' . $redacted . '|' . $sspa_relative_file( $last['file'] ) . '|' . (int) $last['line'] );
			$known = false;
			foreach ( $sspa_faults as $fault ) {
				if ( isset( $fault['fingerprint'] ) && $fingerprint === $fault['fingerprint'] ) {
					$known = true;
					break;
				}
			}
			if ( ! $known ) {
				$sspa_faults[] = array(
					'kind'        => 'php',
					'severity'    => $sspa_error_name( (int) $last['type'] ),
					'message'     => $redacted,
					'fingerprint' => $fingerprint,
					'file'        => $sspa_relative_file( $last['file'] ),
					'line'        => (int) $last['line'],
				);
			}
		}

		global $wpdb;
		$db_error = '';
		$query_count = null;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$query_count = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
			$db_error = isset( $wpdb->last_error ) ? $sspa_redact( $wpdb->last_error ) : '';
			if ( '' !== $db_error ) {
				$sspa_faults[] = array(
					'kind'        => 'database',
					'severity'    => 'database_error',
					'message'     => $db_error,
					'fingerprint' => hash( 'sha256', 'database|' . $db_error ),
					'file'        => '',
					'line'        => 0,
				);
			}
		}

		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$envelope = array(
			'schema'          => 1,
			'recorder_version'=> '0.1.0',
			'run_id'          => $sspa_run_id,
			'sample_id'       => $sspa_sample_id,
			'site_id'         => SSPA_OBSERVATORY_SITE_ID,
			'method'          => $sspa_method,
			'host'            => $sspa_host,
			'path'            => $sspa_path,
			'php_wall_ms'     => round( ( microtime( true ) - $started ) * 1000, 3 ),
			'peak_memory_bytes'=> memory_get_peak_usage( true ),
			'http_status'     => http_response_code(),
			'query_count'     => $query_count,
			'faults'          => $sspa_faults,
			'reached_shutdown'=> true,
			'created_at'      => gmdate( 'c' ),
		);
		$json = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return;
		}
		$final = $sspa_run_dir . '/' . $sspa_sample_id . '.json';
		$temp  = $final . '.' . getmypid() . '.tmp';
		if ( false !== @file_put_contents( $temp, $json, LOCK_EX ) ) {
			@rename( $temp, $final );
		}
	}
);
