<?php
/**
 * The site's global styles — what a pattern inherits rather than references.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_Theme_JSON;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writing `styles` into theme.json or Global Styles.
 */
class Pattern_Builder_Theme_Styles {
	/**
	 * Merge styles into a destination.
	 *
	 * @param array  $styles A theme.json `styles` subtree.
	 * @param string $destination "theme" or "user".
	 * @return array|WP_Error { destination, written, skipped }
	 */
	public static function apply( $styles, $destination ) {
		if ( ! is_array( $styles ) || ! $styles ) {
			return new WP_Error( 'pb_styles_empty', __( 'No styles were given.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$css = self::check_css( $styles );
		if ( is_wp_error( $css ) ) {
			return $css;
		}

		$clean = self::sanitize( $styles );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$skipped = self::missing_paths( $styles, $clean );

		if ( ! $clean ) {
			return new WP_Error(
				'pb_styles_none_valid',
				sprintf(
					/* translators: %s: dotted paths within the styles tree, comma separated. */
					__( 'None of these are styles WordPress recognises (%s). Check the property names against the styles this site already has, from get-design-system.', 'pattern-builder' ),
					implode( ', ', $skipped )
				),
				array( 'status' => 400 )
			);
		}

		$result = Pattern_Builder_Theme_Json::edit(
			$destination,
			function ( $config ) use ( $clean ) {
				$existing          = isset( $config['styles'] ) && is_array( $config['styles'] ) ? $config['styles'] : array();
				$config['styles']  = self::merge( $existing, $clean );
				$config['version'] = isset( $config['version'] ) ? $config['version'] : WP_Theme_JSON::LATEST_SCHEMA;
				return $config;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'destination' => $destination,
			'written'     => self::paths( $clean ),
			'skipped'     => $skipped,
		);
	}

	/**
	 * Refuse a styles tree carrying raw CSS.
	 *
	 * @param array $styles A theme.json `styles` subtree.
	 * @return true|WP_Error
	 */
	public static function check_css( $styles ) {
		$found = self::find_css( $styles );
		if ( ! $found ) {
			return true;
		}

		return new WP_Error(
			'pb_styles_css_refused',
			sprintf(
				/* translators: %s: dotted paths within the styles tree, comma separated. */
				__( 'Raw CSS is not accepted here (%s). WordPress does not sanitize a theme.json "css" property and gates it on the edit_css capability, so it is refused rather than written. Express the design with the styles properties instead.', 'pattern-builder' ),
				implode( ', ', $found )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Drop anything WordPress would not accept as a style.
	 *
	 * @param array $styles A theme.json `styles` subtree.
	 * @return array|WP_Error
	 */
	public static function sanitize( $styles ) {
		if ( self::names_a_variation( $styles ) && class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::get_theme_data();
		}

		$theme_json = new WP_Theme_JSON(
			array(
				'version' => WP_Theme_JSON::LATEST_SCHEMA,
				'styles'  => $styles,
			),
			'theme'
		);

		$raw = $theme_json->get_raw_data();

		return isset( $raw['styles'] ) && is_array( $raw['styles'] ) ? $raw['styles'] : array();
	}

	/**
	 * Whether a styles tree reaches into a block style variation.
	 *
	 * @param array $styles A theme.json `styles` subtree.
	 * @return bool
	 */
	private static function names_a_variation( $styles ) {
		if ( empty( $styles['blocks'] ) || ! is_array( $styles['blocks'] ) ) {
			return false;
		}
		foreach ( $styles['blocks'] as $block ) {
			if ( is_array( $block ) && ! empty( $block['variations'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every place a `css` property appears, as dotted paths.
	 *
	 * @param array  $node Styles subtree.
	 * @param string $prefix Path so far.
	 * @return string[]
	 */
	public static function find_css( $node, $prefix = '' ) {
		$found = array();

		foreach ( $node as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( 'css' === $key ) {
				$found[] = $path;
				continue;
			}
			if ( is_array( $value ) ) {
				$found = array_merge( $found, self::find_css( $value, $path ) );
			}
		}

		return $found;
	}

	/**
	 * Leaf paths present in the first tree and not the second.
	 *
	 * @param array  $given What was asked for.
	 * @param array  $kept What survived sanitization.
	 * @param string $prefix Path so far.
	 * @return string[]
	 */
	public static function missing_paths( $given, $kept, $prefix = '' ) {
		$missing = array();

		foreach ( $given as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( ! is_array( $kept ) || ! array_key_exists( $key, $kept ) ) {
				$missing[] = $path;
				continue;
			}
			if ( is_array( $value ) ) {
				$missing = array_merge( $missing, self::missing_paths( $value, $kept[ $key ], $path ) );
			}
		}

		return $missing;
	}

	/**
	 * The leaf paths a styles tree sets.
	 *
	 * @param array  $node Styles subtree.
	 * @param string $prefix Path so far.
	 * @return string[]
	 */
	public static function paths( $node, $prefix = '' ) {
		$paths = array();

		foreach ( $node as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) && $value ) {
				$paths = array_merge( $paths, self::paths( $value, $path ) );
			} else {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Merge incoming styles over existing ones, leaf by leaf.
	 *
	 * @param array $existing Styles already in the config.
	 * @param array $incoming Styles to write.
	 * @return array
	 */
	private static function merge( $existing, $incoming ) {
		foreach ( $incoming as $key => $value ) {
			if ( is_array( $value ) && isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) ) {
				$existing[ $key ] = self::merge( $existing[ $key ], $value );
			} else {
				$existing[ $key ] = $value;
			}
		}

		return $existing;
	}
}
