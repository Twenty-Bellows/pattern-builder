<?php

namespace TwentyBellows\PatternBuilder;

use WP_Block_Editor_Context;

/**
 * The Appearance → Pattern Builder screen.
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

		if ( $this->page_hook ) {
			add_action( 'load-' . $this->page_hook, array( $this, 'setup_screen' ) );
		}
	}

	/**
	 * Marks the edit-mode screen as a block editor screen, as core's own editor pages do —
	 * admin body classes and asset behavior key off it.
	 */
	public function setup_screen(): void {
		if ( $this->get_requested_pattern() ) {
			get_current_screen()->is_block_editor( true );
		}
	}

	/**
	 * The pattern id the page was asked to edit, if any.
	 *
	 * @return string The pattern id, or an empty string on the browse screen.
	 */
	private function get_requested_pattern_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['type'] ) && 'user' === $_GET['type'] ? 'user' : 'theme';
	}

	/**
	 * The pattern id the page was asked to edit, if any.
	 *
	 * @return string The pattern id, or an empty string on the browse screen.
	 */
	private function get_requested_pattern(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['pattern'] ) ? sanitize_text_field( wp_unslash( $_GET['pattern'] ) ) : '';
	}

	/**
	 * Enqueues the pattern browser / editor boot on the plugin's own page.
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

		$asset   = include $asset_path;
		$pattern = $this->get_requested_pattern();
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
		$binding_sources = array();
		foreach ( get_all_registered_block_bindings_sources() as $source ) {
			$binding_sources[] = array(
				'name'        => $source->name,
				'label'       => $source->label,
				'usesContext' => $source->uses_context,
			);
		}

		if ( $binding_sources ) {
			wp_add_inline_script(
				'wp-blocks',
				sprintf(
					'for ( const source of %s ) { wp.blocks.registerBlockBindingsSource( source ); }',
					wp_json_encode( $binding_sources, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
				),
				'after'
			);
		}

		wp_enqueue_script(
			'pattern-builder-admin',
			plugins_url( '../build/PatternBuilder_Admin.js', __FILE__ ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'pattern-builder-admin', 'pattern-builder' );

		if ( $pattern ) {
			wp_enqueue_style( 'wp-edit-post' );
		}

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
		if ( $pattern ) {
			$settings['styles'][] = array(
				'css' => '.editor-visual-editor__post-title-wrapper { display: none; }',
			);
		}

		$browse_url = admin_url( 'themes.php?page=' . self::PAGE_SLUG );

		$back_url = isset( $_GET['back'] ) ? wp_validate_redirect( sanitize_url( wp_unslash( $_GET['back'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_add_inline_script(
			'pattern-builder-admin',
			sprintf(
				'window.patternBuilderAdmin = %s;',
				wp_json_encode(
					array(
						'editorSettings'   => $settings,
						'pattern'          => $pattern ? $pattern : null,
						'patternType'      => $this->get_requested_pattern_type(),
						'adminUrl'         => $browse_url,
						'backUrl'          => $back_url ? $back_url : $browse_url,
						'telemetry'        => Pattern_Builder_Telemetry::client_state(),
						'wordPressVersion' => Pattern_Builder_Cloud_Porter::wordpress_version(),
						'tileBase'         => $pattern ? '' : Pattern_Builder_Preview::tile_base(),
						'designVersion'    => $pattern ? '' : Pattern_Builder_Preview::design_version(),
					)
				)
			),
			'before'
		);
		do_action( 'enqueue_block_editor_assets' );
	}

	/**
	 * Renders the mount point for the pattern browser or editor.
	 */
	public function render_admin_menu_page(): void {
		if ( $this->get_requested_pattern() ) {
			echo '<div class="block-editor">';
			echo '<div id="pattern-builder-admin" class="block-editor__container hide-if-no-js"></div>';
			echo '</div>';
			return;
		}

		echo '<div id="pattern-builder-admin" class="pattern-builder-admin"></div>';
	}
}
