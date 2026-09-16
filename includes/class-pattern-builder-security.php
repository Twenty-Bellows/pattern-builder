<?php
/**
 * Pattern Builder Security Helper
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security helper class for Pattern Builder
 */
class Pattern_Builder_Security {
	/**
	 * Validate that a file path is within allowed directories.
	 *
	 * @param string $path The path to validate.
	 * @param array  $allowed_dirs Optional.
	 * @return bool|WP_Error True if path is valid, WP_Error otherwise.
	 */
	public static function validate_file_path( $path, $allowed_dirs = array() ) {
		$normalized_path = wp_normalize_path( $path );
		$real_path       = realpath( $path );
		$path            = false !== $real_path
			? wp_normalize_path( $real_path )
			: self::resolve_as_far_as_it_exists( $normalized_path );
		if ( empty( $allowed_dirs ) ) {
			$allowed_dirs = array(
				get_stylesheet_directory(),
				get_template_directory(),
			);
		}
		$allowed_dirs = array_map(
			static function ( $dir ) {
				return self::resolve_as_far_as_it_exists( wp_normalize_path( $dir ) );
			},
			$allowed_dirs
		);
		$is_valid     = false;
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
	 * Resolve the deepest part of a path that exists, keeping the rest.
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	private static function resolve_as_far_as_it_exists( $path ) {
		$missing   = array();
		$candidate = $path;

		while ( true ) {
			$real = realpath( $candidate );

			if ( false !== $real ) {
				$resolved = wp_normalize_path( $real );
				return $missing
					? trailingslashit( $resolved ) . implode( '/', array_reverse( $missing ) )
					: $resolved;
			}

			$parent = dirname( $candidate );
			if ( $parent === $candidate ) {
				return $path;
			}

			$missing[] = basename( $candidate );
			$candidate = $parent;
		}
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
	 * @param array  $allowed_dirs Optional.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function safe_file_write( $path, $content, $allowed_dirs = array() ) {
		$validation = self::validate_file_path( $path, $allowed_dirs );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$fs_init = self::init_filesystem();
		if ( is_wp_error( $fs_init ) ) {
			return $fs_init;
		}

		global $wp_filesystem;
		$dir = self::resolve_as_far_as_it_exists( wp_normalize_path( dirname( $path ) ) );
		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'directory_creation_failed',
					__( 'Failed to create directory.', 'pattern-builder' ),
					array( 'status' => 500 )
				);
			}
		}
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
	 * @param array  $allowed_dirs Optional.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function safe_file_delete( $path, $allowed_dirs = array() ) {
		$validation = self::validate_file_path( $path, $allowed_dirs );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$fs_init = self::init_filesystem();
		if ( is_wp_error( $fs_init ) ) {
			return $fs_init;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem->exists( $path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'File not found.', 'pattern-builder' ),
				array( 'status' => 404 )
			);
		}
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
	 * @param array  $allowed_dirs Optional.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function safe_file_move( $source, $destination, $allowed_dirs = array() ) {
		$source_validation = self::validate_file_path( $source, $allowed_dirs );
		if ( is_wp_error( $source_validation ) ) {
			return $source_validation;
		}

		$dest_validation = self::validate_file_path( $destination, $allowed_dirs );
		if ( is_wp_error( $dest_validation ) ) {
			return $dest_validation;
		}
		$fs_init = self::init_filesystem();
		if ( is_wp_error( $fs_init ) ) {
			return $fs_init;
		}

		global $wp_filesystem;
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
		$result = $wp_filesystem->move( $source, $destination, true );

		if ( false === $result ) {
			return new WP_Error(
				'file_move_failed',
				__( 'Failed to move file.', 'pattern-builder' ),
				array( 'status' => 500 )
			);
		}
		$wp_filesystem->chmod( $destination, FS_CHMOD_FILE );

		return true;
	}
}
