<?php
/**
 * The two themes pattern work is checked against.
 *
 * They are a matched pair and only useful as one: Blank Theme says whether a
 * pattern's own design is right, Opinionated Theme says whether it survives a
 * design system that is not its own. Both claims are the kind that rot
 * silently — a core release adds a default preset group, and the control quietly
 * stops being a control — so they are asserted rather than trusted.
 *
 * @package PatternBuilder
 */

/**
 * Blank Theme and Opinionated Theme.
 */
class Test_Lab_Themes extends WP_UnitTestCase {

	/**
	 * Read a theme's theme.json from the fixture directory.
	 *
	 * @param string $slug Theme directory name.
	 * @return array
	 */
	private function theme_json( $slug ) {
		$path = dirname( __DIR__, 2 ) . '/dev-assets/themes/' . $slug . '/theme.json';
		$this->assertFileExists( $path );
		$data = json_decode( file_get_contents( $path ), true );
		$this->assertIsArray( $data, 'theme.json must parse.' );
		return $data;
	}

	/**
	 * Blank Theme opts out of every default preset group core ships.
	 *
	 * Without these a theme that declares no palette still gets core's, and the
	 * control would be quietly testing a pattern against a design system after
	 * all — which is the one thing it exists not to do.
	 */
	public function test_blank_theme_declines_every_core_default() {
		$settings = $this->theme_json( 'blank-theme' )['settings'];

		$this->assertFalse( $settings['color']['defaultPalette'] );
		$this->assertFalse( $settings['color']['defaultGradients'] );
		$this->assertFalse( $settings['color']['defaultDuotone'] );
		$this->assertFalse( $settings['typography']['defaultFontSizes'] );
		$this->assertFalse( $settings['spacing']['defaultSpacingSizes'] );
	}

	/**
	 * And declares nothing of its own to put in their place.
	 */
	public function test_blank_theme_declares_no_presets_and_no_styles() {
		$json = $this->theme_json( 'blank-theme' );

		$this->assertSame( array(), $json['settings']['color']['palette'] );
		$this->assertSame( array(), $json['settings']['typography']['fontSizes'] );
		$this->assertSame( array(), $json['settings']['typography']['fontFamilies'] );
		$this->assertSame( array(), $json['settings']['spacing']['spacingSizes'] );
		$this->assertSame( array(), $json['styles'] );
	}

	/**
	 * Its templates constrain nothing, so a full-bleed band is full-bleed
	 * because the pattern said so.
	 *
	 * A constrained wrapper around post-content caps every band inside it at the
	 * content width — the failure that reads as a broken pattern and is not one.
	 */
	public function test_blank_theme_templates_impose_no_layout() {
		$dir = dirname( __DIR__, 2 ) . '/dev-assets/themes/blank-theme/templates/';

		foreach ( array( 'index.html', 'page.html', 'single.html' ) as $file ) {
			$markup = file_get_contents( $dir . $file );

			$this->assertStringNotContainsString(
				'"type":"constrained"',
				$markup,
				$file . ' must not constrain: the control theme imposes nothing.'
			);
			$this->assertStringContainsString( 'wp:post-content', $markup, $file . ' must render the content.' );
		}
	}

	/**
	 * Opinionated Theme collides on the slugs everybody reaches for.
	 *
	 * A pattern that assumes `medium` is a familiar size, or that `primary` is
	 * some particular colour, is a pattern that will look wrong on somebody's
	 * site. Tokens are never overwritten, so a colliding slug resolves to the
	 * theme's value and the pattern gets a size it did not choose.
	 */
	public function test_opinionated_theme_collides_on_the_usual_slugs() {
		$settings = $this->theme_json( 'opinionated-theme' )['settings'];

		$sizes = wp_list_pluck( $settings['typography']['fontSizes'], 'size', 'slug' );
		foreach ( array( 'small', 'medium', 'large', 'x-large' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $sizes, 'The collision is the point of this theme.' );
		}

		$colors = wp_list_pluck( $settings['color']['palette'], 'color', 'slug' );
		$this->assertArrayHasKey( 'primary', $colors );
		$this->assertArrayHasKey( 'accent', $colors );
	}

	/**
	 * It is opinionated, not broken: an alignfull band still escapes.
	 *
	 * The distinction matters. A theme whose post-content sits in a flow group
	 * inside a constrained one caps every band at the content width, which is a
	 * fault rather than a view — and a harness that shipped it would teach the
	 * wrong lesson.
	 */
	public function test_opinionated_theme_still_lets_a_band_go_full_bleed() {
		$markup = file_get_contents(
			dirname( __DIR__, 2 ) . '/dev-assets/themes/opinionated-theme/templates/page.html'
		);

		$this->assertStringContainsString( '"type":"constrained"', $markup );
		$this->assertStringContainsString( '"layout":{"inherit":true}', $markup );
	}

	/**
	 * Its content width is narrow enough that a pattern assuming room shows it.
	 */
	public function test_opinionated_theme_is_narrow() {
		$layout = $this->theme_json( 'opinionated-theme' )['settings']['layout'];

		$this->assertSame( '560px', $layout['contentSize'] );
		$this->assertLessThan(
			(int) $this->theme_json( 'blank-theme' )['settings']['layout']['contentSize'],
			(int) $layout['contentSize'],
			'The pair is only useful if the two disagree about width.'
		);
	}

	/**
	 * The claim that matters, asserted the only way that settles it.
	 *
	 * theme.json cannot make a theme blank on its own, which is worth knowing
	 * because it reads as though it can. `settings.color.defaultPalette: false`
	 * hides core's colours from the editor's picker and governs whether a theme
	 * may reuse their slugs — it does not stop
	 * `--wp--preset--color--vivid-red` being emitted, so a pattern can still
	 * depend on one without either party noticing. Core's presets arrive
	 * through `wp_theme_json_data_default`, and emptying them there is what
	 * actually leaves nothing behind; the theme's functions.php does that, and
	 * this is the test that says whether it worked.
	 */
	public function test_blank_theme_really_resolves_to_nothing() {
		register_theme_directory( dirname( __DIR__, 2 ) . '/dev-assets/themes' );
		delete_site_transient( 'theme_roots' );

		if ( ! wp_get_theme( 'blank-theme' )->exists() ) {
			$this->markTestSkipped( 'The fixture theme directory is not registered in this environment.' );
		}

		switch_theme( 'blank-theme' );

		// The test harness does not load a theme's functions.php on
		// switch_theme(), so apply what it registers.
		require_once dirname( __DIR__, 2 ) . '/dev-assets/themes/blank-theme/functions.php';
		wp_clean_theme_json_cache();

		$css = wp_get_global_stylesheet( array( 'variables' ) );

		$leaked = array();
		foreach ( array( 'vivid-red', 'pale-pink', 'cyan-bluish-gray', 'vivid-purple' ) as $slug ) {
			if ( false !== strpos( $css, '--wp--preset--color--' . $slug ) ) {
				$leaked[] = 'color/' . $slug;
			}
		}
		foreach ( array( 'small', 'medium', 'large', 'x-large' ) as $slug ) {
			if ( false !== strpos( $css, '--wp--preset--font-size--' . $slug ) ) {
				$leaked[] = 'font-size/' . $slug;
			}
		}

		$this->assertSame(
			array(),
			$leaked,
			'Blank Theme emitted presets it is meant to have none of: ' . implode( ', ', $leaked )
		);
	}

	/**
	 * Both are real themes WordPress would list.
	 */
	public function test_both_themes_are_well_formed() {
		foreach ( array( 'blank-theme', 'opinionated-theme' ) as $slug ) {
			$dir = dirname( __DIR__, 2 ) . '/dev-assets/themes/' . $slug;

			$this->assertFileExists( $dir . '/style.css' );
			$this->assertFileExists( $dir . '/theme.json' );
			$this->assertFileExists( $dir . '/templates/index.html' );

			$header = file_get_contents( $dir . '/style.css' );
			$this->assertMatchesRegularExpression( '/Theme Name:\s*\S/', $header );

			$json = $this->theme_json( $slug );
			$this->assertSame( 3, $json['version'] );
		}
	}
}
