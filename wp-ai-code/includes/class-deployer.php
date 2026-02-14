<?php

namespace WPAICode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles file staging, deployment execution, rollback, and cleanup.
 * All file operations use the WP_Filesystem API.
 */
class Deployer {

	private ?\WP_Filesystem_Base $filesystem = null;

	/**
	 * Initialize WP_Filesystem.
	 */
	private function init_filesystem(): bool {
		if ( null !== $this->filesystem ) {
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$creds = request_filesystem_credentials( '', '', false, false, null );
		if ( false === $creds ) {
			return false;
		}

		if ( ! WP_Filesystem( $creds ) ) {
			return false;
		}

		global $wp_filesystem;
		$this->filesystem = $wp_filesystem;

		return true;
	}

	/**
	 * Stage files for a deployment.
	 *
	 * Writes files to wp-content/wpaic-staging/{deployment_id}/files/
	 * and creates a manifest.json.
	 *
	 * @param int    $deployment_id Deployment ID.
	 * @param array  $files         Array of [ 'path' => string, 'content' => string ].
	 * @param string $target_type   Target type (theme, plugin, mu-plugin).
	 * @param string $target_slug   Target slug.
	 * @return true|\WP_Error
	 */
	public function stage_files( int $deployment_id, array $files, string $target_type, string $target_slug ): bool|\WP_Error {
		if ( ! $this->init_filesystem() ) {
			return new \WP_Error( 'filesystem_error', 'Unable to initialize WordPress filesystem.' );
		}

		$staging_base = WPAIC_STAGING_DIR . "/{$deployment_id}";
		$files_dir    = "{$staging_base}/files";
		$backups_dir  = "{$staging_base}/backups";

		// Create directories.
		foreach ( [ $staging_base, $files_dir, $backups_dir ] as $dir ) {
			if ( ! $this->filesystem->is_dir( $dir ) ) {
				if ( ! $this->filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
					return new \WP_Error( 'mkdir_failed', "Failed to create directory: {$dir}" );
				}
			}
		}

		// Protect staging dir.
		$this->write_protection_files( $staging_base );

		$manifest = [];

		foreach ( $files as $file ) {
			$path    = $file['path'];
			$content = $file['content'];
			$dest    = "{$files_dir}/{$path}";

			// Create subdirectories as needed.
			$subdir = dirname( $dest );
			if ( ! $this->filesystem->is_dir( $subdir ) ) {
				if ( ! wp_mkdir_p( $subdir ) ) {
					return new \WP_Error( 'mkdir_failed', "Failed to create directory: {$subdir}" );
				}
			}

			if ( ! $this->filesystem->put_contents( $dest, $content, FS_CHMOD_FILE ) ) {
				return new \WP_Error( 'write_failed', "Failed to write staged file: {$path}" );
			}

			$manifest[] = [
				'path' => $path,
				'hash' => hash( 'sha256', $content ),
				'size' => strlen( $content ),
			];
		}

		// Write manifest.
		$manifest_content = wp_json_encode( [
			'deployment_id' => $deployment_id,
			'target_type'   => $target_type,
			'target_slug'   => $target_slug,
			'files'         => $manifest,
			'staged_at'     => current_time( 'mysql', true ),
		], JSON_PRETTY_PRINT );

		if ( ! $this->filesystem->put_contents( "{$staging_base}/manifest.json", $manifest_content, FS_CHMOD_FILE ) ) {
			return new \WP_Error( 'manifest_failed', 'Failed to write manifest.json.' );
		}

		return true;
	}

	/**
	 * Execute a deployment: backup originals, copy staged files to target.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @return true|\WP_Error
	 */
	public function execute_deployment( int $deployment_id ): bool|\WP_Error {
		if ( ! $this->init_filesystem() ) {
			return new \WP_Error( 'filesystem_error', 'Unable to initialize WordPress filesystem.' );
		}

		$staging_base  = WPAIC_STAGING_DIR . "/{$deployment_id}";
		$manifest_path = "{$staging_base}/manifest.json";

		if ( ! $this->filesystem->exists( $manifest_path ) ) {
			return new \WP_Error( 'no_manifest', 'Deployment manifest not found.' );
		}

		$manifest = json_decode( $this->filesystem->get_contents( $manifest_path ), true );
		if ( ! $manifest ) {
			return new \WP_Error( 'invalid_manifest', 'Failed to parse deployment manifest.' );
		}

		$target_dir = $this->get_target_directory( $manifest['target_type'], $manifest['target_slug'] );
		if ( is_wp_error( $target_dir ) ) {
			return $target_dir;
		}

		$files_dir   = "{$staging_base}/files";
		$backups_dir = "{$staging_base}/backups";

		// Phase 1: Backup existing files.
		foreach ( $manifest['files'] as $file_info ) {
			$target_file = "{$target_dir}/{$file_info['path']}";

			if ( $this->filesystem->exists( $target_file ) ) {
				$backup_dest   = "{$backups_dir}/{$file_info['path']}";
				$backup_subdir = dirname( $backup_dest );

				if ( ! $this->filesystem->is_dir( $backup_subdir ) ) {
					wp_mkdir_p( $backup_subdir );
				}

				$original_content = $this->filesystem->get_contents( $target_file );
				if ( false === $original_content ) {
					return new \WP_Error( 'backup_failed', "Failed to read original file for backup: {$file_info['path']}" );
				}

				if ( ! $this->filesystem->put_contents( $backup_dest, $original_content, FS_CHMOD_FILE ) ) {
					return new \WP_Error( 'backup_failed', "Failed to write backup: {$file_info['path']}" );
				}
			}
		}

		// Phase 2: Deploy staged files.
		foreach ( $manifest['files'] as $file_info ) {
			$source = "{$files_dir}/{$file_info['path']}";
			$dest   = "{$target_dir}/{$file_info['path']}";

			// Create target subdirectories.
			$dest_subdir = dirname( $dest );
			if ( ! $this->filesystem->is_dir( $dest_subdir ) ) {
				if ( ! wp_mkdir_p( $dest_subdir ) ) {
					return new \WP_Error( 'deploy_failed', "Failed to create target directory for: {$file_info['path']}" );
				}
			}

			$staged_content = $this->filesystem->get_contents( $source );
			if ( false === $staged_content ) {
				return new \WP_Error( 'deploy_failed', "Failed to read staged file: {$file_info['path']}" );
			}

			if ( ! $this->filesystem->put_contents( $dest, $staged_content, FS_CHMOD_FILE ) ) {
				return new \WP_Error( 'deploy_failed', "Failed to deploy file: {$file_info['path']}" );
			}

			// Verify hash.
			$deployed_hash = hash( 'sha256', $this->filesystem->get_contents( $dest ) );
			if ( $deployed_hash !== $file_info['hash'] ) {
				return new \WP_Error( 'hash_mismatch', "Hash mismatch after deploying: {$file_info['path']}" );
			}
		}

		return true;
	}

	/**
	 * Rollback a deployment: restore backups, remove new files.
	 *
	 * @param int $deployment_id Deployment ID.
	 * @return true|\WP_Error
	 */
	public function rollback_deployment( int $deployment_id ): bool|\WP_Error {
		if ( ! $this->init_filesystem() ) {
			return new \WP_Error( 'filesystem_error', 'Unable to initialize WordPress filesystem.' );
		}

		$staging_base  = WPAIC_STAGING_DIR . "/{$deployment_id}";
		$manifest_path = "{$staging_base}/manifest.json";

		if ( ! $this->filesystem->exists( $manifest_path ) ) {
			return new \WP_Error( 'no_manifest', 'Deployment manifest not found for rollback.' );
		}

		$manifest = json_decode( $this->filesystem->get_contents( $manifest_path ), true );
		if ( ! $manifest ) {
			return new \WP_Error( 'invalid_manifest', 'Failed to parse deployment manifest.' );
		}

		$target_dir = $this->get_target_directory( $manifest['target_type'], $manifest['target_slug'] );
		if ( is_wp_error( $target_dir ) ) {
			return $target_dir;
		}

		$backups_dir = "{$staging_base}/backups";

		foreach ( $manifest['files'] as $file_info ) {
			$target_file = "{$target_dir}/{$file_info['path']}";
			$backup_file = "{$backups_dir}/{$file_info['path']}";

			if ( $this->filesystem->exists( $backup_file ) ) {
				// Restore from backup.
				$backup_content = $this->filesystem->get_contents( $backup_file );
				$this->filesystem->put_contents( $target_file, $backup_content, FS_CHMOD_FILE );
			} elseif ( $this->filesystem->exists( $target_file ) ) {
				// No backup means this was a new file — remove it.
				$this->filesystem->delete( $target_file );
			}
		}

		return true;
	}

	/**
	 * Clean up old staging directories.
	 *
	 * @param int $days Remove staging dirs older than this many days.
	 */
	public function cleanup_old_staging( int $days ): void {
		if ( ! $this->init_filesystem() || ! is_dir( WPAIC_STAGING_DIR ) ) {
			return;
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$dirs   = glob( WPAIC_STAGING_DIR . '/*', GLOB_ONLYDIR );

		if ( ! is_array( $dirs ) ) {
			return;
		}

		foreach ( $dirs as $dir ) {
			$manifest_path = "{$dir}/manifest.json";
			if ( ! file_exists( $manifest_path ) ) {
				continue;
			}

			$modified = filemtime( $manifest_path );
			if ( $modified && $modified < $cutoff ) {
				$this->filesystem->delete( $dir, true );
			}
		}
	}

	/**
	 * Resolve target directory path based on type and slug.
	 *
	 * @param string $type Target type.
	 * @param string $slug Target slug.
	 * @return string|\WP_Error
	 */
	private function get_target_directory( string $type, string $slug ): string|\WP_Error {
		$slug = sanitize_file_name( $slug );

		return match ( $type ) {
			'theme'     => get_theme_root() . "/{$slug}",
			'plugin'    => WP_PLUGIN_DIR . "/{$slug}",
			'mu-plugin' => WPMU_PLUGIN_DIR,
			default     => new \WP_Error( 'invalid_target', "Unknown target type: {$type}" ),
		};
	}

	/**
	 * Write .htaccess and index.php to protect a directory from web access.
	 */
	private function write_protection_files( string $dir ): void {
		$htaccess = "{$dir}/.htaccess";
		if ( ! $this->filesystem->exists( $htaccess ) ) {
			$this->filesystem->put_contents( $htaccess, "Deny from all\n", FS_CHMOD_FILE );
		}

		$index = "{$dir}/index.php";
		if ( ! $this->filesystem->exists( $index ) ) {
			$this->filesystem->put_contents( $index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
	}
}
