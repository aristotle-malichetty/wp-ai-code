<?php

namespace WPAICode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints for WP AI Code.
 *
 * Namespace: wp-ai-code/v1
 */
class RestApi {

	private const NAMESPACE = 'wp-ai-code/v1';

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		// POST /deploy — Submit files for deployment.
		register_rest_route( self::NAMESPACE, '/deploy', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_deploy' ],
			'permission_callback' => [ Auth::class, 'check_permission' ],
			'args'                => $this->get_deploy_args(),
		] );

		// GET /deployments — List deployments.
		register_rest_route( self::NAMESPACE, '/deployments', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_list_deployments' ],
			'permission_callback' => [ Auth::class, 'check_permission' ],
			'args'                => [
				'status'      => [
					'type'              => 'string',
					'enum'              => [ 'pending', 'approved', 'rejected', 'deployed', 'rolled_back', 'failed' ],
					'sanitize_callback' => 'sanitize_key',
				],
				'target_type' => [
					'type'              => 'string',
					'enum'              => [ 'theme', 'plugin', 'mu-plugin' ],
					'sanitize_callback' => 'sanitize_key',
				],
				'per_page'    => [
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				],
				'page'        => [
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				],
			],
		] );

		// GET /deployments/{id} — Single deployment.
		register_rest_route( self::NAMESPACE, '/deployments/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_get_deployment' ],
			'permission_callback' => [ Auth::class, 'check_permission' ],
			'args'                => [
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// POST /deployments/{id}/approve — Approve and execute.
		register_rest_route( self::NAMESPACE, '/deployments/(?P<id>\d+)/approve', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_approve' ],
			'permission_callback' => [ Auth::class, 'check_permission' ],
			'args'                => [
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// POST /deployments/{id}/reject — Reject deployment.
		register_rest_route( self::NAMESPACE, '/deployments/(?P<id>\d+)/reject', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_reject' ],
			'permission_callback' => [ Auth::class, 'check_permission' ],
			'args'                => [
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// POST /deployments/{id}/rollback — Rollback deployed change.
		register_rest_route( self::NAMESPACE, '/deployments/(?P<id>\d+)/rollback', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_rollback' ],
			'permission_callback' => [ Auth::class, 'check_permission' ],
			'args'                => [
				'id' => [
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// GET /status — Health check.
		register_rest_route( self::NAMESPACE, '/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_status' ],
			'permission_callback' => [ Auth::class, 'check_permission' ],
		] );
	}

	/**
	 * POST /deploy — Validate and stage files, create deployment record.
	 */
	public function handle_deploy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Kill switch check.
		if ( ! Auth::is_enabled() ) {
			return new \WP_Error( 'wpaic_disabled', 'WP AI Code is currently disabled.', [ 'status' => 503 ] );
		}

		// HTTPS check.
		$https_check = Auth::check_https();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		// Rate limiting.
		$rate_check = Auth::check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$deployment_name = $request->get_param( 'deployment_name' );
		$description     = $request->get_param( 'description' ) ?? '';
		$target_type     = $request->get_param( 'target_type' );
		$target_slug     = $request->get_param( 'target_slug' );
		$files           = $request->get_param( 'files' );

		// Validate files.
		$validator  = new Validator();
		$validation = $validator->validate_deployment( $files, $target_type, $target_slug );

		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'wpaic_validation_failed',
				'Deployment validation failed.',
				[
					'status'   => 422,
					'errors'   => $validation['errors'],
					'warnings' => $validation['warnings'],
				]
			);
		}

		// Create DB record.
		$store = new DeploymentStore();
		$id    = $store->insert_deployment( [
			'deployment_name' => $deployment_name,
			'description'     => $description,
			'target_type'     => $target_type,
			'target_slug'     => $target_slug,
			'status'          => 'pending',
			'files_manifest'  => wp_json_encode( $files ),
			'validation_log'  => wp_json_encode( $validation ),
		] );

		if ( false === $id ) {
			return new \WP_Error( 'wpaic_db_error', 'Failed to create deployment record.', [ 'status' => 500 ] );
		}

		// Stage files.
		$deployer     = new Deployer();
		$stage_result = $deployer->stage_files( $id, $files, $target_type, $target_slug );

		if ( is_wp_error( $stage_result ) ) {
			$store->update_status( $id, 'failed', [
				'validation_log' => wp_json_encode( [
					'valid'    => false,
					'errors'   => [ [ 'code' => 'staging_failed', 'message' => $stage_result->get_error_message() ] ],
					'warnings' => $validation['warnings'],
				] ),
			] );
			return $stage_result;
		}

		Auth::audit_log( 'deployment_submitted', [
			'deployment_id' => $id,
			'target'        => "{$target_type}/{$target_slug}",
			'file_count'    => count( $files ),
		] );

		$deployment = $store->get_deployment( $id );

		return new \WP_REST_Response( $this->format_deployment( $deployment ), 201 );
	}

	/**
	 * GET /deployments — List deployments with filters.
	 */
	public function handle_list_deployments( \WP_REST_Request $request ): \WP_REST_Response {
		$store  = new DeploymentStore();
		$result = $store->get_deployments( [
			'status'      => $request->get_param( 'status' ) ?? '',
			'target_type' => $request->get_param( 'target_type' ) ?? '',
			'per_page'    => $request->get_param( 'per_page' ),
			'page'        => $request->get_param( 'page' ),
		] );

		$items = array_map( [ $this, 'format_deployment' ], $result['items'] );

		$response = new \WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );

		return $response;
	}

	/**
	 * GET /deployments/{id} — Single deployment with file contents.
	 */
	public function handle_get_deployment( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store      = new DeploymentStore();
		$deployment = $store->get_deployment( $request->get_param( 'id' ) );

		if ( ! $deployment ) {
			return new \WP_Error( 'wpaic_not_found', 'Deployment not found.', [ 'status' => 404 ] );
		}

		return new \WP_REST_Response( $this->format_deployment( $deployment, true ) );
	}

	/**
	 * POST /deployments/{id}/approve — Execute the deployment.
	 */
	public function handle_approve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Auth::is_enabled() ) {
			return new \WP_Error( 'wpaic_disabled', 'WP AI Code is currently disabled.', [ 'status' => 503 ] );
		}

		$store      = new DeploymentStore();
		$deployment = $store->get_deployment( $request->get_param( 'id' ) );

		if ( ! $deployment ) {
			return new \WP_Error( 'wpaic_not_found', 'Deployment not found.', [ 'status' => 404 ] );
		}

		if ( 'pending' !== $deployment->status ) {
			return new \WP_Error(
				'wpaic_invalid_status',
				sprintf( 'Cannot approve a deployment with status "%s". Only pending deployments can be approved.', $deployment->status ),
				[ 'status' => 422 ]
			);
		}

		// Execute deployment.
		$deployer = new Deployer();
		$result   = $deployer->execute_deployment( (int) $deployment->id );

		if ( is_wp_error( $result ) ) {
			$store->update_status( (int) $deployment->id, 'failed' );
			Auth::audit_log( 'deployment_failed', [
				'deployment_id' => $deployment->id,
				'error'         => $result->get_error_message(),
			] );
			return $result;
		}

		$now = current_time( 'mysql', true );
		$store->update_status( (int) $deployment->id, 'deployed', [
			'reviewed_by' => get_current_user_id(),
			'reviewed_at' => $now,
			'deployed_at' => $now,
		] );

		Auth::audit_log( 'deployment_approved', [ 'deployment_id' => $deployment->id ] );

		$updated = $store->get_deployment( (int) $deployment->id );
		return new \WP_REST_Response( $this->format_deployment( $updated ) );
	}

	/**
	 * POST /deployments/{id}/reject — Mark as rejected.
	 */
	public function handle_reject( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$store      = new DeploymentStore();
		$deployment = $store->get_deployment( $request->get_param( 'id' ) );

		if ( ! $deployment ) {
			return new \WP_Error( 'wpaic_not_found', 'Deployment not found.', [ 'status' => 404 ] );
		}

		if ( 'pending' !== $deployment->status ) {
			return new \WP_Error(
				'wpaic_invalid_status',
				sprintf( 'Cannot reject a deployment with status "%s". Only pending deployments can be rejected.', $deployment->status ),
				[ 'status' => 422 ]
			);
		}

		$now = current_time( 'mysql', true );
		$store->update_status( (int) $deployment->id, 'rejected', [
			'reviewed_by' => get_current_user_id(),
			'reviewed_at' => $now,
		] );

		Auth::audit_log( 'deployment_rejected', [ 'deployment_id' => $deployment->id ] );

		$updated = $store->get_deployment( (int) $deployment->id );
		return new \WP_REST_Response( $this->format_deployment( $updated ) );
	}

	/**
	 * POST /deployments/{id}/rollback — Rollback a deployed change.
	 */
	public function handle_rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Auth::is_enabled() ) {
			return new \WP_Error( 'wpaic_disabled', 'WP AI Code is currently disabled.', [ 'status' => 503 ] );
		}

		$store      = new DeploymentStore();
		$deployment = $store->get_deployment( $request->get_param( 'id' ) );

		if ( ! $deployment ) {
			return new \WP_Error( 'wpaic_not_found', 'Deployment not found.', [ 'status' => 404 ] );
		}

		if ( 'deployed' !== $deployment->status ) {
			return new \WP_Error(
				'wpaic_invalid_status',
				sprintf( 'Cannot rollback a deployment with status "%s". Only deployed changes can be rolled back.', $deployment->status ),
				[ 'status' => 422 ]
			);
		}

		$deployer = new Deployer();
		$result   = $deployer->rollback_deployment( (int) $deployment->id );

		if ( is_wp_error( $result ) ) {
			Auth::audit_log( 'rollback_failed', [
				'deployment_id' => $deployment->id,
				'error'         => $result->get_error_message(),
			] );
			return $result;
		}

		$store->update_status( (int) $deployment->id, 'rolled_back', [
			'rolled_back_at' => current_time( 'mysql', true ),
		] );

		Auth::audit_log( 'deployment_rolled_back', [ 'deployment_id' => $deployment->id ] );

		$updated = $store->get_deployment( (int) $deployment->id );
		return new \WP_REST_Response( $this->format_deployment( $updated ) );
	}

	/**
	 * GET /status — Health check.
	 */
	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		$staging_writable = is_dir( WPAIC_STAGING_DIR ) && wp_is_writable( WPAIC_STAGING_DIR );
		$themes_writable  = wp_is_writable( get_theme_root() );
		$plugins_writable = wp_is_writable( WP_PLUGIN_DIR );

		return new \WP_REST_Response( [
			'version'     => WPAIC_VERSION,
			'php_version' => PHP_VERSION,
			'wp_version'  => get_bloginfo( 'version' ),
			'enabled'     => Auth::is_enabled(),
			'https'       => is_ssl(),
			'writable'    => [
				'staging' => $staging_writable,
				'themes'  => $themes_writable,
				'plugins' => $plugins_writable,
			],
			'limits'      => [
				'max_file_size'       => (int) get_option( 'wpaic_max_file_size', 512000 ),
				'max_deployment_size' => (int) get_option( 'wpaic_max_deployment_size', 5242880 ),
			],
		] );
	}

	/**
	 * Define deploy endpoint args schema.
	 */
	private function get_deploy_args(): array {
		return [
			'deployment_name' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					return ! empty( $value );
				},
			],
			'description'     => [
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'target_type'     => [
				'type'              => 'string',
				'required'          => true,
				'enum'              => [ 'theme', 'plugin', 'mu-plugin' ],
				'sanitize_callback' => 'sanitize_key',
			],
			'target_slug'     => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_file_name',
				'validate_callback' => function ( $value ) {
					return ! empty( $value ) && preg_match( '/^[a-z0-9\-_]+$/i', $value );
				},
			],
			'files'           => [
				'type'     => 'array',
				'required' => true,
				'items'    => [
					'type'       => 'object',
					'properties' => [
						'path'    => [
							'type'     => 'string',
							'required' => true,
						],
						'content' => [
							'type'     => 'string',
							'required' => true,
						],
					],
				],
				'validate_callback' => function ( $value ) {
					if ( ! is_array( $value ) || empty( $value ) ) {
						return false;
					}
					foreach ( $value as $file ) {
						if ( ! isset( $file['path'], $file['content'] ) ) {
							return false;
						}
					}
					return true;
				},
			],
		];
	}

	/**
	 * Format a deployment record for API response.
	 *
	 * @param object $deployment    DB row object.
	 * @param bool   $include_files Whether to include file contents.
	 * @return array
	 */
	private function format_deployment( object $deployment, bool $include_files = false ): array {
		$files_manifest = json_decode( $deployment->files_manifest ?? '[]', true ) ?: [];
		$validation_log = json_decode( $deployment->validation_log ?? '[]', true ) ?: [];

		$data = [
			'id'              => (int) $deployment->id,
			'deployment_name' => $deployment->deployment_name,
			'description'     => $deployment->description,
			'target_type'     => $deployment->target_type,
			'target_slug'     => $deployment->target_slug,
			'status'          => $deployment->status,
			'files_count'     => count( $files_manifest ),
			'validation'      => $validation_log,
			'created_by'      => (int) $deployment->created_by,
			'created_at'      => $deployment->created_at,
			'reviewed_by'     => $deployment->reviewed_by ? (int) $deployment->reviewed_by : null,
			'reviewed_at'     => $deployment->reviewed_at,
			'deployed_at'     => $deployment->deployed_at,
			'rolled_back_at'  => $deployment->rolled_back_at,
			'_links'          => [
				'self'     => [
					[ 'href' => rest_url( self::NAMESPACE . '/deployments/' . $deployment->id ) ],
				],
				'approve'  => [
					[ 'href' => rest_url( self::NAMESPACE . '/deployments/' . $deployment->id . '/approve' ) ],
				],
				'reject'   => [
					[ 'href' => rest_url( self::NAMESPACE . '/deployments/' . $deployment->id . '/reject' ) ],
				],
				'rollback' => [
					[ 'href' => rest_url( self::NAMESPACE . '/deployments/' . $deployment->id . '/rollback' ) ],
				],
			],
		];

		if ( $include_files ) {
			$data['files'] = $files_manifest;
		}

		return $data;
	}
}
