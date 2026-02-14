<?php

namespace WPAICode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin dashboard: menu registration, asset enqueueing, settings, page rendering.
 */
class AdminPage {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_wpaic_approve', [ $this, 'handle_approve_action' ] );
		add_action( 'admin_post_wpaic_reject', [ $this, 'handle_reject_action' ] );
		add_action( 'admin_post_wpaic_rollback', [ $this, 'handle_rollback_action' ] );
	}

	/**
	 * Register admin menu pages.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'AI Code', 'wp-ai-code' ),
			__( 'AI Code', 'wp-ai-code' ),
			'manage_options',
			'wpaic-dashboard',
			[ $this, 'render_dashboard' ],
			'dashicons-cloud-upload',
			80
		);

		add_submenu_page(
			'wpaic-dashboard',
			__( 'Deployments', 'wp-ai-code' ),
			__( 'Deployments', 'wp-ai-code' ),
			'manage_options',
			'wpaic-dashboard',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'wpaic-dashboard',
			__( 'Settings', 'wp-ai-code' ),
			__( 'Settings', 'wp-ai-code' ),
			'manage_options',
			'wpaic-settings',
			[ $this, 'render_settings' ]
		);
	}

	/**
	 * Register plugin settings with the Settings API.
	 */
	public function register_settings(): void {
		register_setting( 'wpaic_settings', 'wpaic_enabled', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		] );

		register_setting( 'wpaic_settings', 'wpaic_allowed_targets', [
			'type'              => 'array',
			'sanitize_callback' => function ( $value ) {
				if ( ! is_array( $value ) ) {
					return [ 'theme', 'plugin', 'mu-plugin' ];
				}
				$allowed = [ 'theme', 'plugin', 'mu-plugin' ];
				return array_values( array_intersect( $value, $allowed ) );
			},
			'default'           => [ 'theme', 'plugin', 'mu-plugin' ],
		] );

		register_setting( 'wpaic_settings', 'wpaic_max_file_size', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 512000,
		] );

		register_setting( 'wpaic_settings', 'wpaic_max_deployment_size', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 5242880,
		] );

		register_setting( 'wpaic_settings', 'wpaic_cleanup_days', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 30,
		] );

		register_setting( 'wpaic_settings', 'wpaic_notify_email', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		// Settings sections.
		add_settings_section(
			'wpaic_general',
			__( 'General Settings', 'wp-ai-code' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how WP AI Code operates.', 'wp-ai-code' ) . '</p>';
			},
			'wpaic_settings'
		);

		// Fields.
		add_settings_field( 'wpaic_enabled', __( 'Enable Plugin', 'wp-ai-code' ), [ $this, 'render_enabled_field' ], 'wpaic_settings', 'wpaic_general' );
		add_settings_field( 'wpaic_allowed_targets', __( 'Allowed Targets', 'wp-ai-code' ), [ $this, 'render_targets_field' ], 'wpaic_settings', 'wpaic_general' );
		add_settings_field( 'wpaic_max_file_size', __( 'Max File Size', 'wp-ai-code' ), [ $this, 'render_file_size_field' ], 'wpaic_settings', 'wpaic_general' );
		add_settings_field( 'wpaic_max_deployment_size', __( 'Max Deployment Size', 'wp-ai-code' ), [ $this, 'render_deployment_size_field' ], 'wpaic_settings', 'wpaic_general' );
		add_settings_field( 'wpaic_cleanup_days', __( 'Cleanup After (Days)', 'wp-ai-code' ), [ $this, 'render_cleanup_field' ], 'wpaic_settings', 'wpaic_general' );
		add_settings_field( 'wpaic_notify_email', __( 'Email Notifications', 'wp-ai-code' ), [ $this, 'render_notify_field' ], 'wpaic_settings', 'wpaic_general' );
	}

	/**
	 * Enqueue admin CSS and JS on plugin pages only.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'wpaic-' ) ) {
			return;
		}

		wp_enqueue_style(
			'wpaic-admin',
			WPAIC_PLUGIN_URL . 'admin/css/admin.css',
			[],
			WPAIC_VERSION
		);

		wp_enqueue_script(
			'wpaic-admin',
			WPAIC_PLUGIN_URL . 'admin/js/admin.js',
			[],
			WPAIC_VERSION,
			true
		);

		wp_localize_script( 'wpaic-admin', 'wpaicAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wpaic_admin_nonce' ),
			'i18n'    => [
				'confirmApprove'  => __( 'Are you sure you want to approve this deployment? Files will be deployed to the target directory.', 'wp-ai-code' ),
				'confirmReject'   => __( 'Are you sure you want to reject this deployment?', 'wp-ai-code' ),
				'confirmRollback' => __( 'Are you sure you want to rollback this deployment? Original files will be restored.', 'wp-ai-code' ),
			],
		] );
	}

	/**
	 * Render the deployments dashboard page.
	 */
	public function render_dashboard(): void {
		// Check if viewing a single deployment.
		$deployment_id = isset( $_GET['deployment_id'] ) ? absint( $_GET['deployment_id'] ) : 0;

		if ( $deployment_id > 0 ) {
			$store      = new DeploymentStore();
			$deployment = $store->get_deployment( $deployment_id );

			if ( $deployment ) {
				include WPAIC_PLUGIN_DIR . 'admin/views/deployment-detail.php';
				return;
			}
		}

		include WPAIC_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings(): void {
		include WPAIC_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Handle approve action from admin form.
	 */
	public function handle_approve_action(): void {
		$this->verify_admin_action( 'wpaic_approve' );

		$id    = absint( $_POST['deployment_id'] ?? 0 );
		$store = new DeploymentStore();
		$dep   = $store->get_deployment( $id );

		if ( ! $dep || 'pending' !== $dep->status ) {
			wp_die( esc_html__( 'Invalid deployment or status.', 'wp-ai-code' ) );
		}

		if ( ! Auth::is_enabled() ) {
			wp_die( esc_html__( 'WP AI Code is currently disabled.', 'wp-ai-code' ) );
		}

		$deployer = new Deployer();
		$result   = $deployer->execute_deployment( $id );

		if ( is_wp_error( $result ) ) {
			$store->update_status( $id, 'failed' );
			Auth::audit_log( 'deployment_failed', [ 'deployment_id' => $id, 'error' => $result->get_error_message() ] );
			wp_redirect( add_query_arg( [ 'page' => 'wpaic-dashboard', 'deployment_id' => $id, 'wpaic_error' => 'deploy_failed' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$now = current_time( 'mysql', true );
		$store->update_status( $id, 'deployed', [
			'reviewed_by' => get_current_user_id(),
			'reviewed_at' => $now,
			'deployed_at' => $now,
		] );

		Auth::audit_log( 'deployment_approved', [ 'deployment_id' => $id ] );

		wp_redirect( add_query_arg( [ 'page' => 'wpaic-dashboard', 'deployment_id' => $id, 'wpaic_notice' => 'approved' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle reject action from admin form.
	 */
	public function handle_reject_action(): void {
		$this->verify_admin_action( 'wpaic_reject' );

		$id    = absint( $_POST['deployment_id'] ?? 0 );
		$store = new DeploymentStore();
		$dep   = $store->get_deployment( $id );

		if ( ! $dep || 'pending' !== $dep->status ) {
			wp_die( esc_html__( 'Invalid deployment or status.', 'wp-ai-code' ) );
		}

		$now = current_time( 'mysql', true );
		$store->update_status( $id, 'rejected', [
			'reviewed_by' => get_current_user_id(),
			'reviewed_at' => $now,
		] );

		Auth::audit_log( 'deployment_rejected', [ 'deployment_id' => $id ] );

		wp_redirect( add_query_arg( [ 'page' => 'wpaic-dashboard', 'deployment_id' => $id, 'wpaic_notice' => 'rejected' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle rollback action from admin form.
	 */
	public function handle_rollback_action(): void {
		$this->verify_admin_action( 'wpaic_rollback' );

		$id    = absint( $_POST['deployment_id'] ?? 0 );
		$store = new DeploymentStore();
		$dep   = $store->get_deployment( $id );

		if ( ! $dep || 'deployed' !== $dep->status ) {
			wp_die( esc_html__( 'Invalid deployment or status.', 'wp-ai-code' ) );
		}

		if ( ! Auth::is_enabled() ) {
			wp_die( esc_html__( 'WP AI Code is currently disabled.', 'wp-ai-code' ) );
		}

		$deployer = new Deployer();
		$result   = $deployer->rollback_deployment( $id );

		if ( is_wp_error( $result ) ) {
			Auth::audit_log( 'rollback_failed', [ 'deployment_id' => $id, 'error' => $result->get_error_message() ] );
			wp_redirect( add_query_arg( [ 'page' => 'wpaic-dashboard', 'deployment_id' => $id, 'wpaic_error' => 'rollback_failed' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$store->update_status( $id, 'rolled_back', [
			'rolled_back_at' => current_time( 'mysql', true ),
		] );

		Auth::audit_log( 'deployment_rolled_back', [ 'deployment_id' => $id ] );

		wp_redirect( add_query_arg( [ 'page' => 'wpaic-dashboard', 'deployment_id' => $id, 'wpaic_notice' => 'rolled_back' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Verify nonce and capabilities for admin POST actions.
	 */
	private function verify_admin_action( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-ai-code' ) );
		}

		check_admin_referer( $action );
	}

	// --- Settings field renderers ---

	public function render_enabled_field(): void {
		$value = (bool) get_option( 'wpaic_enabled', true );
		printf(
			'<label><input type="checkbox" name="wpaic_enabled" value="1" %s /> %s</label>',
			checked( $value, true, false ),
			esc_html__( 'Enable WP AI Code API and deployments', 'wp-ai-code' )
		);
	}

	public function render_targets_field(): void {
		$value   = (array) get_option( 'wpaic_allowed_targets', [ 'theme', 'plugin', 'mu-plugin' ] );
		$targets = [
			'theme'     => __( 'Themes', 'wp-ai-code' ),
			'plugin'    => __( 'Plugins', 'wp-ai-code' ),
			'mu-plugin' => __( 'Must-Use Plugins', 'wp-ai-code' ),
		];

		foreach ( $targets as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="wpaic_allowed_targets[]" value="%s" %s /> %s</label><br>',
				esc_attr( $key ),
				checked( in_array( $key, $value, true ), true, false ),
				esc_html( $label )
			);
		}
	}

	public function render_file_size_field(): void {
		$value = (int) get_option( 'wpaic_max_file_size', 512000 );
		printf(
			'<input type="number" name="wpaic_max_file_size" value="%d" min="1024" step="1024" class="small-text" /> <span class="description">%s (%s)</span>',
			$value,
			esc_html__( 'bytes', 'wp-ai-code' ),
			esc_html( size_format( $value ) )
		);
	}

	public function render_deployment_size_field(): void {
		$value = (int) get_option( 'wpaic_max_deployment_size', 5242880 );
		printf(
			'<input type="number" name="wpaic_max_deployment_size" value="%d" min="1024" step="1024" class="small-text" /> <span class="description">%s (%s)</span>',
			$value,
			esc_html__( 'bytes', 'wp-ai-code' ),
			esc_html( size_format( $value ) )
		);
	}

	public function render_cleanup_field(): void {
		$value = (int) get_option( 'wpaic_cleanup_days', 30 );
		printf(
			'<input type="number" name="wpaic_cleanup_days" value="%d" min="1" max="365" class="small-text" /> <span class="description">%s</span>',
			$value,
			esc_html__( 'days', 'wp-ai-code' )
		);
	}

	public function render_notify_field(): void {
		$value = (bool) get_option( 'wpaic_notify_email', false );
		printf(
			'<label><input type="checkbox" name="wpaic_notify_email" value="1" %s /> %s</label>',
			checked( $value, true, false ),
			esc_html__( 'Send email notification to admin when new deployments are submitted', 'wp-ai-code' )
		);
	}
}
