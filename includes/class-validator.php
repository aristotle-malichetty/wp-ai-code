<?php

namespace WPAICode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File validation pipeline for deployment submissions.
 */
class Validator {

	/**
	 * Allowed file extensions.
	 */
	private const ALLOWED_EXTENSIONS = [
		'php', 'css', 'js', 'json', 'txt', 'md', 'html', 'twig',
		'svg', 'png', 'jpg', 'jpeg', 'gif',
		'woff', 'woff2', 'ttf', 'eot',
	];

	/**
	 * Dangerous PHP patterns to flag as warnings.
	 */
	private const DANGEROUS_PATTERNS = [
		'eval\s*\('                     => 'eval() usage detected',
		'shell_exec\s*\('               => 'shell_exec() usage detected',
		'\bexec\s*\('                   => 'exec() usage detected',
		'system\s*\('                   => 'system() usage detected',
		'passthru\s*\('                 => 'passthru() usage detected',
		'proc_open\s*\('                => 'proc_open() usage detected',
		'popen\s*\('                    => 'popen() usage detected',
		'base64_decode\s*\([^)]*\)\s*\)' => 'base64_decode() used in nested call (possible execution)',
		'preg_replace\s*\(\s*[\'"][^"\']*\/e' => 'preg_replace with /e modifier detected',
		'(include|require)(_once)?\s*\(?\s*[\'"]https?://' => 'Remote file include detected',
		'file_get_contents\s*\(\s*[\'"]https?://' => 'Remote file_get_contents detected',
		'\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\s*\[' => 'Direct superglobal access (consider sanitization)',
	];

	/**
	 * Run all validation checks on a set of files.
	 *
	 * @param array  $files       Array of [ 'path' => string, 'content' => string ].
	 * @param string $target_type Target type (theme, plugin, mu-plugin).
	 * @param string $target_slug Target slug.
	 * @return array{ valid: bool, errors: array, warnings: array }
	 */
	public function validate_deployment( array $files, string $target_type, string $target_slug ): array {
		$errors   = [];
		$warnings = [];

		$max_file_size       = (int) get_option( 'wpaic_max_file_size', 512000 );
		$max_deployment_size = (int) get_option( 'wpaic_max_deployment_size', 5242880 );
		$allowed_targets     = (array) get_option( 'wpaic_allowed_targets', [ 'theme', 'plugin', 'mu-plugin' ] );

		// Check target type is allowed.
		if ( ! in_array( $target_type, $allowed_targets, true ) ) {
			$errors[] = [
				'code'    => 'invalid_target_type',
				'message' => sprintf( 'Target type "%s" is not allowed.', $target_type ),
			];
		}

		// Check target slug is valid.
		if ( empty( $target_slug ) || ! preg_match( '/^[a-z0-9\-_]+$/i', $target_slug ) ) {
			$errors[] = [
				'code'    => 'invalid_target_slug',
				'message' => 'Target slug must contain only alphanumeric characters, hyphens, and underscores.',
			];
		}

		if ( empty( $files ) ) {
			$errors[] = [
				'code'    => 'no_files',
				'message' => 'No files provided in the deployment.',
			];
		}

		$total_size = 0;

		foreach ( $files as $index => $file ) {
			$path    = $file['path'] ?? '';
			$content = $file['content'] ?? '';
			$label   = $path ?: "file[{$index}]";

			// Path validation.
			$path_result = $this->validate_path( $path );
			if ( is_wp_error( $path_result ) ) {
				$errors[] = [
					'code'    => 'invalid_path',
					'message' => "{$label}: " . $path_result->get_error_message(),
				];
				continue;
			}

			// File type validation.
			$type_result = $this->validate_file_type( $path );
			if ( is_wp_error( $type_result ) ) {
				$errors[] = [
					'code'    => 'invalid_file_type',
					'message' => "{$label}: " . $type_result->get_error_message(),
				];
				continue;
			}

			// File size validation.
			$size_result = $this->validate_file_size( $content, $max_file_size );
			if ( is_wp_error( $size_result ) ) {
				$errors[] = [
					'code'    => 'file_too_large',
					'message' => "{$label}: " . $size_result->get_error_message(),
				];
			}

			$total_size += strlen( $content );

			// PHP-specific checks.
			if ( str_ends_with( strtolower( $path ), '.php' ) ) {
				$syntax_result = $this->check_php_syntax( $content );
				if ( is_wp_error( $syntax_result ) ) {
					$errors[] = [
						'code'    => 'php_syntax_error',
						'message' => "{$label}: " . $syntax_result->get_error_message(),
					];
				}

				$dangerous = $this->scan_dangerous_patterns( $content );
				foreach ( $dangerous as $warning ) {
					$warnings[] = [
						'code'    => 'dangerous_pattern',
						'message' => "{$label}: {$warning}",
					];
				}
			}
		}

		// Total deployment size.
		if ( $total_size > $max_deployment_size ) {
			$errors[] = [
				'code'    => 'deployment_too_large',
				'message' => sprintf(
					'Total deployment size (%s) exceeds the maximum allowed (%s).',
					size_format( $total_size ),
					size_format( $max_deployment_size )
				),
			];
		}

		return [
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * Validate a file path for safety.
	 *
	 * @param string $path Relative file path.
	 * @return true|\WP_Error
	 */
	public function validate_path( string $path ): bool|\WP_Error {
		if ( empty( $path ) ) {
			return new \WP_Error( 'empty_path', 'File path cannot be empty.' );
		}

		// No directory traversal.
		if ( str_contains( $path, '..' ) ) {
			return new \WP_Error( 'path_traversal', 'Path traversal (..) is not allowed.' );
		}

		// No absolute paths.
		if ( str_starts_with( $path, '/' ) || preg_match( '/^[a-zA-Z]:/', $path ) ) {
			return new \WP_Error( 'absolute_path', 'Absolute paths are not allowed.' );
		}

		// No null bytes.
		if ( str_contains( $path, "\0" ) ) {
			return new \WP_Error( 'null_byte', 'Null bytes in paths are not allowed.' );
		}

		// Must not target WordPress core directories.
		$core_prefixes = [ 'wp-admin', 'wp-includes', 'wp-content/uploads' ];
		foreach ( $core_prefixes as $prefix ) {
			if ( str_starts_with( strtolower( $path ), $prefix ) ) {
				return new \WP_Error( 'core_path', "Cannot deploy to WordPress core directory: {$prefix}" );
			}
		}

		return true;
	}

	/**
	 * Validate file extension against allowlist.
	 *
	 * @param string $path File path.
	 * @return true|\WP_Error
	 */
	public function validate_file_type( string $path ): bool|\WP_Error {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( empty( $extension ) ) {
			return new \WP_Error( 'no_extension', 'File must have an extension.' );
		}

		if ( ! in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ) {
			return new \WP_Error(
				'disallowed_extension',
				sprintf( 'File extension ".%s" is not allowed.', $extension )
			);
		}

		return true;
	}

	/**
	 * Validate file size.
	 *
	 * @param string $content File content.
	 * @param int    $max     Maximum size in bytes.
	 * @return true|\WP_Error
	 */
	public function validate_file_size( string $content, int $max ): bool|\WP_Error {
		$size = strlen( $content );

		if ( $size > $max ) {
			return new \WP_Error(
				'file_too_large',
				sprintf(
					'File size (%s) exceeds the maximum allowed (%s).',
					size_format( $size ),
					size_format( $max )
				)
			);
		}

		return true;
	}

	/**
	 * Check PHP syntax of file content.
	 * Uses `php -l` if exec() is available, otherwise falls back to regex checks.
	 *
	 * @param string $content PHP file content.
	 * @return true|\WP_Error
	 */
	public function check_php_syntax( string $content ): bool|\WP_Error {
		// Try php -l if exec is available.
		if ( function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true ) ) {
			$temp_file = tempnam( sys_get_temp_dir(), 'wpaic_syntax_' );
			if ( false !== $temp_file ) {
				file_put_contents( $temp_file, $content );
				$output = [];
				$code   = 0;
				exec( sprintf( 'php -l %s 2>&1', escapeshellarg( $temp_file ) ), $output, $code );
				unlink( $temp_file );

				if ( 0 !== $code ) {
					$error_msg = implode( "\n", $output );

					// If php binary not found, fall through to regex check.
					if ( str_contains( $error_msg, 'command not found' ) || str_contains( $error_msg, 'not recognized' ) ) {
						// Fall through to regex fallback below.
					} else {
						$error_msg = str_replace( $temp_file, 'file', $error_msg );
						return new \WP_Error( 'php_syntax', "PHP syntax error: {$error_msg}" );
					}
				} else {
					return true;
				}
			}
		}

		// Regex fallback: check for obviously broken PHP.
		$open_braces  = substr_count( $content, '{' );
		$close_braces = substr_count( $content, '}' );
		if ( $open_braces !== $close_braces ) {
			return new \WP_Error(
				'php_syntax',
				sprintf( 'Mismatched braces: %d opening, %d closing.', $open_braces, $close_braces )
			);
		}

		return true;
	}

	/**
	 * Scan content for dangerous PHP patterns.
	 *
	 * @param string $content File content.
	 * @return string[] Array of warning messages.
	 */
	public function scan_dangerous_patterns( string $content ): array {
		$warnings = [];

		foreach ( self::DANGEROUS_PATTERNS as $pattern => $message ) {
			if ( preg_match( '/' . $pattern . '/i', $content ) ) {
				$warnings[] = $message;
			}
		}

		return $warnings;
	}
}
