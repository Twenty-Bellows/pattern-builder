<?php
/**
 * The two themes pattern work is checked against.
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
		$path = dirname( __DIR__, 2 ) . '/themes/' . $slug . '/theme.json';
		$this->assertFileExists( $path );
		$data = json_decode( file_get_contents( $path ), true );
		$this->assertIsArray( $data, 'theme.json must parse.' );
		return $data;
	}

	/**
	 * Blank Theme opts out of every default preset group core ships.
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
	 * Its templates constrain nothing, so a full-bleed band is full-bleed because the
	 * pattern said so.
	 */
	public function test_blank_theme_templates_impose_no_layout() {
		$dir = dirname( __DIR__, 2 ) . '/themes/blank-theme/templates/';

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
	 * Opinionated Theme follows the conventions the guide documents.
	 */
	public function test_opinionated_theme_uses_the_documented_slugs() {
		$settings = $this->theme_json( 'opinionated-theme' )['settings'];

		$colors = wp_list_pluck( $settings['color']['palette'], 'color', 'slug' );
		$this->assertArrayHasKey( 'base', $colors );
		$this->assertArrayHasKey( 'contrast', $colors );
		$this->assertArrayNotHasKey( 'text-default', $colors );
		$this->assertArrayNotHasKey( 'secondary', $colors );

		$sizes = wp_list_pluck( $settings['typography']['fontSizes'], 'size', 'slug' );
		$this->assertSame(
			array( 'small', 'medium', 'large', 'x-large', 'xx-large' ),
			array_keys( $sizes ),
			'The five-step ladder, in order, is what the default themes ship.'
		);

		$spacing = wp_list_pluck( $settings['spacing']['spacingSizes'], 'size', 'slug' );
		foreach ( array( '40', '50', '60' ) as $step ) {
			$this->assertArrayHasKey( $step, $spacing, 'The safe middle of the scale must exist.' );
		}
	}

	/**
	 * And it carries the tier-2 roles the guide asks themes to agree on.
	 */
	public function test_opinionated_theme_carries_the_tier_two_roles() {
		$colors = wp_list_pluck(
			$this->theme_json( 'opinionated-theme' )['settings']['color']['palette'],
			'color',
			'slug'
		);

		foreach ( array( 'surface', 'surface-variant', 'text-muted', 'primary', 'primary-hover', 'accent', 'hairline' ) as $role ) {
			$this->assertArrayHasKey( $role, $colors, $role . ' is one of the roles the guide standardises.' );
		}
	}

	/**
	 * Semantics live in the name, never in the slug.
	 */
	public function test_opinionated_theme_keeps_semantics_out_of_the_slugs() {
		$spacing = $this->theme_json( 'opinionated-theme' )['settings']['spacing']['spacingSizes'];
		$slugs   = wp_list_pluck( $spacing, 'slug' );
		$names   = wp_list_pluck( $spacing, 'name', 'slug' );

		foreach ( $slugs as $slug ) {
			$this->assertMatchesRegularExpression( '/^\d+$/', $slug, 'Spacing slugs are numeric or they do not travel.' );
		}

		foreach ( array( 'xs', 'sm', 'md', 'lg', 'xl' ) as $tempting ) {
			$this->assertNotContains( $tempting, $slugs );
		}

		$this->assertNotEmpty( $names['40'], 'The readable label belongs in name.' );
	}

	/**
	 * Its values are nobody's defaults, which is what makes it a test.
	 */
	public function test_opinionated_theme_agrees_with_nothing_by_accident() {
		$settings = $this->theme_json( 'opinionated-theme' )['settings'];
		$sizes    = wp_list_pluck( $settings['typography']['fontSizes'], 'size', 'slug' );
		$colors   = wp_list_pluck( $settings['color']['palette'], 'color', 'slug' );

		$this->assertNotSame( '1rem', $sizes['medium'] );
		$this->assertNotSame( '#ffffff', strtolower( $colors['base'] ) );
		$this->assertNotSame( '#000000', strtolower( $colors['contrast'] ) );
	}

	/**
	 * Opinionated Theme collides on the slugs everybody reaches for.
	 */
	public function test_opinionated_theme_collides_on_the_usual_slugs() {
		$settings = $this->theme_json( 'opinionated-theme' )['settings'];

		$sizes = wp_list_pluck( $settings['typography']['fontSizes'], 'size', 'slug' );
		foreach ( array( 'small', 'medium', 'large', 'x-large' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $sizes, 'The collision is the point of this theme.' );
		}

		$colors = wp_list_pluck( $settings['color']['palette'], 'color', 'slug' );
		$this->assertArrayHasKey( 'base', $colors );
		$this->assertArrayHasKey( 'accent', $colors );
	}

	/**
	 * It is opinionated, not broken: an alignfull band still escapes.
	 */
	public function test_opinionated_theme_still_lets_a_band_go_full_bleed() {
		$markup = file_get_contents(
			dirname( __DIR__, 2 ) . '/themes/opinionated-theme/templates/page.html'
		);

		$this->assertStringContainsString( '"type":"constrained"', $markup );
		$this->assertStringContainsString( '"layout":{"inherit":true}', $markup );
	}

	/**
	 * Its content width is narrow enough that a pattern assuming room shows it.
	 */
	public function test_opinionated_theme_is_narrow() {
		$layout = $this->theme_json( 'opinionated-theme' )['settings']['layout'];
		$this->assertStringEndsWith( 'rem', $layout['contentSize'] );

		$theirs = (float) $layout['contentSize'] * 16;
		$blank  = (float) $this->theme_json( 'blank-theme' )['settings']['layout']['contentSize'];

		$this->assertLessThan(
			$blank,
			$theirs,
			'The pair is only useful if the two disagree about width.'
		);
	}

	/**
	 * The claim that matters, asserted the only way that settles it.
	 */
	public function test_blank_theme_really_resolves_to_nothing() {
		register_theme_directory( dirname( __DIR__, 2 ) . '/themes' );
		delete_site_transient( 'theme_roots' );

		if ( ! wp_get_theme( 'blank-theme' )->exists() ) {
			$this->markTestSkipped( 'The fixture theme directory is not registered in this environment.' );
		}

		switch_theme( 'blank-theme' );
		require_once dirname( __DIR__, 2 ) . '/themes/blank-theme/functions.php';
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
	/**
	 * The theme is the worked example of the guide, and the guide now covers three layers
	 * rather than one — so it carries a block style variation too, in the only form that
	 * registers one without PHP: a partial in the theme's `styles/` directory with a
	 * `blockTypes` key.
	 */
	public function test_opinionated_theme_ships_a_block_style_variation() {
		$path = dirname( __DIR__, 2 ) . '/themes/opinionated-theme/styles/inset-panel.json';
		$this->assertFileExists( $path );

		$partial = json_decode( (string) file_get_contents( $path ), true );
		$this->assertSame( array( 'core/group' ), $partial['blockTypes'] );
		$this->assertNotEmpty( $partial['styles'] );
		$this->assertSame( 'inset-panel', $partial['slug'] );
		$encoded = wp_json_encode( $partial['styles'] );
		$this->assertStringContainsString( 'var(--wp--preset--color--surface)', $encoded );
		$this->assertStringNotContainsString( '#', $encoded );
	}

	public function test_both_themes_are_well_formed() {
		foreach ( array( 'blank-theme', 'opinionated-theme' ) as $slug ) {
			$dir = dirname( __DIR__, 2 ) . '/themes/' . $slug;

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
