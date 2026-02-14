<?php
/**
 * Admin dashboard view — deployments list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$store = new WPAICode\DeploymentStore();

// Current filter.
$current_status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$current_page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

$result = $store->get_deployments( [
	'status'   => $current_status,
	'per_page' => 20,
	'page'     => $current_page,
] );

$items       = $result['items'];
$total       = $result['total'];
$total_pages = $result['pages'];

// Status counts for filter tabs.
$all_count = $store->get_deployments( [ 'per_page' => 1 ] )['total'];
$statuses  = [ 'pending', 'deployed', 'rejected', 'rolled_back', 'failed' ];
$counts    = [];
foreach ( $statuses as $s ) {
	$counts[ $s ] = $store->get_deployments( [ 'status' => $s, 'per_page' => 1 ] )['total'];
}
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Code Deployments', 'wp-ai-code' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( ! WPAICode\Auth::is_enabled() ) : ?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'WP AI Code is currently disabled.', 'wp-ai-code' ); ?></strong>
			<?php esc_html_e( 'New deployments cannot be submitted or approved. Go to Settings to re-enable.', 'wp-ai-code' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Status filter tabs -->
	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpaic-dashboard' ) ); ?>"
			   class="<?php echo '' === $current_status ? 'current' : ''; ?>">
				<?php printf( esc_html__( 'All (%d)', 'wp-ai-code' ), $all_count ); ?>
			</a> |
		</li>
		<?php foreach ( $statuses as $i => $s ) : ?>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpaic-dashboard&status=' . $s ) ); ?>"
				   class="<?php echo $current_status === $s ? 'current' : ''; ?>">
					<?php printf( '%s (%d)', esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ), $counts[ $s ] ); ?>
				</a><?php echo $i < count( $statuses ) - 1 ? ' |' : ''; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th class="column-id" style="width: 50px;"><?php esc_html_e( 'ID', 'wp-ai-code' ); ?></th>
				<th class="column-name"><?php esc_html_e( 'Name', 'wp-ai-code' ); ?></th>
				<th class="column-target"><?php esc_html_e( 'Target', 'wp-ai-code' ); ?></th>
				<th class="column-status" style="width: 110px;"><?php esc_html_e( 'Status', 'wp-ai-code' ); ?></th>
				<th class="column-files" style="width: 60px;"><?php esc_html_e( 'Files', 'wp-ai-code' ); ?></th>
				<th class="column-author"><?php esc_html_e( 'Submitted By', 'wp-ai-code' ); ?></th>
				<th class="column-date"><?php esc_html_e( 'Date', 'wp-ai-code' ); ?></th>
				<th class="column-actions"><?php esc_html_e( 'Actions', 'wp-ai-code' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $items ) ) : ?>
				<tr>
					<td colspan="8"><?php esc_html_e( 'No deployments found.', 'wp-ai-code' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $items as $item ) :
					$files_manifest = json_decode( $item->files_manifest ?? '[]', true ) ?: [];
					$file_count     = count( $files_manifest );
					$user           = get_userdata( (int) $item->created_by );
					$detail_url     = admin_url( 'admin.php?page=wpaic-dashboard&deployment_id=' . $item->id );
				?>
					<tr>
						<td><?php echo esc_html( $item->id ); ?></td>
						<td>
							<strong><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $item->deployment_name ); ?></a></strong>
						</td>
						<td><?php echo esc_html( $item->target_type . '/' . $item->target_slug ); ?></td>
						<td><span class="wpaic-status wpaic-status--<?php echo esc_attr( $item->status ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $item->status ) ) ); ?></span></td>
						<td><?php echo esc_html( $file_count ); ?></td>
						<td><?php echo $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'wp-ai-code' ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->created_at ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'wp-ai-code' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post( paginate_links( [
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $current_page,
					'total'   => $total_pages,
					'type'    => 'plain',
				] ) );
				?>
			</div>
		</div>
	<?php endif; ?>
</div>
