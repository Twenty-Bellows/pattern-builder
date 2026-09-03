<?php
/**
 * Reading and writing the two places a theme.json-shaped config lives.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_Theme_JSON_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The active theme's `theme.json`, or the site's user Global Styles.
 *
 * Two destinations, one shape: a theme.json-shaped array that is loaded,
 * changed and written back. `Pattern_Builder_Cloud_Tokens` merges presets into
 * it and `Pattern_Builder_Theme_Styles` merges styles, and neither of them
 * should have to know that one destination is a file on disk and the other a
 * post — or repeat the four ways loading can fail.
 */
class Pattern_Builder_Theme_Json {

	/**
	 * Read the config for a destination.
	 *
	 * @param string $destination "theme" or "user".
	 * @return array|WP_Error theme.json-shaped config.
	 */
	public static function load( $destination ) {
		if ( 'user' === $destination ) {
			$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			if ( ! $post_id ) {
				return new WP_Error( 'pb_cloud_no_global_styles', __( 'This site has no Global Styles storage for the active theme.', 'pattern-builder' ), array( 'status' => 500 ) );
			}

			$post   = get_post( $post_id );
			$config = $post ? json_decode( (string) $post->post_content, true ) : null;
			if ( ! is_array( $config ) ) {
				$config = array();
			}

			// Global Styles is stored as theme.json with two markers on it.
			$config['version']                     = isset( $config['version'] ) ? $config['version'] : 3;
			$config['isGlobalStylesUserThemeJSON'] = true;

			return $config;
		}

		$path = self::theme_json_path();
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'pb_cloud_no_theme_json', __( 'The active theme has no theme.json — write to Site styles instead.', 'pattern-builder' ), array( 'status' => 400 ) );
		}
		if ( ! wp_is_writable( $path ) ) {
			return new WP_Error( 'pb_cloud_theme_json_readonly', __( 'theme.json is not writable — write to Site styles instead.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$config = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file.
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'pb_cloud_theme_json_invalid', __( 'theme.json could not be parsed.', 'pattern-builder' ), array( 'status' => 500 ) );
		}

		return $config;
	}

	/**
	 * Write a config back to its destination.
	 *
	 * @param string $destination "theme" or "user".
	 * @param array  $config      theme.json-shaped config.
	 * @return true|WP_Error
	 */
	public static function save( $destination, $config ) {
		if ( 'user' === $destination ) {
			$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			if ( ! $post_id ) {
				return new WP_Error( 'pb_cloud_no_global_styles', __( 'This site has no Global Styles storage for the active theme.', 'pattern-builder' ), array( 'status' => 500 ) );
			}

			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( wp_json_encode( $config ) ),
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		} else {
			$written = file_put_contents( self::theme_json_path(), wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same direct write path Pattern_File_Store uses for theme files.
			if ( false === $written ) {
				return new WP_Error( 'pb_cloud_theme_json_write', __( 'theme.json could not be written.', 'pattern-builder' ), array( 'status' => 500 ) );
			}
		}

		// Whatever just changed, the merged data every reader sees is stale.
		wp_clean_theme_json_cache();

		return true;
	}

	/**
	 * Load a config, hand it to a merger, and write the result back.
	 *
	 * @param string   $destination "theme" or "user".
	 * @param callable $merge       Takes the config, returns it changed.
	 * @return true|WP_Error
	 */
	public static function edit( $destination, $merge ) {
		$config = self::load( $destination );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$config = call_user_func( $merge, $config );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return self::save( $destination, $config );
	}

	/**
	 * Where the active theme's theme.json lives.
	 *
	 * @return string
	 */
	public static function theme_json_path() {
		return get_stylesheet_directory() . '/theme.json';
	}
}
