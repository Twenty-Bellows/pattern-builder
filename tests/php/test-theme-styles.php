<?php
/**
 * Global styles: the writer, the merge, and the two refusals.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Theme_Styles;

class Test_Theme_Styles extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_clean_theme_json_cache();
	}

	public function tear_down() {
		$path = get_stylesheet_directory() . '/theme.json';
		if ( file_exists( $path ) ) {
			unlink( $path );
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
	 * Read the theme.json back off disk.
	 *
	 * @return array
	 */
	private function stored() {
		return json_decode( (string) file_get_contents( get_stylesheet_directory() . '/theme.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
	}

	public function test_styles_land_in_theme_json() {
		$this->seed_theme_json();

		$result = Pattern_Builder_Theme_Styles::apply(
			array(
				'typography' => array( 'fontSize' => 'var:preset|font-size|medium' ),
				'elements'   => array(
					'heading' => array( 'typography' => array( 'fontWeight' => '700' ) ),
				),
			),
			'theme'
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'theme', $result['destination'] );
		$this->assertContains( 'typography.fontSize', $result['written'] );
		$this->assertContains( 'elements.heading.typography.fontWeight', $result['written'] );
		$this->assertSame( array(), $result['skipped'] );

		/*
		 * Core resolves the theme.json `var:preset|…` shorthand into the CSS
		 * custom property as it reads it, so that is what lands in the file.
		 * The two are equivalent and it is the form the themes shipped here
		 * already use; `Cloud_Tokens::referenced()` scans for both, so a
		 * style written this way is still one whose tokens can be collected.
		 */
		$stored = $this->stored();
		$this->assertSame( 'var(--wp--preset--font-size--medium)', $stored['styles']['typography']['fontSize'] );
		$this->assertSame( '700', $stored['styles']['elements']['heading']['typography']['fontWeight'] );
	}

	/**
	 * The opposite of a token. `add-design-tokens` leaves an existing slug
	 * alone because a preset is additive; there is only one
	 * `elements.link.color.text`, so setting it has to mean setting it.
	 */
	public function test_a_style_replaces_rather_than_being_skipped() {
		$this->seed_theme_json();

		Pattern_Builder_Theme_Styles::apply(
			array( 'elements' => array( 'link' => array( 'color' => array( 'text' => '#111111' ) ) ) ),
			'theme'
		);
		Pattern_Builder_Theme_Styles::apply(
			array( 'elements' => array( 'link' => array( 'color' => array( 'text' => '#c62026' ) ) ) ),
			'theme'
		);

		$stored = $this->stored();
		$this->assertSame( '#c62026', $stored['styles']['elements']['link']['color']['text'] );
	}

	/**
	 * An agent sets the one thing it means to change, so naming a link must
	 * not take the button with it.
	 */
	public function test_setting_one_element_leaves_the_others_alone() {
		$this->seed_theme_json(
			array(
				'version' => 3,
				'styles'  => array(
					'elements' => array(
						'button' => array( 'border' => array( 'radius' => '2px' ) ),
					),
				),
			)
		);

		Pattern_Builder_Theme_Styles::apply(
			array( 'elements' => array( 'link' => array( 'color' => array( 'text' => '#c62026' ) ) ) ),
			'theme'
		);

		$stored = $this->stored();
		$this->assertSame( '2px', $stored['styles']['elements']['button']['border']['radius'] );
		$this->assertSame( '#c62026', $stored['styles']['elements']['link']['color']['text'] );
	}

	/**
	 * WordPress does not sanitize a theme.json `css` property and gates it on
	 * `edit_css` for exactly that reason, so it is refused here rather than
	 * written — a string that closes its own selector can write rules for the
	 * whole document, and this ability's output is meant to be able to travel.
	 */
	public function test_raw_css_is_refused() {
		$this->seed_theme_json();

		$result = Pattern_Builder_Theme_Styles::apply(
			array( 'css' => '} body { display: none } .x {' ),
			'theme'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_styles_css_refused', $result->get_error_code() );
		$this->assertStringContainsString( 'css', $result->get_error_message() );

		$stored = $this->stored();
		$this->assertArrayNotHasKey( 'styles', $stored );
	}

	public function test_raw_css_is_refused_wherever_it_is_nested() {
		$this->seed_theme_json();

		$result = Pattern_Builder_Theme_Styles::apply(
			array(
				'elements' => array(
					'button' => array( 'css' => '&:hover { opacity: 0 }' ),
				),
			),
			'theme'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_styles_css_refused', $result->get_error_code() );
		$this->assertStringContainsString( 'elements.button.css', $result->get_error_message() );
	}

	/**
	 * Core's schema drops what it does not recognise. Dropping it silently
	 * would leave an agent believing it had set something and building the
	 * rest of the design on top of a property that is not there.
	 */
	public function test_unrecognised_properties_are_reported_rather_than_swallowed() {
		$this->seed_theme_json();

		$result = Pattern_Builder_Theme_Styles::apply(
			array(
				'typography' => array(
					'fontSize'   => 'var:preset|font-size|large',
					'fontSizzle' => '3rem',
				),
			),
			'theme'
		);

		$this->assertNotWPError( $result );
		$this->assertContains( 'typography.fontSize', $result['written'] );
		$this->assertContains( 'typography.fontSizzle', $result['skipped'] );
	}

	public function test_nothing_recognisable_is_an_error_not_a_silent_success() {
		$this->seed_theme_json();

		$result = Pattern_Builder_Theme_Styles::apply( array( 'notAThing' => 'x' ), 'theme' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_styles_none_valid', $result->get_error_code() );
	}

	public function test_user_destination_writes_global_styles() {
		$result = Pattern_Builder_Theme_Styles::apply(
			array( 'color' => array( 'background' => 'var:preset|color|base' ) ),
			'user'
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'user', $result['destination'] );

		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$config  = json_decode( (string) get_post( $post_id )->post_content, true );
		$this->assertSame( 'var(--wp--preset--color--base)', $config['styles']['color']['background'] );
	}

	public function test_empty_styles_is_refused() {
		$result = Pattern_Builder_Theme_Styles::apply( array(), 'theme' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_styles_empty', $result->get_error_code() );
	}

	public function test_the_theme_destination_needs_a_theme_json() {
		$result = Pattern_Builder_Theme_Styles::apply(
			array( 'color' => array( 'background' => '#ffffff' ) ),
			'theme'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_no_theme_json', $result->get_error_code() );
	}
}
