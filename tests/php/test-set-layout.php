<?php
/**
 * settings.layout: the one part of a design system that is neither a preset
 * nor a style.
 *
 * Every constrained band measures against these widths, so a site whose layout
 * an agent cannot set is one where every pattern has to restate the measure on
 * every band — which is exactly what a design system exists to stop.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Abilities;

class Test_Set_Layout extends WP_UnitTestCase {

	/**
	 * @var Pattern_Builder_Abilities
	 */
	private $abilities;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Registered already during bootstrap; this instance is only here to
		// call the execute_* methods directly.
		$this->abilities = new Pattern_Builder_Abilities();
		remove_action( 'wp_abilities_api_categories_init', array( $this->abilities, 'register_category' ) );
		remove_action( 'wp_abilities_api_init', array( $this->abilities, 'register_abilities' ) );

		$this->seed_theme_json();
		$this->refresh_theme_json();
	}

	public function tear_down() {
		$path = get_stylesheet_directory() . '/theme.json';
		if ( file_exists( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	/**
	 * Give the active theme a theme.json to be written into.
	 *
	 * @param array $config Starting config.
	 */
	private function seed_theme_json( $config = array( 'version' => 3 ) ) {
		file_put_contents( get_stylesheet_directory() . '/theme.json', wp_json_encode( $config ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
	}

	/**
	 * Make a rewritten theme.json visible to core's resolver.
	 *
	 * `WP_Theme_JSON_Resolver` keys parsed theme.json files by path and
	 * `clean_cached_data()` deliberately leaves that cache alone, so within one
	 * PHP process the first read of a path is the only read. A live site gets a
	 * fresh process per request and never notices; a test that writes and then
	 * asks core what it now thinks has to clear it by hand.
	 */
	private function refresh_theme_json() {
		wp_clean_theme_json_cache();

		$cache = new ReflectionProperty( 'WP_Theme_JSON_Resolver', 'theme_json_file_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );
	}

	/**
	 * Read the theme.json back off disk.
	 *
	 * @return array
	 */
	private function stored() {
		return json_decode( (string) file_get_contents( get_stylesheet_directory() . '/theme.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}

	public function test_the_widths_land_in_theme_json() {
		$result = $this->abilities->execute_set_layout(
			array(
				'contentSize' => '46rem',
				'wideSize'    => '80rem',
			)
		);

		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array( 'layout.contentSize', 'layout.wideSize' ), $result['written'] );
		$this->assertSame( 'theme', $result['destination'] );

		$stored = $this->stored();
		$this->assertSame( '46rem', $stored['settings']['layout']['contentSize'] );
		$this->assertSame( '80rem', $stored['settings']['layout']['wideSize'] );
	}

	/**
	 * A constrained block reads this at render, so what core now generates is
	 * the only proof that the write took.
	 */
	public function test_the_generated_custom_property_follows() {
		$this->abilities->execute_set_layout( array( 'contentSize' => '37rem' ) );
		$this->refresh_theme_json();

		$this->assertStringContainsString( '--wp--style--global--content-size: 37rem', wp_get_global_stylesheet() );
	}

	/**
	 * Naming one width leaves the other alone: an agent correcting the measure
	 * should not have to restate a wide size it never asked about.
	 */
	public function test_one_width_does_not_clear_the_other() {
		$this->abilities->execute_set_layout( array( 'contentSize' => '46rem', 'wideSize' => '80rem' ) );
		$this->abilities->execute_set_layout( array( 'contentSize' => '38rem' ) );

		$stored = $this->stored();
		$this->assertSame( '38rem', $stored['settings']['layout']['contentSize'] );
		$this->assertSame( '80rem', $stored['settings']['layout']['wideSize'] );
	}

	/**
	 * Root padding without this flag insets every full-width band, so the two
	 * belong to the same decision and the same ability.
	 */
	public function test_the_root_padding_flag_is_settable() {
		$result = $this->abilities->execute_set_layout( array( 'useRootPaddingAwareAlignments' => true ) );

		$this->assertNotWPError( $result );
		$this->assertContains( 'useRootPaddingAwareAlignments', $result['written'] );
		$this->assertTrue( $this->stored()['settings']['useRootPaddingAwareAlignments'] );
		$this->assertTrue( $result['layout']['useRootPaddingAwareAlignments'] );
	}

	/**
	 * A clamp() is the shape a fluid measure takes, so the grammar has to
	 * accept one rather than only a bare length.
	 */
	public function test_a_fluid_width_is_accepted() {
		$result = $this->abilities->execute_set_layout(
			array( 'contentSize' => 'clamp(20rem, 60vw, 46rem)' )
		);

		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'clamp(20rem, 60vw, 46rem)', $this->stored()['settings']['layout']['contentSize'] );
	}

	/**
	 * Core substitutes `initial` for a value it judges unsafe, which renders as
	 * a layout that silently did not take. Refusing here is what turns that
	 * into something the caller can read.
	 */
	public function test_a_value_that_is_not_a_length_is_refused() {
		foreach ( array( '46rem; position:fixed', 'url(https://example.com/x)', '46rem } body {', 'auto !important' ) as $bad ) {
			$result = $this->abilities->execute_set_layout( array( 'contentSize' => $bad ) );

			$this->assertWPError( $result, '"' . $bad . '" should have been refused.' );
			$this->assertSame( 'pb_layout_value', $result->get_error_code() );
		}

		$this->assertArrayNotHasKey( 'layout', $this->stored()['settings'] ?? array() );
	}

	public function test_setting_nothing_is_refused() {
		$result = $this->abilities->execute_set_layout( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_layout_empty', $result->get_error_code() );
	}

	/**
	 * The read half: an agent is told to call get-design-system first, so the
	 * widths it would be changing have to be in that answer.
	 */
	public function test_the_design_system_reports_the_layout() {
		$this->abilities->execute_set_layout(
			array(
				'contentSize'                   => '46rem',
				'wideSize'                      => '80rem',
				'useRootPaddingAwareAlignments' => true,
			)
		);

		$this->refresh_theme_json();
		$system = $this->abilities->execute_design_system( array() );

		$this->assertSame( '46rem', $system['layout']['contentSize'] );
		$this->assertSame( '80rem', $system['layout']['wideSize'] );
		$this->assertTrue( $system['layout']['useRootPaddingAwareAlignments'] );
	}
}
