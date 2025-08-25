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
		// Normalize the path to prevent traversal attempts.
		$path = wp_normalize_path( realpath( $path ) );

		if ( false === $path ) {
			return new WP_Error(
				'invalid_path',
				__( 'Invalid file path provided.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
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
}
