<?php
/**
 * Blank Theme.
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
