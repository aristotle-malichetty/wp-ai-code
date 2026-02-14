<?php

namespace WPAICode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database operations for the deployments table.
 */
class DeploymentStore {

	private const DB_VERSION = '1.0.0';

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wpaic_deployments';
	}

	/**
	 * Create the deployments table. Called on plugin activation.
	 */
	public function create_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			deployment_name VARCHAR(255) NOT NULL,
			description TEXT,
			target_type VARCHAR(20) NOT NULL DEFAULT 'theme',
			target_slug VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			files_manifest LONGTEXT,
			validation_log LONGTEXT,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			reviewed_by BIGINT UNSIGNED DEFAULT NULL,
			reviewed_at DATETIME DEFAULT NULL,
			deployed_at DATETIME DEFAULT NULL,
			rolled_back_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY status (status),
			KEY target_type (target_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'wpaic_db_version', self::DB_VERSION );
	}

	/**
	 * Insert a new deployment record.
	 *
	 * @param array $data Deployment data.
	 * @return int|false The deployment ID on success, false on failure.
	 */
	public function insert_deployment( array $data ): int|false {
		global $wpdb;

		$defaults = [
			'deployment_name' => '',
			'description'     => '',
			'target_type'     => 'theme',
			'target_slug'     => '',
			'status'          => 'pending',
			'files_manifest'  => '[]',
			'validation_log'  => '[]',
			'created_by'      => get_current_user_id(),
			'created_at'      => current_time( 'mysql', true ),
		];

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert(
			$this->table,
			[
				'deployment_name' => sanitize_text_field( $data['deployment_name'] ),
				'description'     => sanitize_textarea_field( $data['description'] ),
				'target_type'     => sanitize_key( $data['target_type'] ),
				'target_slug'     => sanitize_file_name( $data['target_slug'] ),
				'status'          => sanitize_key( $data['status'] ),
				'files_manifest'  => $data['files_manifest'],
				'validation_log'  => $data['validation_log'],
				'created_by'      => absint( $data['created_by'] ),
				'created_at'      => $data['created_at'],
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a single deployment by ID.
	 *
	 * @param int $id Deployment ID.
	 * @return object|null
	 */
	public function get_deployment( int $id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id )
		);

		return $row ?: null;
	}

	/**
	 * Get deployments with filters and pagination.
	 *
	 * @param array $args {
	 *     @type string $status      Filter by status.
	 *     @type string $target_type Filter by target type.
	 *     @type int    $per_page    Results per page. Default 20.
	 *     @type int    $page        Page number. Default 1.
	 *     @type string $orderby     Column to order by. Default 'created_at'.
	 *     @type string $order       ASC or DESC. Default 'DESC'.
	 * }
	 * @return array{ items: array, total: int, pages: int }
	 */
	public function get_deployments( array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'status'      => '',
			'target_type' => '',
			'per_page'    => 20,
			'page'        => 1,
			'orderby'     => 'created_at',
			'order'       => 'DESC',
		];
		$args = wp_parse_args( $args, $defaults );

		$where  = [];
		$values = [];

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['target_type'] ) ) {
			$where[]  = 'target_type = %s';
			$values[] = sanitize_key( $args['target_type'] );
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		// Validate orderby against allowed columns.
		$allowed_orderby = [ 'id', 'deployment_name', 'status', 'target_type', 'created_at', 'deployed_at' ];
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Count total.
		if ( ! empty( $values ) ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} {$where_sql}", ...$values )
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} {$where_sql}" );
		}

		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$query_values   = array_merge( $values, [ $per_page, $offset ] );
		$order_clause   = "ORDER BY {$orderby} {$order}";
		$limit_clause   = 'LIMIT %d OFFSET %d';

		if ( ! empty( $query_values ) ) {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} {$where_sql} {$order_clause} {$limit_clause}",
					...$query_values
				)
			);
		} else {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} {$order_clause} {$limit_clause}",
					$per_page,
					$offset
				)
			);
		}

		return [
			'items' => $items ?: [],
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		];
	}

	/**
	 * Update deployment status with optional extra fields.
	 *
	 * @param int    $id           Deployment ID.
	 * @param string $status       New status.
	 * @param array  $extra_fields Additional columns to update.
	 * @return bool
	 */
	public function update_status( int $id, string $status, array $extra_fields = [] ): bool {
		global $wpdb;

		$data    = array_merge( $extra_fields, [ 'status' => sanitize_key( $status ) ] );
		$formats = [];

		foreach ( $data as $key => $value ) {
			if ( is_int( $value ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		$result = $wpdb->update(
			$this->table,
			$data,
			[ 'id' => $id ],
			$formats,
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Get the raw table name (for direct queries if needed).
	 */
	public function get_table_name(): string {
		return $this->table;
	}
}
