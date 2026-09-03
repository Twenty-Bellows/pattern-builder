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
 *
 * A preset is a value a pattern *references*; a style is one it *inherits*,
 * and the difference decides everything about how this class behaves next to
 * `Pattern_Builder_Cloud_Tokens`.
 *
 * A preset is additive and inert — adding one changes nothing on the site
 * until some block names it — so tokens are never overwritten and a collision
 * is reported as skipped. A style is neither: there is one
 * `styles.elements.link.color.text`, setting it replaces whatever was there,
 * and it repaints every link on every page the moment it lands. So this
 * replaces where the tokens never do, and it is deliberately not reachable
 * from the cloud download path — a pattern that arrived from somewhere else
 * must not repaint the site it arrived at.
 */
class Pattern_Builder_Theme_Styles {

	/**
	 * Merge styles into a destination.
	 *
	 * @param array  $styles      A theme.json `styles` subtree.
	 * @param string $destination "theme" or "user".
	 * @return array|WP_Error { destination, written, skipped }
	 */
	public static function apply( $styles, $destination ) {
		if ( ! is_array( $styles ) || ! $styles ) {
			return new WP_Error( 'pb_styles_empty', __( 'No styles were given.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$css = self::find_css( $styles );
		if ( $css ) {
			return new WP_Error(
				'pb_styles_css_refused',
				sprintf(
					/* translators: %s: dotted paths within the styles tree, comma separated. */
					__( 'Raw CSS is not accepted here (%s). WordPress does not sanitize a theme.json "css" property and gates it on the edit_css capability, so it is refused rather than written. Express the design with the styles properties instead.', 'pattern-builder' ),
					implode( ', ', $css )
				),
				array( 'status' => 400 )
			);
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
	 * Drop anything WordPress would not accept as a style.
	 *
	 * Core's own schema does this — the same pass a theme.json gets when
	 * WordPress reads it, so it stays right across releases in a way a
	 * hand-written property list would not. What it drops is reported rather
	 * than silently lost, since an agent that believes it set a property and
	 * did not will go on to build against a design that isn't there.
	 *
	 * @param array $styles A theme.json `styles` subtree.
	 * @return array|WP_Error
	 */
	public static function sanitize( $styles ) {
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
	 * Every place a `css` property appears, as dotted paths.
	 *
	 * @param array  $node   Styles subtree.
	 * @param string $prefix Path so far.
	 * @return string[]
	 */
	private static function find_css( $node, $prefix = '' ) {
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
	 * @param array  $given  What was asked for.
	 * @param array  $kept   What survived sanitization.
	 * @param string $prefix Path so far.
	 * @return string[]
	 */
	private static function missing_paths( $given, $kept, $prefix = '' ) {
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
	 * @param array  $node   Styles subtree.
	 * @param string $prefix Path so far.
	 * @return string[]
	 */
	private static function paths( $node, $prefix = '' ) {
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
	 * Deep rather than wholesale: setting `elements.link.color.text` should
	 * not take `elements.button` with it, since an agent sets the one thing
	 * it means to change and has no reason to restate the rest.
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
