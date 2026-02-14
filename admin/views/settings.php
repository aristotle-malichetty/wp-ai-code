<?php
/**
 * Admin view — settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
	<h1><?php esc_html_e( 'WP AI Code Settings', 'wp-ai-code' ); ?></h1>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'wpaic_settings' );
		do_settings_sections( 'wpaic_settings' );
		submit_button();
		?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'API Information', 'wp-ai-code' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'API Base URL', 'wp-ai-code' ); ?></th>
			<td><code><?php echo esc_html( rest_url( 'wp-ai-code/v1/' ) ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Status Endpoint', 'wp-ai-code' ); ?></th>
			<td><code><?php echo esc_html( rest_url( 'wp-ai-code/v1/status' ) ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Authentication', 'wp-ai-code' ); ?></th>
			<td>
				<?php esc_html_e( 'Use Application Passwords with Basic Auth.', 'wp-ai-code' ); ?>
				<br>
				<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">
					<?php esc_html_e( 'Manage Application Passwords', 'wp-ai-code' ); ?>
				</a>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'System Status', 'wp-ai-code' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Plugin Version', 'wp-ai-code' ); ?></th>
			<td><?php echo esc_html( WPAIC_VERSION ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'PHP Version', 'wp-ai-code' ); ?></th>
			<td>
				<?php echo esc_html( PHP_VERSION ); ?>
				<?php if ( version_compare( PHP_VERSION, '8.0', '<' ) ) : ?>
					<span class="wpaic-status wpaic-status--failed"><?php esc_html_e( 'PHP 8.0+ required', 'wp-ai-code' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'HTTPS', 'wp-ai-code' ); ?></th>
			<td>
				<?php if ( is_ssl() ) : ?>
					<span class="wpaic-status wpaic-status--deployed"><?php esc_html_e( 'Active', 'wp-ai-code' ); ?></span>
				<?php else : ?>
					<span class="wpaic-status wpaic-status--pending"><?php esc_html_e( 'Not active', 'wp-ai-code' ); ?></span>
					<br><small><?php esc_html_e( 'HTTPS is required in production. Local development (.local, .test, localhost) is exempt.', 'wp-ai-code' ); ?></small>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Staging Directory', 'wp-ai-code' ); ?></th>
			<td>
				<?php if ( is_dir( WPAIC_STAGING_DIR ) && wp_is_writable( WPAIC_STAGING_DIR ) ) : ?>
					<span class="wpaic-status wpaic-status--deployed"><?php esc_html_e( 'Writable', 'wp-ai-code' ); ?></span>
				<?php else : ?>
					<span class="wpaic-status wpaic-status--failed"><?php esc_html_e( 'Not writable', 'wp-ai-code' ); ?></span>
				<?php endif; ?>
				<br><code><?php echo esc_html( WPAIC_STAGING_DIR ); ?></code>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Themes Directory', 'wp-ai-code' ); ?></th>
			<td>
				<?php echo wp_is_writable( get_theme_root() )
					? '<span class="wpaic-status wpaic-status--deployed">' . esc_html__( 'Writable', 'wp-ai-code' ) . '</span>'
					: '<span class="wpaic-status wpaic-status--failed">' . esc_html__( 'Not writable', 'wp-ai-code' ) . '</span>';
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Plugins Directory', 'wp-ai-code' ); ?></th>
			<td>
				<?php echo wp_is_writable( WP_PLUGIN_DIR )
					? '<span class="wpaic-status wpaic-status--deployed">' . esc_html__( 'Writable', 'wp-ai-code' ) . '</span>'
					: '<span class="wpaic-status wpaic-status--failed">' . esc_html__( 'Not writable', 'wp-ai-code' ) . '</span>';
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Next Cleanup', 'wp-ai-code' ); ?></th>
			<td>
				<?php
				$next = wp_next_scheduled( 'wpaic_daily_cleanup' );
				echo $next
					? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
					: esc_html__( 'Not scheduled', 'wp-ai-code' );
				?>
			</td>
		</tr>
	</table>
</div>
