<?php
/**
 * Plugin Name: WP AI Code
 * Plugin URI:  https://github.com/your-org/wp-ai-code
 * Description: Enables AI coding agents to push theme/plugin files to WordPress through authenticated REST API endpoints with mandatory human approval.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      WP AI Code
 * Author URI:  https://github.com/your-org/wp-ai-code
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-code
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAIC_VERSION', '1.0.0' );
define( 'WPAIC_PLUGIN_FILE', __FILE__ );
define( 'WPAIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAIC_STAGING_DIR', WP_CONTENT_DIR . '/wpaic-staging' );

/**
 * Autoloader for WPAICode classes.
 */
spl_autoload_register( function ( string $class ) {
	$prefix = 'WPAICode\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$parts    = explode( '\\', $relative );
	$filename = array_pop( $parts );

	// Convert class name to file name: DeploymentStore -> class-deployment-store.php
	$filename = 'class-' . strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $filename ) ) . '.php';

	// Map namespace parts to directories
	$subdir = '';
	if ( ! empty( $parts ) ) {
		$subdir = strtolower( implode( '/', $parts ) ) . '/';
	}

	// Check includes/ first, then admin/
	$paths = [
		WPAIC_PLUGIN_DIR . 'includes/' . $subdir . $filename,
		WPAIC_PLUGIN_DIR . 'admin/' . $subdir . $filename,
	];

	foreach ( $paths as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
} );

/**
 * Plugin activation.
 */
function wpaic_activate(): void {
	$store = new WPAICode\DeploymentStore();
	$store->create_table();

	// Create staging directory with protections.
	if ( ! is_dir( WPAIC_STAGING_DIR ) ) {
		wp_mkdir_p( WPAIC_STAGING_DIR );
	}

	// Protect staging dir from web access.
	$htaccess = WPAIC_STAGING_DIR . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Deny from all\n" );
	}

	$index = WPAIC_STAGING_DIR . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}

	// Set default options.
	add_option( 'wpaic_enabled', true );
	add_option( 'wpaic_allowed_targets', [ 'theme', 'plugin', 'mu-plugin' ] );
	add_option( 'wpaic_max_file_size', 512000 );
	add_option( 'wpaic_max_deployment_size', 5242880 );
	add_option( 'wpaic_cleanup_days', 30 );
	add_option( 'wpaic_notify_email', false );

	// Schedule cleanup cron.
	if ( ! wp_next_scheduled( 'wpaic_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'wpaic_daily_cleanup' );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wpaic_activate' );

/**
 * Plugin deactivation.
 */
function wpaic_deactivate(): void {
	wp_clear_scheduled_hook( 'wpaic_daily_cleanup' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wpaic_deactivate' );

// Boot the plugin.
add_action( 'plugins_loaded', function () {
	WPAICode\Plugin::instance();
} );
