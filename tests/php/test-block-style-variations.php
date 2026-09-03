<?php
/**
 * Block style variations: the partial that registers one, and its refusals.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Block_Style_Variations;

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
		foreach ( array( 'button-secondary' ) as $slug ) {
			if ( WP_Block_Styles_Registry::get_instance()->is_registered( 'core/button', $slug ) ) {
				unregister_block_style( 'core/button', $slug );
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
