<?php
/**
 * Pattern Builder Security Helper
 *
 * Provides security utilities for file operations and path validation.
 *
 * @package Pattern_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Security helper class for Pattern Builder
 */
class Pattern_Builder_Security {

	/**
	 * Validate that a file path is within allowed directories.
	 *
	 * @param string $path The path to validate.
	 * @param array  $allowed_dirs Optional. Array of allowed base directories. Defaults to theme directory.
	 * @return bool|WP_Error True if path is valid, WP_Error otherwise.
	 */
	public static function validate_file_path( $path, $allowed_dirs = array() ) {
		// First normalize the path without realpath to handle non-existing files.
		$normalized_path = wp_normalize_path( $path );
		
		// If the file exists, use realpath for stronger validation.
		if ( file_exists( $path ) ) {
			$real_path = wp_normalize_path( realpath( $path ) );
			if ( false === $real_path ) {
				return new WP_Error(
					'invalid_path',
					__( 'Invalid file path provided.', 'pattern-builder' ),
					array( 'status' => 400 )
				);
			}
			$path = $real_path;
		} else {
			// For non-existing files, validate the normalized path.
			$path = $normalized_path;
		}

		// Default to theme directory if no allowed directories specified.
		if ( empty( $allowed_dirs ) ) {
			$allowed_dirs = array(
				wp_normalize_path( get_stylesheet_directory() ),
				wp_normalize_path( get_template_directory() ),
			);
		} else {
			// Normalize all allowed directories.
			$allowed_dirs = array_map( 'wp_normalize_path', $allowed_dirs );
		}

		// Check if the path starts with any of the allowed directories.
		$is_valid = false;
		foreach ( $allowed_dirs as $allowed_dir ) {
			if ( 0 === strpos( $path, $allowed_dir ) ) {
				$is_valid = true;
				break;
			}
		}

		if ( ! $is_valid ) {
			return new WP_Error(
				'path_traversal_detected',
				__( 'Path traversal attempt detected. Operation blocked.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		// Additional check for suspicious patterns.
		if ( preg_match( '/\.\.\/|\.\.\\\\/', $path ) ) {
			return new WP_Error(
				'suspicious_path',
				__( 'Suspicious path pattern detected. Operation blocked.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate pattern file path specifically.
	 *
	 * @param string $path The pattern file path to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_pattern_path( $path ) {
		// Pattern files should only be in the patterns directory.
		$allowed_dirs = array(
			wp_normalize_path( get_stylesheet_directory() . '/patterns' ),
			wp_normalize_path( get_template_directory() . '/patterns' ),
		);

		$validation = self::validate_file_path( $path, $allowed_dirs );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Ensure it's a PHP file.
		if ( '.php' !== substr( $path, -4 ) ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'Pattern files must be PHP files.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate asset file path.
	 *
	 * @param string $path The asset file path to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_asset_path( $path ) {
		// Assets should only be in the assets directory.
		$allowed_dirs = array(
			wp_normalize_path( get_stylesheet_directory() . '/assets' ),
			wp_normalize_path( get_template_directory() . '/assets' ),
		);

		return self::validate_file_path( $path, $allowed_dirs );
	}

	/**
	 * Initialize WordPress Filesystem.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function init_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'filesystem_init_failed',
				__( 'Failed to initialize WordPress filesystem.', 'pattern-builder' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Safely write content to a file using WordPress Filesystem API.
	 *
	 * @param string $path The file path.
	 * @param string $content The content to write.
	 * @param array  $allowed_dirs Optional. Allowed directories for the file.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function safe_file_write( $path, $content, $allowed_dirs = array() ) {
		// Validate the path first.
		$validation = self::validate_file_path( $path, $allowed_dirs );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Initialize filesystem.
		$fs_init = self::init_filesystem();
		if ( is_wp_error( $fs_init ) ) {
			return $fs_init;
		}

		global $wp_filesystem;

		// Ensure directory exists.
		$dir = dirname( $path );
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'directory_creation_failed',
					__( 'Failed to create directory.', 'pattern-builder' ),
					array( 'status' => 500 )
				);
			}
		}

		// Write the file.
		$result = $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );

		if ( false === $result ) {
			return new WP_Error(
				'file_write_failed',
				__( 'Failed to write file.', 'pattern-builder' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Safely delete a file using WordPress Filesystem API.
	 *
	 * @param string $path The file path to delete.
	 * @param array  $allowed_dirs Optional. Allowed directories for the file.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function safe_file_delete( $path, $allowed_dirs = array() ) {
		// Validate the path first.
		$validation = self::validate_file_path( $path, $allowed_dirs );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Initialize filesystem.
		$fs_init = self::init_filesystem();
		if ( is_wp_error( $fs_init ) ) {
			return $fs_init;
		}

		global $wp_filesystem;

		// Check if file exists.
		if ( ! $wp_filesystem->exists( $path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'File not found.', 'pattern-builder' ),
				array( 'status' => 404 )
			);
		}

		// Delete the file.
		$result = $wp_filesystem->delete( $path );

		if ( false === $result ) {
			return new WP_Error(
				'file_delete_failed',
				__( 'Failed to delete file.', 'pattern-builder' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Safely move a file using WordPress Filesystem API.
	 *
	 * @param string $source The source file path.
	 * @param string $destination The destination file path.
	 * @param array  $allowed_dirs Optional. Allowed directories for both paths.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function safe_file_move( $source, $destination, $allowed_dirs = array() ) {
		// Validate both paths.
		$source_validation = self::validate_file_path( $source, $allowed_dirs );
		if ( is_wp_error( $source_validation ) ) {
			return $source_validation;
		}

		$dest_validation = self::validate_file_path( $destination, $allowed_dirs );
		if ( is_wp_error( $dest_validation ) ) {
			return $dest_validation;
		}

		// Initialize filesystem.
		$fs_init = self::init_filesystem();
		if ( is_wp_error( $fs_init ) ) {
			return $fs_init;
		}

		global $wp_filesystem;

		// Ensure destination directory exists.
		$dest_dir = dirname( $destination );
		if ( ! $wp_filesystem->is_dir( $dest_dir ) ) {
			if ( ! wp_mkdir_p( $dest_dir ) ) {
				return new WP_Error(
					'directory_creation_failed',
					__( 'Failed to create destination directory.', 'pattern-builder' ),
					array( 'status' => 500 )
				);
			}
		}

		// Move the file.
		$result = $wp_filesystem->move( $source, $destination, true );

		if ( false === $result ) {
			return new WP_Error(
				'file_move_failed',
				__( 'Failed to move file.', 'pattern-builder' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Sanitize a filename for safe use.
	 *
	 * @param string $filename The filename to sanitize.
	 * @return string Sanitized filename.
	 */
	public static function sanitize_filename( $filename ) {
		// Use WordPress built-in sanitization.
		$filename = sanitize_file_name( $filename );

		// Remove any remaining directory traversal attempts.
		$filename = str_replace( array( '..', '/', '\\' ), '', $filename );

		// Ensure it has a valid extension for patterns.
		if ( ! preg_match( '/\.(php|json|html?)$/i', $filename ) ) {
			$filename .= '.php';
		}

		return $filename;
	}

	/**
	 * Log an error with context for debugging purposes.
	 * 
	 * Respects WordPress debug settings and provides consistent error logging
	 * throughout the plugin.
	 *
	 * @param string $message   The error message to log.
	 * @param string $context   Optional. Context where the error occurred (method name, etc.).
	 * @param mixed  $data      Optional. Additional data to log (will be serialized).
	 * @param string $level     Optional. Error level: 'error', 'warning', 'info', 'debug'. Default 'error'.
	 */
	public static function log_error( $message, $context = '', $data = null, $level = 'error' ) {
		// Only log if WordPress debugging is enabled
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Build the log message
		$log_message = '[Pattern Builder] ';
		
		if ( ! empty( $context ) ) {
			$log_message .= "[$context] ";
		}
		
		$log_message .= $message;
		
		if ( ! is_null( $data ) ) {
			$log_message .= ' | Data: ' . wp_json_encode( $data );
		}

		// Log to WordPress debug log if enabled
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( $log_message );
		}
		
		// Also trigger WordPress action for extensibility
		do_action( 'pattern_builder_log_error', $level, $message, $context, $data );
	}

	/**
	 * Create a standardized WP_Error with optional logging.
	 *
	 * @param string $code       Error code.
	 * @param string $message    Error message.
	 * @param array  $data       Optional. Error data array.
	 * @param string $context    Optional. Context where error occurred.
	 * @param bool   $log_error  Optional. Whether to log this error. Default true.
	 * @return WP_Error
	 */
	public static function create_error( $code, $message, $data = array(), $context = '', $log_error = true ) {
		if ( $log_error ) {
			self::log_error( $message, $context, array( 'code' => $code, 'data' => $data ) );
		}

		return new WP_Error( $code, $message, $data );
	}
}
