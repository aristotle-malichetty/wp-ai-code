<?php
/**
 * Admin view — single deployment detail.
 *
 * @var object $deployment The deployment record (passed from AdminPage::render_dashboard).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$files_manifest = json_decode( $deployment->files_manifest ?? '[]', true ) ?: [];
$validation_log = json_decode( $deployment->validation_log ?? '[]', true ) ?: [];
$user           = get_userdata( (int) $deployment->created_by );
$reviewer       = $deployment->reviewed_by ? get_userdata( (int) $deployment->reviewed_by ) : null;

// Admin notices.
$notice = isset( $_GET['wpaic_notice'] ) ? sanitize_key( $_GET['wpaic_notice'] ) : '';
$error  = isset( $_GET['wpaic_error'] ) ? sanitize_key( $_GET['wpaic_error'] ) : '';
?>

<div class="wrap">
	<h1 class="wp-heading-inline">
		<?php printf( esc_html__( 'Deployment #%d: %s', 'wp-ai-code' ), $deployment->id, esc_html( $deployment->deployment_name ) ); ?>
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpaic-dashboard' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to List', 'wp-ai-code' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( 'approved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Deployment approved and files deployed successfully.', 'wp-ai-code' ); ?></p></div>
	<?php elseif ( 'rejected' === $notice ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Deployment has been rejected.', 'wp-ai-code' ); ?></p></div>
	<?php elseif ( 'rolled_back' === $notice ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Deployment has been rolled back. Original files restored.', 'wp-ai-code' ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'deploy_failed' === $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Deployment execution failed. Check the deployment details for errors.', 'wp-ai-code' ); ?></p></div>
	<?php elseif ( 'rollback_failed' === $error ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Rollback failed. Manual intervention may be needed.', 'wp-ai-code' ); ?></p></div>
	<?php endif; ?>

	<div class="wpaic-detail-grid">
		<!-- Metadata card -->
		<div class="wpaic-card">
			<h2><?php esc_html_e( 'Details', 'wp-ai-code' ); ?></h2>
			<table class="wpaic-meta-table">
				<tr>
					<th><?php esc_html_e( 'Status', 'wp-ai-code' ); ?></th>
					<td><span class="wpaic-status wpaic-status--<?php echo esc_attr( $deployment->status ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $deployment->status ) ) ); ?></span></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Target', 'wp-ai-code' ); ?></th>
					<td><?php echo esc_html( $deployment->target_type . '/' . $deployment->target_slug ); ?></td>
				</tr>
				<?php if ( ! empty( $deployment->description ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Description', 'wp-ai-code' ); ?></th>
					<td><?php echo esc_html( $deployment->description ); ?></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Files', 'wp-ai-code' ); ?></th>
					<td><?php echo esc_html( count( $files_manifest ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Submitted By', 'wp-ai-code' ); ?></th>
					<td><?php echo $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'wp-ai-code' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Created', 'wp-ai-code' ); ?></th>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $deployment->created_at ) ) ); ?></td>
				</tr>
				<?php if ( $reviewer ) : ?>
				<tr>
					<th><?php esc_html_e( 'Reviewed By', 'wp-ai-code' ); ?></th>
					<td><?php echo esc_html( $reviewer->display_name ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $deployment->reviewed_at ) : ?>
				<tr>
					<th><?php esc_html_e( 'Reviewed', 'wp-ai-code' ); ?></th>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $deployment->reviewed_at ) ) ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $deployment->deployed_at ) : ?>
				<tr>
					<th><?php esc_html_e( 'Deployed', 'wp-ai-code' ); ?></th>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $deployment->deployed_at ) ) ); ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $deployment->rolled_back_at ) : ?>
				<tr>
					<th><?php esc_html_e( 'Rolled Back', 'wp-ai-code' ); ?></th>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $deployment->rolled_back_at ) ) ); ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<!-- Action buttons -->
			<?php if ( 'pending' === $deployment->status ) : ?>
				<div class="wpaic-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpaic-action-form" data-confirm="approve">
						<?php wp_nonce_field( 'wpaic_approve' ); ?>
						<input type="hidden" name="action" value="wpaic_approve">
						<input type="hidden" name="deployment_id" value="<?php echo esc_attr( $deployment->id ); ?>">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve & Deploy', 'wp-ai-code' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpaic-action-form" data-confirm="reject">
						<?php wp_nonce_field( 'wpaic_reject' ); ?>
						<input type="hidden" name="action" value="wpaic_reject">
						<input type="hidden" name="deployment_id" value="<?php echo esc_attr( $deployment->id ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Reject', 'wp-ai-code' ); ?></button>
					</form>
				</div>
			<?php elseif ( 'deployed' === $deployment->status ) : ?>
				<div class="wpaic-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpaic-action-form" data-confirm="rollback">
						<?php wp_nonce_field( 'wpaic_rollback' ); ?>
						<input type="hidden" name="action" value="wpaic_rollback">
						<input type="hidden" name="deployment_id" value="<?php echo esc_attr( $deployment->id ); ?>">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Rollback', 'wp-ai-code' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<!-- Validation results -->
		<?php if ( ! empty( $validation_log ) ) : ?>
		<div class="wpaic-card">
			<h2><?php esc_html_e( 'Validation Results', 'wp-ai-code' ); ?></h2>

			<?php if ( ! empty( $validation_log['errors'] ) ) : ?>
				<div class="wpaic-validation-section wpaic-validation--errors">
					<h3><?php esc_html_e( 'Errors', 'wp-ai-code' ); ?></h3>
					<ul>
						<?php foreach ( $validation_log['errors'] as $err ) : ?>
							<li><?php echo esc_html( $err['message'] ?? '' ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $validation_log['warnings'] ) ) : ?>
				<div class="wpaic-validation-section wpaic-validation--warnings">
					<h3><?php esc_html_e( 'Warnings', 'wp-ai-code' ); ?></h3>
					<ul>
						<?php foreach ( $validation_log['warnings'] as $warn ) : ?>
							<li><?php echo esc_html( $warn['message'] ?? '' ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( empty( $validation_log['errors'] ) && empty( $validation_log['warnings'] ) ) : ?>
				<p class="wpaic-validation-ok"><?php esc_html_e( 'All validation checks passed.', 'wp-ai-code' ); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>

	<!-- File listing -->
	<div class="wpaic-card wpaic-card--full">
		<h2><?php printf( esc_html__( 'Files (%d)', 'wp-ai-code' ), count( $files_manifest ) ); ?></h2>

		<?php if ( empty( $files_manifest ) ) : ?>
			<p><?php esc_html_e( 'No files in this deployment.', 'wp-ai-code' ); ?></p>
		<?php else : ?>
			<?php foreach ( $files_manifest as $index => $file ) :
				$path      = $file['path'] ?? '';
				$content   = $file['content'] ?? '';
				$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				$is_text   = in_array( $extension, [ 'php', 'css', 'js', 'json', 'txt', 'md', 'html', 'twig', 'svg' ], true );
			?>
				<div class="wpaic-file">
					<div class="wpaic-file-header">
						<span class="wpaic-file-path"><?php echo esc_html( $path ); ?></span>
						<span class="wpaic-file-size"><?php echo esc_html( size_format( strlen( $content ) ) ); ?></span>
					</div>
					<?php if ( $is_text ) : ?>
						<pre class="wpaic-file-content"><code><?php echo esc_html( $content ); ?></code></pre>
					<?php else : ?>
						<p class="wpaic-file-binary"><?php esc_html_e( 'Binary file — preview not available.', 'wp-ai-code' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>
