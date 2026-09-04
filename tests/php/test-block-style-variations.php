<?php
/**
 * Block style variations: the partial that registers one, and its refusals.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Block_Style_Variations;
use TwentyBellows\PatternBuilder\Pattern_Builder_Theme_Styles;

class Test_Block_Style_Variations extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_clean_theme_json_cache();
	}

	public function tear_down() {
		/*
		 * `WP_Block_Styles_Registry` is a process-wide singleton and the test
		 * case does not reset it, so a variation registered by one test is
		 * still registered for the next — where it reads as somebody else's
		 * name and gets refused.
		 */
		foreach ( array( 'core/button' => array( 'button-secondary', 'button-hover', 'button-hover-css' ), 'core/group' => array( 'group-hover' ) ) as $block => $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( WP_Block_Styles_Registry::get_instance()->is_registered( $block, $slug ) ) {
					unregister_block_style( $block, $slug );
				}
			}
		}

		$dir = Pattern_Builder_Block_Style_Variations::directory();
		if ( is_dir( $dir ) ) {
			foreach ( glob( $dir . '/*.json' ) as $file ) {
				unlink( $file );
			}
			rmdir( $dir );
		}
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * The shape of a variation this ability writes.
	 *
	 * @param array $overrides Fields to change.
	 * @return array
	 */
	private function args( $overrides = array() ) {
		return array_merge(
			array(
				'slug'       => 'button-secondary',
				'title'      => 'Secondary',
				'blockTypes' => array( 'core/button' ),
				'styles'     => array(
					'border' => array( 'radius' => '999px' ),
					'color'  => array( 'background' => 'var:preset|color|accent' ),
				),
			),
			$overrides
		);
	}

	public function test_a_variation_is_written_as_a_theme_partial() {
		$result = Pattern_Builder_Block_Style_Variations::add( $this->args() );

		$this->assertNotWPError( $result );
		$this->assertSame( 'button-secondary', $result['slug'] );
		$this->assertSame( 'is-style-button-secondary', $result['class'] );
		$this->assertSame( 'styles/button-secondary.json', $result['path'] );

		$partial = json_decode( (string) file_get_contents( Pattern_Builder_Block_Style_Variations::directory() . '/button-secondary.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertSame( array( 'core/button' ), $partial['blockTypes'] );
		$this->assertSame( 'Secondary', $partial['title'] );
		$this->assertSame( '999px', $partial['styles']['border']['radius'] );
	}

	/**
	 * The partial is only worth writing if WordPress registers it. Core reads
	 * the theme's `styles/` directory, keeps the files carrying a
	 * `blockTypes` key, and registers each as a block style — so the proof is
	 * the registry, not the file.
	 */
	public function test_wordpress_registers_the_variation_it_finds() {
		Pattern_Builder_Block_Style_Variations::add( $this->args() );

		$variations = WP_Theme_JSON_Resolver::get_style_variations( 'block' );
		wp_register_block_style_variations_from_theme_json_partials( $variations );

		$registered = WP_Block_Styles_Registry::get_instance()->get_registered( 'core/button', 'button-secondary' );

		$this->assertNotNull( $registered );
		$this->assertSame( 'Secondary', $registered['label'] );
	}

	public function test_the_definition_can_be_read_back_by_slug() {
		Pattern_Builder_Block_Style_Variations::add( $this->args() );

		$definition = Pattern_Builder_Block_Style_Variations::definition( 'button-secondary' );

		$this->assertNotNull( $definition );
		$this->assertSame( array( 'core/button' ), $definition['blockTypes'] );
		$this->assertArrayHasKey( 'button-secondary', Pattern_Builder_Block_Style_Variations::all() );
	}

	/**
	 * Revising a design means rewriting the variation, and this theme's own
	 * partial is the file this ability wrote.
	 */
	public function test_our_own_partial_is_rewritten_rather_than_refused() {
		Pattern_Builder_Block_Style_Variations::add( $this->args() );

		$result = Pattern_Builder_Block_Style_Variations::add(
			$this->args( array( 'styles' => array( 'border' => array( 'radius' => '2px' ) ) ) )
		);

		$this->assertNotWPError( $result );
		$partial = json_decode( (string) file_get_contents( Pattern_Builder_Block_Style_Variations::directory() . '/button-secondary.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertSame( '2px', $partial['styles']['border']['radius'] );
	}

	/**
	 * `wp_register_block_style_variations_from_theme_json_partials()` checks
	 * the registry and skips a name that is already there, so a partial
	 * written over somebody else's registration is inert. Reporting that is
	 * the difference between a refusal and a silent no-op.
	 */
	public function test_a_name_another_registration_holds_is_refused() {
		register_block_style( 'core/button', array( 'name' => 'button-secondary', 'label' => 'Someone else\'s' ) );

		try {
			$result = Pattern_Builder_Block_Style_Variations::add( $this->args() );

			$this->assertWPError( $result );
			$this->assertSame( 'pb_variation_name_taken', $result->get_error_code() );
			$this->assertFalse( file_exists( Pattern_Builder_Block_Style_Variations::directory() . '/button-secondary.json' ) );
		} finally {
			unregister_block_style( 'core/button', 'button-secondary' );
		}
	}

	/**
	 * Core reads a partial through the whole-theme schema before it puts the
	 * styles under the variation's node (`get_style_variations()`), so what a
	 * partial can carry is what a root `styles` tree can: properties,
	 * `elements`, inner `blocks`. A block state such as `:hover` is dropped on
	 * read, silently. The ability checks the file exactly as core will read
	 * it, keeps what survives, and says where the state goes instead.
	 */
	public function test_a_block_state_is_reported_with_where_it_goes_and_elements_survive() {
		$result = Pattern_Builder_Block_Style_Variations::add(
			$this->args(
				array(
					'slug'   => 'button-hover',
					'styles' => array(
						'color'    => array( 'background' => 'var:preset|color|accent' ),
						':hover'   => array( 'color' => array( 'background' => 'var:preset|color|contrast' ) ),
						'elements' => array( 'link' => array( 'color' => array( 'text' => 'var:preset|color|base' ) ) ),
					),
				)
			)
		);

		$this->assertNotWPError( $result );
		$this->assertContains( 'elements.link.color.text', $result['written'] );
		$this->assertContains( ':hover', $result['skipped'] );
		$this->assertArrayHasKey( 'note', $result );
		$this->assertStringContainsString( 'set-global-styles', $result['note'] );
		$this->assertStringContainsString( 'variations.button-hover', $result['note'] );

		$partial = json_decode( (string) file_get_contents( Pattern_Builder_Block_Style_Variations::directory() . '/button-hover.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertArrayNotHasKey( ':hover', $partial['styles'] );
		// Core spells a preset reference out as the custom property on the
		// way through its schema, which is the form the partial is stored in.
		$this->assertSame( 'var(--wp--preset--color--base)', $partial['styles']['elements']['link']['color']['text'] );
	}

	/**
	 * The state does have a home: theme.json's block-level node for the
	 * variation, which core merges over the partial. That node is kept only
	 * while the variation is registered, and a partial registers lazily — so
	 * the styles writer has to ask for the theme's data first, or a state set
	 * for a variation that plainly exists would come back as unrecognised.
	 * The proof is the CSS core generates for the button.
	 */
	public function test_a_state_set_through_global_styles_reaches_the_rendered_css() {
		// A slug no other test writes: the resolver caches a partial by path
		// for the whole process, so a path reused across tests reads stale.
		$added = Pattern_Builder_Block_Style_Variations::add(
			$this->args(
				array(
					'slug'   => 'button-hover-css',
					'styles' => array( 'border' => array( 'radius' => '999px' ) ),
				)
			)
		);
		$this->assertNotWPError( $added );

		$theme_json = get_stylesheet_directory() . '/theme.json';
		$had_file   = file_exists( $theme_json );
		if ( ! $had_file ) {
			file_put_contents( $theme_json, wp_json_encode( array( 'version' => 3 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		wp_clean_theme_json_cache();

		try {
			$set = Pattern_Builder_Theme_Styles::apply(
				array(
					'blocks' => array(
						'core/button' => array(
							'variations' => array(
								'button-hover-css' => array(
									':hover' => array( 'color' => array( 'background' => '#123456' ) ),
								),
							),
						),
					),
				),
				'theme'
			);

			$this->assertNotWPError( $set );
			$this->assertSame( array(), $set['skipped'] );
			$this->assertContains( 'blocks.core/button.variations.button-hover-css.:hover.color.background', $set['written'] );

			/*
			 * The resolver also caches the *file* by path for the whole process,
			 * and `wp_clean_theme_json_cache()` leaves that cache alone — so
			 * within one process the theme.json it already read is the one it
			 * keeps. A real request never sees this; a test that writes and then
			 * renders in one process has to reach in.
			 */
			$file_cache = new ReflectionProperty( WP_Theme_JSON_Resolver::class, 'theme_json_file_cache' );
			$file_cache->setAccessible( true );
			$file_cache->setValue( null, array() );
			wp_clean_theme_json_cache();

			do_blocks( "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button {\"className\":\"is-style-button-hover-css\"} -->\n<div class=\"wp-block-button is-style-button-hover-css\"><a class=\"wp-block-button__link wp-element-button\">Go</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->" );

			$after = wp_styles()->get_data( 'block-style-variation-styles', 'after' );
			$css   = is_array( $after ) ? implode( "\n", $after ) : (string) $after;

			$this->assertStringContainsString( 'is-style-button-hover-css--', $css );
			$this->assertStringContainsString( '999px', $css );
			$this->assertStringContainsString( ':hover', $css );
			$this->assertStringContainsString( '#123456', $css );
		} finally {
			if ( ! $had_file ) {
				unlink( $theme_json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			// Core registered this handle on the process-wide `wp_styles()`
			// with `global-styles` as a dependency; a later test that prints
			// a head would trip a notice over it, so take it away again.
			wp_styles()->remove( 'block-style-variation-styles' );
		}
	}

	/**
	 * A block this site has not registered cannot be checked against
	 * anything, and a partial naming one would register a look for a block
	 * that parses to core/missing.
	 */
	public function test_a_variation_for_a_block_this_site_lacks_is_refused() {
		$result = Pattern_Builder_Block_Style_Variations::add( $this->args( array( 'blockTypes' => array( 'acme/nothing' ) ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_variation_unknown_block', $result->get_error_code() );
	}

	public function test_raw_css_is_refused() {
		$result = Pattern_Builder_Block_Style_Variations::add(
			$this->args( array( 'styles' => array( 'css' => '} body { display: none } .x {' ) ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_styles_css_refused', $result->get_error_code() );
	}

	public function test_a_variation_needs_a_block_type() {
		$result = Pattern_Builder_Block_Style_Variations::add( $this->args( array( 'blockTypes' => array() ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_variation_no_block_types', $result->get_error_code() );
	}

	public function test_a_variation_needs_styles() {
		$result = Pattern_Builder_Block_Style_Variations::add( $this->args( array( 'styles' => array() ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_variation_no_styles', $result->get_error_code() );
	}

	public function test_a_slug_is_normalised_and_the_title_follows_it() {
		$result = Pattern_Builder_Block_Style_Variations::add(
			$this->args(
				array(
					'slug'  => 'Button Secondary!',
					'title' => '',
				)
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'button-secondary', $result['slug'] );
		$this->assertSame( 'Button Secondary', $result['title'] );
	}
}
