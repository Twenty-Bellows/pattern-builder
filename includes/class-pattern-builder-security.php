<?php
/**
 * Pattern Builder Security Helper
 *
 * Provides security utilities for file operations and path validation.
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;

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

		/*
		 * Resolve the path as far as it goes, so it can be compared with
		 * directories resolved the same way below. A file that isn't there
		 * yet — the destination of a write or a move — resolves through the
		 * directory it will live in, which collapses any `..` just the same.
		 */
		$real_path = realpath( $path );
		$path      = false !== $real_path
			? wp_normalize_path( $real_path )
			: self::resolve_as_far_as_it_exists( $normalized_path );

		// Default to theme directory if no allowed directories specified.
		if ( empty( $allowed_dirs ) ) {
			$allowed_dirs = array(
				get_stylesheet_directory(),
				get_template_directory(),
			);
		}

		/*
		 * Resolve the allowed directories the same way the path above was
		 * resolved. A theme (or wp-content) reached through a symlink — the
		 * usual shape of a local dev setup — otherwise resolves to a real
		 * path that no unresolved allowed directory can ever match, and a
		 * legitimate write or delete looks like a traversal attempt.
		 */
		$allowed_dirs = array_map(
			static function ( $dir ) {
				return self::resolve_as_far_as_it_exists( wp_normalize_path( $dir ) );
			},
			$allowed_dirs
		);

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
	 * Resolve the deepest part of a path that exists, keeping the rest.
	 *
	 * A file being written doesn't exist yet, and neither does the directory
	 * it goes in, on the first write — but everything above them does, and
	 * resolving that much is what collapses `..` and follows the symlinks
	 * that make a checkout look like a theme directory.
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
				return $path; // Nothing along the way exists.
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

		/*
		 * Ensure the directory exists. `wp_mkdir_p()` refuses outright any
		 * path carrying a `..` segment, so a theme root that reaches its
		 * themes through one — as a registered theme root may, and as the
		 * test fixtures do — would fail to create the directory rather than
		 * be denied it, with "could not create" standing in for a traversal
		 * guard that was never the point. Resolving collapses it the same way
		 * `validate_file_path()` already did before allowing the write.
		 */
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

		/*
		 * A move carries the source file's permissions across, and a moved-in
		 * file can arrive with a mode no web server will serve: an image the
		 * image editor wrote is chmodded from the temporary directory's own
		 * mode, so a resize in /tmp (0777) lands the result world-writable,
		 * which suEXEC hosts refuse with a 403. Normalise to the same mode
		 * every other write in this plugin uses.
		 */
		$wp_filesystem->chmod( $destination, FS_CHMOD_FILE );

		return true;
	}
}
