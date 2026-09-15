<?php

namespace TwentyBellows\PatternBuilder;

use WP_Block_Editor_Context;

/**
 * The Appearance → Pattern Builder screen.
 *
 * Two modes, decided by the URL's `pattern` parameter:
 *
 * - Browse (no parameter): the pattern grid — search, filter, create.
 * - Edit (`&pattern={id}`): the WordPress editor itself. The page boots
 *   core's `@wordpress/edit-post` editor (the one that powers post.php)
 *   bound to the `pb_pattern` entity, so theme pattern edits save straight
 *   to the pattern files with the full core editing experience.
 */
class Pattern_Builder_Admin {

	private const PAGE_SLUG = 'pattern-builder';

	/**
	 * The name this screen gives its block editor context.
	 *
	 * Core keys a few of its editor settings off the context name, so the
	 * value is shared rather than spelled out at each use.
	 */
	private const EDITOR_CONTEXT = 'pattern-builder/editor';

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
		add_filter( 'block_editor_settings_all', array( $this, 'allow_block_bindings_editing' ), 10, 2 );
	}

	/**
	 * Lets this screen's editor edit block bindings.
	 *
	 * `canUpdateBlockBindings` is `current_user_can( 'edit_block_binding' )`,
	 * and that capability wants either a post in the editor context or the
	 * context name `core/edit-site`. This screen has neither — it edits
	 * file-backed patterns, which have no post — so core maps the capability
	 * to `do_not_allow` and every bindings control renders read-only.
	 *
	 * Reaching the screen at all already requires `edit_theme_options`, which
	 * is also what `pb_pattern` maps its edit capabilities to and what core
	 * itself falls back to for the Site Editor, so that is the check applied
	 * here rather than a broader one.
	 *
	 * @param array                   $settings The block editor settings.
	 * @param WP_Block_Editor_Context $context  The current editor context.
	 * @return array The filtered settings.
	 */
	public function allow_block_bindings_editing( $settings, $context ) {
		if ( ! isset( $context->name ) || self::EDITOR_CONTEXT !== $context->name ) {
			return $settings;
		}

		$settings['canUpdateBlockBindings'] = current_user_can( 'edit_theme_options' );

		return $settings;
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
	 * Marks the edit-mode screen as a block editor screen, as core's own
	 * editor pages do — admin body classes and asset behavior key off it.
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

		// The block editor's client-side registry needs the server's block
		// definitions and categories, exactly as core's editor screens set up.
		wp_add_inline_script(
			'wp-blocks',
			'wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode( get_block_editor_server_block_settings() ) . ');',
			'after'
		);

		$editor_context = new WP_Block_Editor_Context( array( 'name' => self::EDITOR_CONTEXT ) );

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

		if ( $pattern ) {
			// The full editor skin — the same stylesheet stack post.php loads.
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

		$browse_url = admin_url( 'themes.php?page=' . self::PAGE_SLUG );

		/*
		 * Where the editor's back button returns to: the screen the user
		 * came from (the Site Editor, the browse screen, …), validated the
		 * way core validates redirect targets, falling back to browse.
		 */
		$back_url = isset( $_GET['back'] ) ? wp_validate_redirect( sanitize_url( wp_unslash( $_GET['back'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_add_inline_script(
			'pattern-builder-admin',
			sprintf(
				'window.patternBuilderAdmin = %s;',
				wp_json_encode(
					array(
						'editorSettings' => $settings,
						'pattern'        => $pattern ? $pattern : null,
						'adminUrl'       => $browse_url,
						'backUrl'        => $back_url ? $back_url : $browse_url,
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
	 * Renders the mount point for the pattern browser or editor.
	 */
	public function render_admin_menu_page(): void {
		if ( $this->get_requested_pattern() ) {
			// The div core's editor takes over — mirrors post.php's markup.
			echo '<div class="block-editor">';
			echo '<div id="pattern-builder-admin" class="block-editor__container hide-if-no-js"></div>';
			echo '</div>';
			return;
		}

		echo '<div id="pattern-builder-admin" class="pattern-builder-admin"></div>';
	}
}
