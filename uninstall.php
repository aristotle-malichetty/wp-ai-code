<?php
/**
 * WP AI Code uninstall handler.
 *
 * Removes all plugin data: database table, options, staging directory.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the deployments table.
$table = $wpdb->prefix . 'wpaic_deployments';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Delete all plugin options.
$options = [
	'wpaic_enabled',
	'wpaic_allowed_targets',
	'wpaic_max_file_size',
	'wpaic_max_deployment_size',
	'wpaic_cleanup_days',
	'wpaic_notify_email',
	'wpaic_audit_log',
	'wpaic_db_version',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete rate-limit transients.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpaic_rate_%' OR option_name LIKE '_transient_timeout_wpaic_rate_%'"
);

// Remove staging directory.
$staging_dir = WP_CONTENT_DIR . '/wpaic-staging';
if ( is_dir( $staging_dir ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $staging_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getPathname() );
		} else {
			unlink( $file->getPathname() );
		}
	}
	rmdir( $staging_dir );
}

// Clear scheduled cron.
wp_clear_scheduled_hook( 'wpaic_daily_cleanup' );
