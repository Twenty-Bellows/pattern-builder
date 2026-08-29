<?php

namespace TwentyBellows\PatternBuilder;

use WP_Block_Editor_Context;

/**
 * The Appearance → Pattern Builder screen.
 *
 * Hosts the plugin's full-screen pattern editor: a block editor built from
 * public `@wordpress/editor` pieces, bound to the `pb_pattern` entity so theme
 * pattern edits save straight to the pattern files.
 */
class Pattern_Builder_Admin {

	private const PAGE_SLUG = 'pattern-builder';

	/**
	 * The admin page's hook suffix, once registered.
	 *
	 * @var string|false
	 */
	private $page_hook = false;

	/**
	 * Constructor to initialize admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'create_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Creates the admin menu for the Pattern Builder.
	 */
	public function create_admin_menu(): void {
		$this->page_hook = add_theme_page(
			_x( 'Pattern Builder', 'UI String', 'pattern-builder' ),
			_x( 'Pattern Builder', 'UI String', 'pattern-builder' ),
			'edit_theme_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_menu_page' )
		);
	}

	/**
	 * Enqueues the pattern editor app on the plugin's own page.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( ! $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}

		$asset_path = plugin_dir_path( __FILE__ ) . '../build/PatternBuilder_Admin.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset = include $asset_path;

		// The block editor's client-side registry needs the server's block
		// definitions and categories, exactly as core's editor screens set up.
		wp_add_inline_script(
			'wp-blocks',
			'wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode( get_block_editor_server_block_settings() ) . ');',
			'after'
		);

		$editor_context = new WP_Block_Editor_Context( array( 'name' => 'pattern-builder/editor' ) );

		wp_add_inline_script(
			'wp-blocks',
			sprintf( 'wp.blocks.setCategories( %s );', wp_json_encode( get_block_categories( $editor_context ) ) ),
			'after'
		);

		wp_enqueue_script(
			'pattern-builder-admin',
			plugins_url( '../build/PatternBuilder_Admin.js', __FILE__ ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'pattern-builder-admin', 'pattern-builder' );

		$css_path = plugin_dir_path( __FILE__ ) . '../build/PatternBuilder_Admin.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'pattern-builder-admin',
				plugins_url( '../build/PatternBuilder_Admin.css', __FILE__ ),
				array( 'wp-components', 'wp-block-editor', 'wp-edit-blocks' ),
				$asset['version']
			);
		} else {
			wp_enqueue_style( 'wp-edit-blocks' );
		}

		wp_enqueue_style( 'wp-format-library' );
		wp_enqueue_media();

		$settings = get_block_editor_settings(
			array_merge(
				get_default_block_editor_settings(),
				array( 'styles' => get_block_editor_theme_styles() )
			),
			$editor_context
		);

		wp_add_inline_script(
			'pattern-builder-admin',
			sprintf(
				'window.patternBuilderAdmin = %s;',
				wp_json_encode(
					array(
						'editorSettings' => $settings,
						'pattern'        => isset( $_GET['pattern'] ) ? sanitize_text_field( wp_unslash( $_GET['pattern'] ) ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'adminUrl'       => admin_url( 'themes.php?page=' . self::PAGE_SLUG ),
					)
				)
			),
			'before'
		);

		/*
		 * Let every block-editor integration load — this plugin's own editor
		 * tools and pattern runtime included, along with any third-party
		 * blocks' editor assets.
		 */
		do_action( 'enqueue_block_editor_assets' );
	}

	/**
	 * Renders the mount point for the pattern editor app.
	 */
	public function render_admin_menu_page(): void {
		echo '<div id="pattern-builder-admin" class="pattern-builder-admin"></div>';
	}
}
