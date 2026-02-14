<?php

namespace WPAICode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authentication, authorization, and security utilities.
 */
class Auth {

	private const RATE_LIMIT_MAX     = 10;
	private const RATE_LIMIT_WINDOW  = 60; // seconds
	private const AUDIT_LOG_MAX_SIZE = 1000;

	/**
	 * Permission callback for REST endpoints.
	 * Verifies user has manage_options capability.
	 */
	public static function check_permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'wpaic_forbidden',
				__( 'You do not have permission to access this endpoint.', 'wp-ai-code' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Check if the plugin is enabled (kill switch).
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( 'wpaic_enabled', true );
	}

	/**
	 * Check if the request is over HTTPS.
	 * Allows exceptions for local development.
	 */
	public static function check_https(): bool|\WP_Error {
		if ( is_ssl() ) {
			return true;
		}

		// Allow local development.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$local_hosts = [ 'localhost', '127.0.0.1', '::1' ];

		if ( in_array( $host, $local_hosts, true ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' ) ) {
			return true;
		}

		return new \WP_Error(
			'wpaic_https_required',
			__( 'HTTPS is required for API access.', 'wp-ai-code' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Check rate limit for the current user.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_rate_limit(): bool|\WP_Error {
		$user_id = get_current_user_id();
		$key     = 'wpaic_rate_' . $user_id;
		$current = (int) get_transient( $key );

		if ( $current >= self::RATE_LIMIT_MAX ) {
			return new \WP_Error(
				'wpaic_rate_limited',
				__( 'Rate limit exceeded. Please wait before submitting again.', 'wp-ai-code' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $current + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Add an entry to the audit log.
	 *
	 * @param string $action  Action performed.
	 * @param array  $details Additional context.
	 */
	public static function audit_log( string $action, array $details = [] ): void {
		$log = get_option( 'wpaic_audit_log', [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		$entry = [
			'timestamp' => current_time( 'mysql', true ),
			'user_id'   => get_current_user_id(),
			'action'    => sanitize_text_field( $action ),
			'details'   => $details,
			'ip'        => self::get_client_ip(),
		];

		// Add to beginning (most recent first).
		array_unshift( $log, $entry );

		// Trim to max size.
		if ( count( $log ) > self::AUDIT_LOG_MAX_SIZE ) {
			$log = array_slice( $log, 0, self::AUDIT_LOG_MAX_SIZE );
		}

		update_option( 'wpaic_audit_log', $log, false );
	}

	/**
	 * Get client IP address (best effort).
	 */
	private static function get_client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		return sanitize_text_field( $ip );
	}
}
