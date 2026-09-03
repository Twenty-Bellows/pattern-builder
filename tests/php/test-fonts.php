<?php
/**
 * Installing a typeface by name.
 *
 * The collection and the font files are mocked at the HTTP layer, as every
 * test here that talks to something remote is. The assertion that matters
 * most is the preset: `wp_print_font_faces()` builds its `@font-face` rules
 * from the merged theme.json, so a font installed without a `fontFamily`
 * preset carrying `fontFace` is a font that never renders.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Fonts;

class Test_Fonts extends WP_UnitTestCase {

	/**
	 * The writable theme directory these tests treat as the active theme.
	 *
	 * @var string
	 */
	private $theme_dir;

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-fonts-test';

		if ( ! is_dir( $this->theme_dir ) ) {
			mkdir( $this->theme_dir, 0777, true );
		}

		// A theme.json for the preset to be written into.
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->theme_dir . '/theme.json',
			wp_json_encode( array( 'version' => 3 ) )
		);

		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'template_directory', array( $this, 'theme_dir' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );

		delete_transient( Pattern_Builder_Fonts::INDEX_TRANSIENT );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		remove_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		remove_filter( 'template_directory', array( $this, 'theme_dir' ) );

		foreach ( (array) glob( $this->theme_dir . '/assets/fonts/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		delete_transient( Pattern_Builder_Fonts::INDEX_TRANSIENT );

		parent::tear_down();
	}

	/**
	 * The writable theme directory, as a filter.
	 *
	 * @return string
	 */
	public function theme_dir() {
		return $this->theme_dir;
	}

	/**
	 * Stand in for the font collection and for Google's file server.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array
	 */
	public function mock_http( $preempt, $args, $url ) {
		if ( false !== strpos( $url, 'fonts.gstatic.com' ) ) {
			return array(
				'headers'  => array(),
				// Not a real font; nothing here parses one.
				'body'     => 'wOF2-stand-in-' . md5( $url ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		}

		if ( false !== strpos( $url, 's.w.org' ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $this->collection_fixture() ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		}

		return $preempt;
	}

	/**
	 * A collection with the two cases that matter: a family whose weights
	 * are separate files, and a variable family that answers a range with
	 * one file.
	 *
	 * @return array
	 */
	private function collection_fixture() {
		return array(
			'name'          => 'Google Fonts',
			'font_families' => array(
				array(
					'font_family_settings' => array(
						'name'       => 'Testerly',
						'slug'       => 'testerly',
						'fontFamily' => 'Testerly, sans-serif',
						'fontFace'   => array(
							array(
								'fontFamily' => 'Testerly',
								'fontStyle'  => 'normal',
								'fontWeight' => '400',
								'src'        => array( 'https://fonts.gstatic.com/s/testerly/v1/regular.woff2' ),
							),
							array(
								'fontFamily' => 'Testerly',
								'fontStyle'  => 'normal',
								'fontWeight' => '700',
								'src'        => array( 'https://fonts.gstatic.com/s/testerly/v1/bold.woff2' ),
							),
							array(
								'fontFamily' => 'Testerly',
								'fontStyle'  => 'italic',
								'fontWeight' => '400',
								'src'        => array( 'https://fonts.gstatic.com/s/testerly/v1/italic.woff2' ),
							),
						),
					),
					'categories'           => array( 'sans-serif' ),
				),
				array(
					'font_family_settings' => array(
						'name'       => 'Variabilia',
						'slug'       => 'variabilia',
						'fontFamily' => 'Variabilia, serif',
						'fontFace'   => array(
							array(
								'fontFamily' => 'Variabilia',
								'fontStyle'  => 'normal',
								'fontWeight' => '100 900',
								'src'        => array( 'https://fonts.gstatic.com/s/variabilia/v1/variable.woff2' ),
							),
						),
					),
					'categories'           => array( 'serif' ),
				),
			),
		);
	}

	/**
	 * The index lists what the collection offers, without its file lists.
	 */
	public function test_the_index_lists_the_families() {
		$families = Pattern_Builder_Fonts::search();

		$this->assertNotWPError( $families );
		$this->assertCount( 2, $families );
		$this->assertSame( 'Testerly', $families[0]['name'] );
		$this->assertSame( array( 'sans-serif' ), $families[0]['categories'] );
	}

	/**
	 * Search matches on the name, case-insensitively.
	 */
	public function test_search_matches_the_name() {
		$families = Pattern_Builder_Fonts::search( 'variab' );

		$this->assertCount( 1, $families );
		$this->assertSame( 'Variabilia', $families[0]['name'] );
	}

	/**
	 * Search filters by category.
	 */
	public function test_search_filters_by_category() {
		$families = Pattern_Builder_Fonts::search( '', 'serif' );

		$this->assertCount( 1, $families );
		$this->assertSame( 'Variabilia', $families[0]['name'] );
	}

	/**
	 * A family nobody has is refused by name, pointing at the listing.
	 */
	public function test_an_unknown_family_is_refused() {
		$result = Pattern_Builder_Fonts::family( 'Nonesuch' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_font_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'list-fonts', $result->get_error_message() );
	}

	/**
	 * A family is found by its slug as well as its name.
	 */
	public function test_a_family_is_found_by_slug() {
		$family = Pattern_Builder_Fonts::family( 'testerly' );

		$this->assertNotWPError( $family );
		$this->assertSame( 'Testerly', $family['name'] );
	}

	/**
	 * Installing into the theme writes the files under assets/fonts and the
	 * preset into theme.json, with `file:./` sources — the placeholder core
	 * rewrites into a theme URI, which is what lets the font travel with the
	 * theme rather than depend on this site's uploads.
	 */
	public function test_installing_into_the_theme_writes_files_and_the_preset() {
		$result = Pattern_Builder_Fonts::install( 'Testerly', array( '400', '700' ), array( 'normal' ), 'theme' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'theme', $result['destination'] );
		$this->assertSame( 'testerly', $result['slug'] );
		$this->assertCount( 2, $result['faces'] );

		$this->assertFileExists( $this->theme_dir . '/assets/fonts/testerly-400-normal.woff2' );
		$this->assertFileExists( $this->theme_dir . '/assets/fonts/testerly-700-normal.woff2' );

		$config = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$preset = $config['settings']['typography']['fontFamilies'][0];

		$this->assertSame( 'testerly', $preset['slug'] );
		$this->assertSame( 'Testerly, sans-serif', $preset['fontFamily'] );
		$this->assertCount( 2, $preset['fontFace'] );
		$this->assertSame(
			array( 'file:./assets/fonts/testerly-400-normal.woff2' ),
			$preset['fontFace'][0]['src']
		);
		$this->assertSame( '400', $preset['fontFace'][0]['fontWeight'] );
		$this->assertSame( 'normal', $preset['fontFace'][0]['fontStyle'] );
	}

	/**
	 * The answer says how to reference the font, which is the whole point of
	 * registering the preset.
	 */
	public function test_the_answer_says_how_to_reference_the_font() {
		$result = Pattern_Builder_Fonts::install( 'Testerly', array( '400' ), array( 'normal' ), 'theme' );

		$this->assertSame( '"fontFamily":"testerly"', $result['reference']['attribute'] );
		$this->assertSame( 'has-testerly-font-family', $result['reference']['class'] );
		$this->assertSame( 'var(--wp--preset--font-family--testerly)', $result['reference']['css'] );
	}

	/**
	 * Italic is installed when asked for, and not when it isn't.
	 */
	public function test_styles_are_installed_as_asked() {
		$result = Pattern_Builder_Fonts::install( 'Testerly', array( '400' ), array( 'normal', 'italic' ), 'theme' );

		$this->assertCount( 2, $result['faces'] );
		$this->assertFileExists( $this->theme_dir . '/assets/fonts/testerly-400-normal.woff2' );
		$this->assertFileExists( $this->theme_dir . '/assets/fonts/testerly-400-italic.woff2' );
	}

	/**
	 * A variable font covers a range of weights with one file, so asking for
	 * three weights downloads it once rather than three times.
	 */
	public function test_a_variable_font_is_installed_once_for_a_range() {
		$result = Pattern_Builder_Fonts::install( 'Variabilia', array( '300', '400', '800' ), array( 'normal' ), 'theme' );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['faces'] );
		$this->assertSame( '100 900', $result['faces'][0]['weight'] );
		// The space in the range would be escaped in every URL naming it.
		$this->assertFileExists( $this->theme_dir . '/assets/fonts/variabilia-100-900-normal.woff2' );
	}

	/**
	 * A weight the family does not have is refused, naming what was asked
	 * for rather than installing something else.
	 */
	public function test_a_weight_the_family_lacks_is_refused() {
		$result = Pattern_Builder_Fonts::install( 'Testerly', array( '950' ), array( 'normal' ), 'theme' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_font_no_match', $result->get_error_code() );
		$this->assertStringContainsString( '950', $result->get_error_message() );
	}

	/**
	 * Installing the same family twice leaves one preset: the token writer
	 * never overwrites a slug the site already defines.
	 */
	public function test_installing_twice_leaves_one_preset() {
		Pattern_Builder_Fonts::install( 'Testerly', array( '400' ), array( 'normal' ), 'theme' );
		$second = Pattern_Builder_Fonts::install( 'Testerly', array( '400' ), array( 'normal' ), 'theme' );

		$this->assertNotWPError( $second );

		$config = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertCount( 1, $config['settings']['typography']['fontFamilies'] );
	}

	/**
	 * Installing into Global Styles writes the preset there, with the files
	 * served from this site's uploads rather than from Google.
	 */
	public function test_installing_into_global_styles_writes_the_user_preset() {
		$result = Pattern_Builder_Fonts::install( 'Testerly', array( '400' ), array( 'normal' ), 'user' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'user', $result['destination'] );

		$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$config  = json_decode( get_post( $post_id )->post_content, true );
		$preset  = $config['settings']['typography']['fontFamilies'][0];

		$this->assertSame( 'testerly', $preset['slug'] );
		$this->assertNotEmpty( $preset['fontFace'][0]['src'][0] );
		$this->assertStringNotContainsString( 'gstatic.com', $preset['fontFace'][0]['src'][0] );
	}

	/**
	 * The theme.json this site has is left alone where it already defines the
	 * slug — a font the theme designed with is not replaced by a lookalike.
	 */
	public function test_an_existing_slug_is_not_overwritten() {
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->theme_dir . '/theme.json',
			wp_json_encode(
				array(
					'version'  => 3,
					'settings' => array(
						'typography' => array(
							'fontFamilies' => array(
								array(
									'slug'       => 'testerly',
									'name'       => 'The theme its own',
									'fontFamily' => 'Georgia, serif',
								),
							),
						),
					),
				)
			)
		);

		Pattern_Builder_Fonts::install( 'Testerly', array( '400' ), array( 'normal' ), 'theme' );

		$config = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$preset = $config['settings']['typography']['fontFamilies'][0];

		$this->assertCount( 1, $config['settings']['typography']['fontFamilies'] );
		$this->assertSame( 'Georgia, serif', $preset['fontFamily'] );
		$this->assertArrayNotHasKey( 'fontFace', $preset );
	}
}
