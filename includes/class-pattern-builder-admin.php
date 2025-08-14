<?php

namespace TwentyBellows\PatternBuilder;

class Pattern_Builder_Admin {

	private const PAGE_SLUG  = 'pattern-builder';
	private const PAGE_TITLE = 'Pattern Builder';

	/**
	 * Constructor to initialize admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'create_admin_menu' ) );
	}

	/**
	 * Creates the admin menu for the Pattern Builder.
	 */
	public function create_admin_menu(): void {
		add_theme_page(
			_x( 'Pattern Builder', 'UI String', 'pattern-builder' ),
			_x( 'Pattern Builder', 'UI String', 'pattern-builder' ),
			'edit_theme_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_menu_page' )
		);
	}

	/**
	 * Renders the admin menu page.
	 */
	public function render_admin_menu_page(): void {
		$this->enqueue_assets();
		echo '<div id="pattern-builder-app"></div>';
	}

	/**
	 * Enqueues the necessary assets for the admin page.
	 */
	private function enqueue_assets(): void {

		$screen = get_current_screen();

		if ( $screen->id !== 'appearance_page_pattern-builder' ) {
			return;
		}

		$asset_file = include plugin_dir_path( __FILE__ ) . '../build/PatternBuilder_Admin.asset.php';

		wp_enqueue_script(
			'pattern-builder-app',
			plugins_url( '../build/PatternBuilder_Admin.js', __FILE__ ),
			$asset_file['dependencies'],
			$asset_file['version']
		);

		wp_enqueue_style(
			'pattern-builder-editor-style',
			plugins_url( '../build/PatternBuilder_Admin.css', __FILE__ ),
			array(),
			$asset_file['version']
		);

		// Enqueue core editor styles
		// wp_enqueue_style( 'wp-edit-blocks' ); // Block editor base styles
		// wp_enqueue_style( 'wp-block-library' ); // Front-end block styles
		// wp_enqueue_style( 'wp-block-editor' ); // Editor layout styles

		// Enqueue media library assets
		// wp_enqueue_media();

		wp_set_script_translations( 'pattern-builder-app', 'pattern-builder' );
	}
}
