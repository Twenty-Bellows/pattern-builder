<?php
/**
 * Design tokens: reference scanning, resolution against global settings,
 * missing-token detection, and the Global Styles / theme.json writers.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Tokens;

class Test_Cloud_Tokens extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wp_theme_json_data_theme', array( $this, 'inject_theme_presets' ) );
		wp_clean_theme_json_cache();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		remove_filter( 'wp_theme_json_data_theme', array( $this, 'inject_theme_presets' ) );
		wp_clean_theme_json_cache();
		$theme_json = get_stylesheet_directory() . '/theme.json';
		if ( file_exists( $theme_json ) ) {
			unlink( $theme_json );
		}
		parent::tear_down();
	}

	/**
	 * Give the test theme a known palette/spacing/typography.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme data.
	 * @return WP_Theme_JSON_Data
	 */
	public function inject_theme_presets( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version'  => 3,
				'settings' => array(
					'color'      => array(
						'palette'   => array(
							array(
								'slug'  => 'brand',
								'name'  => 'Brand',
								'color' => '#4f46e5',
							),
						),
						'gradients' => array(
							array(
								'slug'     => 'dusk',
								'name'     => 'Dusk',
								'gradient' => 'linear-gradient(135deg, #4f46e5 0%, #14141f 100%)',
							),
						),
					),
					'spacing'    => array(
						'spacingSizes' => array(
							array(
								'slug' => 'jumbo',
								'name' => 'Jumbo',
								'size' => '5rem',
							),
						),
					),
					'typography' => array(
						'fontSizes'    => array(
							array(
								'slug' => 'mega',
								'name' => 'Mega',
								'size' => '3.5rem',
							),
						),
						'fontFamilies' => array(
							array(
								'slug'       => 'body-face',
								'name'       => 'Body Face',
								'fontFamily' => "Inter, 'Segoe UI', sans-serif",
							),
						),
					),
				),
			)
		);
	}

	private function sample_markup() {
		return '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|jumbo"}}},"backgroundColor":"brand","fontFamily":"body-face"} -->
<div class="wp-block-group has-brand-background-color has-background has-body-face-font-family" style="padding-top:var(--wp--preset--spacing--jumbo)"><!-- wp:heading {"fontSize":"mega","gradient":"dusk"} -->
<h2 class="wp-block-heading has-dusk-gradient-background has-mega-font-size">Hello</h2>
<!-- /wp:heading --><!-- wp:paragraph {"textColor":"brand"} -->
<p class="has-brand-color has-text-color">Copy</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->';
	}

	public function test_referenced_finds_every_reference_form_and_skips_support_classes() {
		$refs = Pattern_Builder_Cloud_Tokens::referenced( $this->sample_markup() );

		$this->assertSame( array( 'brand' ), $refs['color'] );
		$this->assertSame( array( 'body-face' ), $refs['fontFamily'] );
		$this->assertSame( array( 'jumbo' ), $refs['spacing'] );
		$this->assertSame( array( 'mega' ), $refs['fontSize'] );
		$this->assertSame( array( 'dusk' ), $refs['gradient'] );
	}

	public function test_collect_resolves_against_merged_settings() {
		$tokens = Pattern_Builder_Cloud_Tokens::collect( $this->sample_markup() );

		$by_key = array();
		foreach ( $tokens as $token ) {
			$by_key[ $token['type'] . ':' . $token['slug'] ] = $token;
		}

		$this->assertSame( '#4f46e5', $by_key['color:brand']['value'] );
		$this->assertSame( 'Brand', $by_key['color:brand']['name'] );
		$this->assertSame( '5rem', $by_key['spacing:jumbo']['value'] );
		$this->assertSame( '3.5rem', $by_key['fontSize:mega']['value'] );
		$this->assertSame( "Inter, 'Segoe UI', sans-serif", $by_key['fontFamily:body-face']['value'] );
		$this->assertStringStartsWith( 'linear-gradient', $by_key['gradient:dusk']['value'] );
	}

	public function test_collect_drops_unresolvable_references() {
		$tokens = Pattern_Builder_Cloud_Tokens::collect( '<!-- wp:paragraph {"textColor":"no-such-slug"} --><p class="has-no-such-slug-color has-text-color">x</p><!-- /wp:paragraph -->' );

		$this->assertSame( array(), $tokens );
	}

	public function test_missing_respects_existing_definitions_from_any_origin() {
		$tokens = array(
			array(
				'type'  => 'color',
				'slug'  => 'brand',
				'name'  => 'Brand',
				'value' => '#123456',
			), // Theme-defined here — not missing, local value wins.
			array(
				'type'  => 'color',
				'slug'  => 'imported-accent',
				'name'  => 'Imported Accent',
				'value' => '#aa5500',
			),
		);

		$missing = Pattern_Builder_Cloud_Tokens::missing( $tokens );

		$this->assertCount( 1, $missing );
		$this->assertSame( 'imported-accent', $missing[0]['slug'] );
	}

	public function test_apply_writes_to_user_global_styles_idempotently() {
		$token = array(
			'type'  => 'color',
			'slug'  => 'imported-accent',
			'name'  => 'Imported Accent',
			'value' => '#aa5500',
		);

		$written = Pattern_Builder_Cloud_Tokens::apply( array( $token ), 'user' );
		$this->assertSame( array( 'color' => array( 'imported-accent' ) ), $written );

		// Now resolvable — and a second apply writes nothing.
		$this->assertSame( array(), Pattern_Builder_Cloud_Tokens::missing( array( $token ) ) );
		$this->assertSame( array(), Pattern_Builder_Cloud_Tokens::apply( array( $token ), 'user' ) );

		// The value round-trips through the global styles pipeline.
		$collected = Pattern_Builder_Cloud_Tokens::collect( '<!-- wp:paragraph {"textColor":"imported-accent"} --><p>x</p><!-- /wp:paragraph -->' );
		$this->assertSame( '#aa5500', $collected[0]['value'] );
	}

	public function test_apply_writes_to_theme_json() {
		$path = get_stylesheet_directory() . '/theme.json';
		file_put_contents( $path, wp_json_encode( array( 'version' => 3 ) ) );
		wp_clean_theme_json_cache();

		$written = Pattern_Builder_Cloud_Tokens::apply(
			array(
				array(
					'type'  => 'spacing',
					'slug'  => 'imported-gap',
					'name'  => 'Imported Gap',
					'value' => '2.5rem',
				),
			),
			'theme'
		);

		$this->assertSame( array( 'spacing' => array( 'imported-gap' ) ), $written );

		$config = json_decode( file_get_contents( $path ), true );
		$this->assertSame( 'imported-gap', $config['settings']['spacing']['spacingSizes'][0]['slug'] );
		$this->assertSame( '2.5rem', $config['settings']['spacing']['spacingSizes'][0]['size'] );
	}

	public function test_apply_theme_requires_theme_json() {
		$result = Pattern_Builder_Cloud_Tokens::apply(
			array(
				array(
					'type'  => 'color',
					'slug'  => 'imported-accent',
					'name'  => 'Imported Accent',
					'value' => '#aa5500',
				),
			),
			'theme'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_no_theme_json', $result->get_error_code() );
	}

	public function test_apply_rejects_hostile_wire_values() {
		$result = Pattern_Builder_Cloud_Tokens::apply(
			array(
				array(
					'type'  => 'color',
					'slug'  => 'evil',
					'name'  => 'Evil',
					'value' => 'red; background:url(javascript:alert(1))',
				),
			),
			'user'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_bad_token', $result->get_error_code() );
	}

	/**
	 * The download endpoint asks no second question: whatever destination the
	 * user picked for the pattern is where its missing tokens go, and only
	 * the missing ones are written.
	 */
	public function test_download_adds_missing_tokens_where_the_pattern_goes() {
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_test-token' );

		$this->mock_download(
			array(
				array(
					'type'  => 'color',
					'slug'  => 'brand',
					'name'  => 'Brand',
					'value' => '#000000',
				),
				array(
					'type'  => 'color',
					'slug'  => 'imported-accent',
					'name'  => 'Imported Accent',
					'value' => '#aa5500',
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/cloud/download' );
		$request->set_param( 'source', 'library' );
		$request->set_param( 'cloudId', 12 );
		$request->set_param( 'destination', 'user' );
		$request->set_param( 'addTokens', true );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		// Only the token the site lacks; "brand" keeps the theme's value.
		$this->assertSame(
			array( 'color' => array( 'imported-accent' ) ),
			$response->get_data()['tokensWritten']
		);

		$user_styles = json_decode(
			get_post( WP_Theme_JSON_Resolver::get_user_global_styles_post_id() )->post_content,
			true
		);
		$this->assertSame(
			array( 'imported-accent' ),
			wp_list_pluck( $user_styles['settings']['color']['palette'], 'slug' )
		);
		$this->assertFileDoesNotExist( get_stylesheet_directory() . '/theme.json' );
	}

	public function test_download_writes_no_tokens_unless_asked() {
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_test-token' );

		$this->mock_download(
			array(
				array(
					'type'  => 'color',
					'slug'  => 'imported-accent',
					'name'  => 'Imported Accent',
					'value' => '#aa5500',
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/cloud/download' );
		$request->set_param( 'source', 'library' );
		$request->set_param( 'cloudId', 12 );
		$request->set_param( 'destination', 'user' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['tokensWritten'] );
		$this->assertSame(
			array(),
			Pattern_Builder_Cloud_Tokens::missing(
				array(
					array(
						'type'  => 'color',
						'slug'  => 'brand',
						'value' => '#4f46e5',
					),
				)
			)
		);
	}

	/**
	 * Answer the service's download call with a package carrying $tokens.
	 *
	 * @param array $tokens PBP token list.
	 */
	private function mock_download( $tokens ) {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $tokens ) {
				if ( false === strpos( $url, rawurlencode( '/download' ) ) ) {
					return $pre;
				}

				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'pbp'     => '1',
							'title'   => 'Tokened Pattern',
							'slug'    => 'tokened-pattern',
							'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
							'tokens'  => $tokens,
						)
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	public function test_porter_export_bundles_tokens() {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Tokened Pattern',
				'post_name'    => 'tokened-pattern',
				'post_content' => $this->sample_markup(),
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );

		$porter   = new TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'user', $post_id );

		$this->assertIsArray( $exported );
		$slugs = wp_list_pluck( $exported['pbp']['tokens'], 'slug' );
		$this->assertContains( 'brand', $slugs );
		$this->assertContains( 'jumbo', $slugs );
		$this->assertContains( 'mega', $slugs );
	}

	/**
	 * A composed pattern's tokens include the ones its references need.
	 *
	 * A page pattern is mostly `core/pattern` references, so almost none of the
	 * presets it depends on are in its own markup. Collecting only the top level
	 * under-reports it, and renders it against another theme carrying a fraction
	 * of what it needs — which is how a blank preview came back missing two of
	 * the three colours the page actually uses.
	 */
	public function test_collect_tree_follows_pattern_references() {
		register_block_pattern(
			'probe/leaf',
			array(
				'title'   => 'Leaf',
				'content' => '<!-- wp:paragraph {"textColor":"pale-pink"} --><p class="has-pale-pink-color has-text-color">Leaf.</p><!-- /wp:paragraph -->',
			)
		);

		$page = '<!-- wp:group {"backgroundColor":"vivid-red"} --><div class="wp-block-group has-vivid-red-background-color has-background">'
			. '<!-- wp:pattern {"slug":"probe/leaf"} /-->'
			. '</div><!-- /wp:group -->';

		$shallow = wp_list_pluck( Pattern_Builder_Cloud_Tokens::referenced( $page ), 0 );
		$this->assertNotContains( 'pale-pink', (array) $shallow, 'The top level alone cannot see it.' );

		$slugs = wp_list_pluck( Pattern_Builder_Cloud_Tokens::collect_tree( $page ), 'slug' );

		$this->assertContains( 'vivid-red', $slugs );
		$this->assertContains( 'pale-pink', $slugs, 'The referenced pattern\'s tokens are part of what this page needs.' );

		unregister_block_pattern( 'probe/leaf' );
	}

	/**
	 * A reference loop is walked once, not for ever.
	 */
	public function test_collect_tree_survives_a_reference_loop() {
		register_block_pattern( 'probe/a', array( 'title' => 'A', 'content' => '<!-- wp:pattern {"slug":"probe/b"} /-->' ) );
		register_block_pattern( 'probe/b', array( 'title' => 'B', 'content' => '<!-- wp:pattern {"slug":"probe/a"} /-->' ) );

		$this->assertIsArray( Pattern_Builder_Cloud_Tokens::collect_tree( '<!-- wp:pattern {"slug":"probe/a"} /-->' ) );

		unregister_block_pattern( 'probe/a' );
		unregister_block_pattern( 'probe/b' );
	}

	/**
	 * A reference to a pattern nothing registered is skipped, not fatal.
	 */
	public function test_collect_tree_tolerates_an_unresolvable_reference() {
		$tokens = Pattern_Builder_Cloud_Tokens::collect_tree( '<!-- wp:pattern {"slug":"probe/never-registered"} /-->' );

		$this->assertSame( array(), $tokens );
	}
}
