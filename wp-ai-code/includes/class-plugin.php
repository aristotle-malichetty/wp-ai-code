<?php

namespace WPAICode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class — singleton that wires all components together.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_hooks();
	}

	private function register_hooks(): void {
		// REST API endpoints.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Admin dashboard.
		if ( is_admin() ) {
			add_action( 'init', [ $this, 'load_admin' ] );
		}

		// Cron cleanup.
		add_action( 'wpaic_daily_cleanup', [ $this, 'run_cleanup' ] );
	}

	public function register_rest_routes(): void {
		$rest = new RestApi();
		$rest->register_routes();
	}

	public function load_admin(): void {
		new AdminPage();
	}

	public function run_cleanup(): void {
		$days     = (int) get_option( 'wpaic_cleanup_days', 30 );
		$deployer = new Deployer();
		$deployer->cleanup_old_staging( $days );
	}
}
