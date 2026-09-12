<?php
/**
 * Block style variations travelling with a pattern: what a package carries, what it
 * deliberately does not, how the names are kept apart, and what an install writes.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Block_Style_Variations;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Tokens;

class Test_Cloud_Variations extends WP_UnitTestCase {
	/**
	 * The writable theme directory these tests write into.
	 *
	 * @var string
	 */
	private $theme_dir;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-variations-test';
		foreach ( array( '/patterns', '/styles' ) as $sub ) {
			if ( ! is_dir( $this->theme_dir . $sub ) ) {
				mkdir( $this->theme_dir . $sub, 0777, true );
			}
		}
		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'stylesheet', array( $this, 'theme_slug' ) );
		wp_clean_theme_json_cache();
	}

	public function tear_down() {
		foreach ( array( '/patterns/*.php', '/styles/*.json' ) as $glob ) {
			foreach ( (array) glob( $this->theme_dir . $glob ) as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		foreach ( array( 'card', 'card-wide', 'card-ns', 'card-token', 'card-css', 'card-bad-css', 'card-css-ns', 'card-ns-inner', 'card-css-install', 'card-css-refused', 'card-install', 'studio-a-heroes-card', 'studio-a-heroes-card-ns', 'studio-a-heroes-card-css-ns', 'studio-a-heroes-card-ns-inner' ) as $slug ) {
			if ( WP_Block_Styles_Registry::get_instance()->is_registered( 'core/group', $slug ) ) {
				unregister_block_style( 'core/group', $slug );
			}
		}

		remove_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		remove_filter( 'stylesheet', array( $this, 'theme_slug' ) );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		wp_clean_theme_json_cache();
		parent::tear_down();
	}

	public function theme_dir() {
		return $this->theme_dir;
	}

	public function theme_slug() {
		return 'simple-theme';
	}

	/**
	 * Write a variation partial, as `add-block-style-variation` does.
	 *
	 * @param string $slug Variation slug.
	 * @param array  $styles Its styles.
	 */
	private function make_variation( $slug, $styles = array( 'border' => array( 'radius' => '999px' ) ) ) {
		$written = Pattern_Builder_Block_Style_Variations::add(
			array(
				'slug'       => $slug,
				'title'      => ucfirst( $slug ),
				'blockTypes' => array( 'core/group' ),
				'styles'     => $styles,
			)
		);
		$this->assertNotWPError( $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
		wp_clean_theme_json_cache();
	}

	/**
	 * Write a theme pattern file.
	 *
	 * @param string $slug Pattern slug.
	 * @param string $content Block markup.
	 */
	private function make_theme_pattern( $slug, $content ) {
		$header = "<?php\n/**\n * Title: {$slug}\n * Slug: simple-theme/{$slug}\n * Description: A test pattern.\n */\n?>\n";
		file_put_contents( $this->theme_dir . '/patterns/' . $slug . '.php', $header . $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * A group carrying one variation class.
	 *
	 * @param string $slug Variation slug.
	 * @return string
	 */
	private function group_with( $slug ) {
		return '<!-- wp:group {"className":"is-style-' . $slug . '"} -->' . "\n"
			. '<div class="wp-block-group is-style-' . $slug . '"></div>' . "\n"
			. '<!-- /wp:group -->';
	}

	public function test_a_package_carries_the_variation_its_markup_applies() {
		$this->make_variation( 'card' );
		$this->make_theme_pattern( 'banner', $this->group_with( 'card' ) );

		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'theme', 'simple-theme/banner' );

		$this->assertNotWPError( $exported );
		$this->assertCount( 1, $exported['pbp']['variations'] );
		$this->assertSame( 'card', $exported['pbp']['variations'][0]['slug'] );
		$this->assertSame( array( 'core/group' ), $exported['pbp']['variations'][0]['blockTypes'] );
		$this->assertSame( '999px', $exported['pbp']['variations'][0]['styles']['border']['radius'] );
	}

	/**
	 * A variation declared in a block's own block.json ships with WordPress, so carrying it
	 * would be redundant at best and would collide with core's at the far end at worst.
	 */
	public function test_a_variation_wordpress_already_has_is_not_carried() {
		$this->make_theme_pattern(
			'outlined',
			'<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} -->' . "\n"
				. '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button">Go</a></div>' . "\n"
				. '<!-- /wp:button --></div>' . "\n" . '<!-- /wp:buttons -->'
		);

		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'theme', 'simple-theme/outlined' );

		$this->assertNotWPError( $exported );
		$this->assertSame( array(), $exported['pbp']['variations'] );
	}

	/**
	 * A variation slug is a name in a shared namespace, so it hangs under the collection
	 * carrying it — and both halves have to move: the class in the markup and the slug of
	 * the definition beside it.
	 */
	public function test_the_namespace_rewrite_moves_the_class_and_the_slug_together() {
		$this->make_variation( 'card-ns' );
		$this->make_theme_pattern( 'banner-ns', $this->group_with( 'card-ns' ) );

		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'theme', 'simple-theme/banner-ns', 'studio-a/heroes' );

		$this->assertNotWPError( $exported );
		$this->assertSame( 'studio-a-heroes-card-ns', $exported['pbp']['variations'][0]['slug'] );
		$this->assertStringContainsString( 'is-style-studio-a-heroes-card-ns', $exported['pbp']['content'] );
		$this->assertStringNotContainsString( '"is-style-card-ns"', $exported['pbp']['content'] );
	}

	/**
	 * `is-style-card` must not match inside `is-style-card-wide`: they are two variations,
	 * and renaming one by prefix-match would rename the other's class into something
	 * nothing defines.
	 */
	public function test_a_rename_does_not_reach_into_a_longer_sibling() {
		list( $content, $variations ) = Pattern_Builder_Cloud_Porter::rewrite_variations(
			$this->group_with( 'card' ) . "\n" . $this->group_with( 'card-wide' ),
			array( array( 'slug' => 'card', 'title' => 'Card', 'blockTypes' => array( 'core/group' ), 'styles' => array() ) ),
			'studio-a/heroes'
		);

		$this->assertStringContainsString( 'is-style-studio-a-heroes-card"', $content );
		$this->assertStringContainsString( 'is-style-card-wide', $content );
		$this->assertStringNotContainsString( 'is-style-studio-a-heroes-card-wide', $content );
		$this->assertSame( 'studio-a-heroes-card', $variations[0]['slug'] );
	}

	/**
	 * The markup carries the class and no colour at all — the preset the variation resolves
	 * to lives in its definition.
	 */
	public function test_a_token_referenced_only_inside_a_variation_is_still_collected() {
		add_filter( 'wp_theme_json_data_theme', array( $this, 'inject_accent' ) );
		wp_clean_theme_json_cache();

		$this->make_variation( 'card-token', array( 'color' => array( 'background' => 'var:preset|color|accent' ) ) );

		$tokens = Pattern_Builder_Cloud_Tokens::collect_tree( $this->group_with( 'card-token' ) );
		$slugs  = wp_list_pluck( $tokens, 'slug' );

		remove_filter( 'wp_theme_json_data_theme', array( $this, 'inject_accent' ) );

		$this->assertContains( 'accent', $slugs );
	}

	/**
	 * Give the test theme one preset for the variation to reference.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme data.
	 * @return WP_Theme_JSON_Data
	 */
	public function inject_accent( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version'  => 3,
				'settings' => array(
					'color' => array(
						'palette' => array(
							array(
								'slug'  => 'accent',
								'name'  => 'Accent',
								'color' => '#c62026',
							),
						),
					),
				),
			)
		);
	}

	public function test_installing_writes_a_missing_variation_and_skips_one_already_here() {
		$variation = array(
			'slug'       => 'card-install',
			'title'      => 'Card',
			'blockTypes' => array( 'core/group' ),
			'styles'     => array( 'border' => array( 'radius' => '999px' ) ),
		);

		$this->assertSame( 'written', Pattern_Builder_Block_Style_Variations::install( $variation ) );
		$this->assertTrue( file_exists( $this->theme_dir . '/styles/card-install.json' ) );

		wp_clean_theme_json_cache();
		$again = Pattern_Builder_Block_Style_Variations::install(
			array_merge( $variation, array( 'styles' => array( 'border' => array( 'radius' => '0px' ) ) ) )
		);
		$this->assertSame( 'skipped', $again );

		$partial = json_decode( (string) file_get_contents( $this->theme_dir . '/styles/card-install.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertSame( '999px', $partial['styles']['border']['radius'] );
	}

	/**
	 * Write a partial by hand, which is how a variation gets CSS the ability would have
	 * refused — and how one gets CSS at all in a theme nobody built with these abilities.
	 *
	 * @param string $slug Variation slug.
	 * @param string $css Its `css` string.
	 */
	private function make_partial_with_css( $slug, $css ) {
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->theme_dir . '/styles/' . $slug . '.json',
			wp_json_encode(
				array(
					'version'    => 3,
					'title'      => ucfirst( $slug ),
					'slug'       => $slug,
					'blockTypes' => array( 'core/group' ),
					'styles'     => array( 'css' => $css ),
				)
			)
		);
		wp_clean_theme_json_cache();
	}

	/**
	 * The CSS is most of what a variation does — a pseudo-element, a descendant rule, a
	 * hover state — so a package that left it behind would carry a look that does almost
	 * nothing at the far end.
	 */
	public function test_a_package_carries_the_css_its_variation_defines() {
		$css = 'position: relative; &::before { content: ""; inset: 0; }';
		$this->make_partial_with_css( 'card-css', $css );
		$this->make_theme_pattern( 'banner-css', $this->group_with( 'card-css' ) );

		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'theme', 'simple-theme/banner-css' );

		$this->assertNotWPError( $exported, is_wp_error( $exported ) ? $exported->get_error_message() : '' );
		$this->assertSame( $css, $exported['pbp']['variations'][0]['styles']['css'] );
	}

	/**
	 * CSS outside the subset still refuses the export, and says which variation and which
	 * rule — better than letting the upload fail at the far end with nothing local to point
	 * at.
	 */
	public function test_a_variation_carrying_unsafe_css_refuses_the_export() {
		$this->make_partial_with_css( 'card-bad-css', 'background: url(https://evil.test/x.png);' );
		$this->make_theme_pattern( 'banner-bad-css', $this->group_with( 'card-bad-css' ) );

		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'theme', 'simple-theme/banner-bad-css' );

		$this->assertWPError( $exported );
		$this->assertSame( 'pb_variation_css_cannot_travel', $exported->get_error_code() );
		$this->assertStringContainsString( 'card-bad-css', $exported->get_error_message() );
		$this->assertStringContainsString( 'url(', $exported->get_error_message() );
	}

	/**
	 * A variation's own CSS can name a variation by class — `& .is-style-x` — so the
	 * namespace stamp has to reach inside the string too, or the two halves of a rename
	 * drift apart and the rule styles a class that is no longer there.
	 */
	public function test_the_namespace_rewrite_reaches_inside_the_css() {
		$this->make_partial_with_css(
			'card-css-ns',
			'& .is-style-card-ns-inner { color: red; } & .wp-block-button.is-style-outline .wp-block-button__link { color: blue; }'
		);
		$this->make_variation( 'card-ns-inner' );
		$this->make_theme_pattern(
			'banner-css-ns',
			$this->group_with( 'card-css-ns' ) . "\n" . $this->group_with( 'card-ns-inner' )
		);

		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'theme', 'simple-theme/banner-css-ns', 'studio-a/heroes' );

		$this->assertNotWPError( $exported, is_wp_error( $exported ) ? $exported->get_error_message() : '' );

		$slugs = wp_list_pluck( $exported['pbp']['variations'], 'slug' );
		$this->assertContains( 'studio-a-heroes-card-css-ns', $slugs );
		$this->assertContains( 'studio-a-heroes-card-ns-inner', $slugs );

		$carried = $exported['pbp']['variations'][ array_search( 'studio-a-heroes-card-css-ns', $slugs, true ) ];
		$this->assertStringContainsString( 'is-style-studio-a-heroes-card-ns-inner', $carried['styles']['css'] );
		$this->assertStringContainsString( 'is-style-outline', $carried['styles']['css'] );
		$this->assertStringNotContainsString( 'is-style-studio-a-heroes-outline', $carried['styles']['css'] );
	}

	/**
	 * And the install writes it, so the look arrives whole.
	 */
	public function test_installing_writes_the_css_into_the_partial() {
		$css = 'position: relative; &:hover { outline: 2px solid #abcdef; }';

		$written = Pattern_Builder_Block_Style_Variations::install(
			array(
				'slug'       => 'card-css-install',
				'title'      => 'Card',
				'blockTypes' => array( 'core/group' ),
				'styles'     => array( 'css' => $css ),
			)
		);

		$this->assertSame( 'written', $written );

		$partial = json_decode( (string) file_get_contents( $this->theme_dir . '/styles/card-css-install.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertSame( $css, $partial['styles']['css'] );
	}

	/**
	 * The check that matters most, because this is the machine that will run the CSS.
	 */
	public function test_an_install_skips_a_variation_whose_css_this_site_will_not_write() {
		$refused = Pattern_Builder_Block_Style_Variations::install(
			array(
				'slug'       => 'card-css-refused',
				'title'      => 'Card',
				'blockTypes' => array( 'core/group' ),
				'styles'     => array( 'css' => 'content: "</style><script>alert(1)</script>";' ),
			)
		);

		$this->assertWPError( $refused );
		$this->assertSame( 'pb_variation_css_refused', $refused->get_error_code() );
		$this->assertFalse( file_exists( $this->theme_dir . '/styles/card-css-refused.json' ) );

		$pbp = array(
			'format'     => 'pbp/1',
			'title'      => 'Downloaded Band',
			'slug'       => 'downloaded-band',
			'content'    => $this->group_with( 'card-css-refused' ),
			'variations' => array(
				array(
					'slug'       => 'card-css-refused',
					'title'      => 'Card',
					'blockTypes' => array( 'core/group' ),
					'styles'     => array( 'css' => 'content: "</style><script>alert(1)</script>";' ),
				),
			),
		);

		add_filter(
			'pre_http_request',
			static function () use ( $pbp ) {
				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $pbp ),
				);
			}
		);

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->install_cloud_pattern( 77, 'user', false );
		remove_all_filters( 'pre_http_request' );

		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array(), $result['variationsWritten'] );
		$this->assertCount( 1, $result['variationsRefused'] );
		$this->assertSame( 'card-css-refused', $result['variationsRefused'][0]['slug'] );
		$this->assertStringContainsString( '<', $result['variationsRefused'][0]['reason'] );
		$this->assertFalse( file_exists( $this->theme_dir . '/styles/card-css-refused.json' ) );
	}
}
