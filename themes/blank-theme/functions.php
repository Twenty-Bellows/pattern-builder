<?php
/**
 * Blank Theme.
 *
 * The point of this theme is that a pattern authored against it is authored
 * against nothing, so that what it looks like is what the pattern itself does.
 *
 * theme.json cannot deliver that on its own. `settings.color.defaultPalette:
 * false` reads as though it removes core's palette and does not: it hides those
 * colours from the editor's picker and governs whether a theme may reuse their
 * slugs, while `--wp--preset--color--vivid-red` and every other default custom
 * property is still emitted, so a pattern can quietly depend on one. The same
 * goes for the default font sizes, spacing steps, gradients and duotones.
 *
 * The presets themselves come from core's own theme.json, which arrives through
 * `wp_theme_json_data_default`. Emptying them there is what actually leaves the
 * page with no design system on it.
 *
 * @package BlankTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'blank_theme_empty_the_defaults' ) ) :

	/**
	 * Take core's own presets out of the merge.
	 *
	 * Guarded because Pattern Builder's preview route loads this file directly
	 * when it renders a pattern against this theme without activating it.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Core's default theme.json data.
	 * @return WP_Theme_JSON_Data
	 */
	function blank_theme_empty_the_defaults( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version'  => 3,
				'settings' => array(
					'color'      => array(
						'palette'   => array(),
						'gradients' => array(),
						'duotone'   => array(),
					),
					'typography' => array(
						'fontSizes'    => array(),
						'fontFamilies' => array(),
					),
					'spacing'    => array(
						'spacingSizes' => array(),
						'spacingScale' => array( 'steps' => 0 ),
					),
					'shadow'     => array(
						'presets' => array(),
					),
				),
			)
		);
	}
	endif;

if ( ! function_exists( 'blank_theme_boot' ) ) :

	/**
	 * Attach what this theme does.
	 *
	 * Separate from the file body because Pattern Builder's preview route loads
	 * this theme without activating it, and `require_once` runs a file once per
	 * process: a second preview would attach nothing. The convention is
	 * `{slug with underscores}_boot()` and `_unboot()`, which the preview calls
	 * either side of a render so the swap is contained to it.
	 */
	function blank_theme_boot() {
		add_filter( 'wp_theme_json_data_default', 'blank_theme_empty_the_defaults' );
	}

	/**
	 * Take it off again.
	 */
	function blank_theme_unboot() {
		remove_filter( 'wp_theme_json_data_default', 'blank_theme_empty_the_defaults' );
	}

	endif;

blank_theme_boot();
