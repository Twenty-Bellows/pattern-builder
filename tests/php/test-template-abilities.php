<?php
/**
 * The abilities an agent reads, writes and removes the theme's templates and template parts
 * through.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Template_Abilities;

class Test_Template_Abilities extends Pattern_Test_Case {
	/**
	 * The active theme's directory.
	 *
	 * @var string
	 */
	private $theme_dir = '';

	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'This WordPress has no Abilities API.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->theme_dir = $this->use_a_template_theme();
	}

	/**
	 * Run an ability the way a caller does, permission check and schemas included.
	 *
	 * @param string $name Ability name, without the namespace.
	 * @param array  $input Ability input.
	 * @return mixed
	 */
	private function call( string $name, array $input = array() ) {
		return wp_get_ability( 'pattern-builder/' . $name )->execute( $input );
	}

	/**
	 * Annotations are what core reads to decide which HTTP method a call must arrive on.
	 */
	public function test_each_ability_registers_for_the_method_it_needs() {
		foreach ( Pattern_Builder_Template_Abilities::names() as $name ) {
			$this->assertTrue( wp_has_ability( $name ), $name . ' did not register.' );
			$meta = wp_get_ability( $name )->get_meta();
			$this->assertTrue( $meta['show_in_rest'], $name . ' must be reachable over REST.' );
			$this->assertTrue( $meta['mcp']['public'], $name . ' must be served over MCP.' );
		}

		foreach ( array( 'list-templates', 'get-template' ) as $read ) {
			$this->assertTrue( wp_get_ability( 'pattern-builder/' . $read )->get_meta()['annotations']['readonly'], $read );
		}

		$create = wp_get_ability( 'pattern-builder/create-template' )->get_meta()['annotations'];
		$update = wp_get_ability( 'pattern-builder/update-template' )->get_meta()['annotations'];
		$delete = wp_get_ability( 'pattern-builder/delete-template' )->get_meta()['annotations'];

		$this->assertFalse( $create['readonly'] || $create['destructive'] || $create['idempotent'] );
		$this->assertTrue( $update['idempotent'] );
		$this->assertFalse( $update['destructive'] );
		$this->assertTrue( $delete['destructive'] && $delete['idempotent'], 'delete-template must arrive over DELETE.' );
	}

	/**
	 * Core keeps its templates endpoint to whoever may edit the theme, reads included.
	 */
	public function test_reading_needs_edit_theme_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->call( 'list-templates' ) );
	}

	public function test_list_reports_templates_parts_and_the_areas_a_part_fills() {
		$listed = $this->call( 'list-templates' );

		$this->assertSame( array( 'template', 'template-part' ), array_column( $listed['templates'], 'type' ) );
		$this->assertSame( array( 'index', 'header' ), array_column( $listed['templates'], 'slug' ) );
		$this->assertContains( 'footer', array_column( $listed['areas'], 'area' ) );

		$templates_only = $this->call( 'list-templates', array( 'type' => 'template' ) );
		$this->assertSame( array( 'index' ), array_column( $templates_only['templates'], 'slug' ) );
		$this->assertArrayNotHasKey( 'areas', $templates_only );
	}

	public function test_get_returns_the_markup_and_a_hash() {
		$got = $this->call(
			'get-template',
			array(
				'type' => 'template-part',
				'slug' => 'header',
			)
		);

		$this->assertSame( "<!-- wp:site-title /-->\n", $got['template']['content'] );
		$this->assertSame( 64, strlen( $got['template']['hash'] ) );
	}

	public function test_get_of_an_unknown_slug_is_not_found() {
		$got = $this->call(
			'get-template',
			array(
				'type' => 'template',
				'slug' => 'nowhere',
			)
		);

		$this->assertWPError( $got );
		$this->assertSame( 'pb_template_not_found', $got->get_error_code() );
	}

	public function test_create_writes_the_file_and_warns_about_what_will_not_travel() {
		$created = $this->call(
			'create-template',
			array(
				'type'      => 'template',
				'slug'      => 'landing',
				'content'   => '<!-- wp:template-part {"slug":"header"} /--><!-- wp:navigation {"ref":12} /--><!-- wp:post-content /-->',
				'title'     => 'Landing',
				'postTypes' => array( 'page' ),
			)
		);

		$this->assertNotWPError( $created );
		$this->assertFileExists( $this->theme_dir . '/templates/landing.html' );
		$this->assertSame( 'Landing', $created['template']['title'] );
		$this->assertArrayNotHasKey( 'content', $created['template'] );
		$this->assertArrayNotHasKey( 'replaces', $created );
		$this->assertSame( array( 'template_database_id' ), array_column( $created['warnings'], 'code' ) );
	}

	public function test_create_refuses_a_slug_the_theme_already_has() {
		$created = $this->call(
			'create-template',
			array(
				'type'    => 'template-part',
				'slug'    => 'header',
				'content' => '<!-- wp:site-title /-->',
			)
		);

		$this->assertWPError( $created );
		$this->assertSame( 'pb_template_exists', $created->get_error_code() );
	}

	/**
	 * A Site Editor customization with no file is still the theme's, and update-template is
	 * what takes it over.
	 */
	public function test_create_refuses_a_slug_the_site_editor_made() {
		$this->customize_template( 'wp_template', 'from-site-editor', '<!-- wp:post-content /-->' );

		$created = $this->call(
			'create-template',
			array(
				'type'    => 'template',
				'slug'    => 'from-site-editor',
				'content' => '<!-- wp:post-content /-->',
			)
		);

		$this->assertSame( 'pb_template_exists', $created->get_error_code() );
	}

	/**
	 * The store refuses PHP on the bytes it writes, whichever caller sent them.
	 */
	public function test_create_refuses_php() {
		$created = $this->call(
			'create-template',
			array(
				'type'    => 'template',
				'slug'    => 'landing',
				'content' => '<!-- wp:paragraph --><p><? echo 1; ?></p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( 'pb_template_has_php', $created->get_error_code() );
		$this->assertFileDoesNotExist( $this->theme_dir . '/templates/landing.html' );
	}

	public function test_create_refuses_a_part_that_does_not_exist() {
		$created = $this->call(
			'create-template',
			array(
				'type'    => 'template',
				'slug'    => 'landing',
				'content' => '<!-- wp:template-part {"slug":"footer"} /-->',
			)
		);

		$this->assertWPError( $created );
		$this->assertSame( 'pb_markup_refused', $created->get_error_code() );
		$this->assertFileDoesNotExist( $this->theme_dir . '/templates/landing.html' );
	}

	public function test_create_over_a_parent_template_says_what_it_replaces() {
		$child = $this->use_a_template_theme( true );

		$created = $this->call(
			'create-template',
			array(
				'type'    => 'template',
				'slug'    => 'single',
				'content' => '<!-- wp:post-title /--><!-- wp:post-content /-->',
			)
		);

		$this->assertNotWPError( $created );
		$this->assertSame( 'pb-template-parent', $created['replaces']['theme'] );
		$this->assertSame( 'pb-template-test', $created['template']['theme'] );
		$this->assertFileExists( $child . '/templates/single.html' );
	}

	public function test_update_with_edits_changes_the_fragment_in_place() {
		$got = $this->call(
			'get-template',
			array(
				'type' => 'template',
				'slug' => 'index',
			)
		);

		$updated = $this->call(
			'update-template',
			array(
				'type'         => 'template',
				'slug'         => 'index',
				'expectedHash' => $got['template']['hash'],
				'edits'        => array(
					array(
						'old' => '<!-- wp:post-content /-->',
						'new' => '<!-- wp:post-title /--><!-- wp:post-content /-->',
					),
				),
			)
		);

		$this->assertNotWPError( $updated );
		$this->assertTrue( $updated['changed'] );
		$this->assertFalse( $updated['folded'] );
		$this->assertStringContainsString( '<!-- wp:post-title /--><!-- wp:post-content /-->', file_get_contents( $this->theme_dir . '/templates/index.html' ) );
	}

	public function test_edits_need_a_hash() {
		$updated = $this->call(
			'update-template',
			array(
				'type'  => 'template',
				'slug'  => 'index',
				'edits' => array(
					array(
						'old' => '<!-- wp:post-content /-->',
						'new' => '',
					),
				),
			)
		);

		$this->assertSame( 'pb_edits_need_a_hash', $updated->get_error_code() );
	}

	/**
	 * A Site Editor save after the read changes the hash, and the write is refused rather
	 * than overwriting it.
	 */
	public function test_update_against_a_stale_hash_is_refused() {
		$got = $this->call(
			'get-template',
			array(
				'type' => 'template',
				'slug' => 'index',
			)
		);
		$this->customize_template( 'wp_template', 'index', '<!-- wp:paragraph --><p>Saved meanwhile</p><!-- /wp:paragraph -->' );

		$updated = $this->call(
			'update-template',
			array(
				'type'         => 'template',
				'slug'         => 'index',
				'expectedHash' => $got['template']['hash'],
				'content'      => '<!-- wp:post-content /-->',
			)
		);

		$this->assertSame( 'pb_template_changed', $updated->get_error_code() );
		$this->assertStringContainsString( 'wp:template-part', file_get_contents( $this->theme_dir . '/templates/index.html' ) );
	}

	public function test_update_folds_the_customization_it_read() {
		$post_id = $this->customize_template( 'wp_template', 'index', '<!-- wp:paragraph --><p>From the Site Editor</p><!-- /wp:paragraph -->' );
		$got     = $this->call(
			'get-template',
			array(
				'type' => 'template',
				'slug' => 'index',
			)
		);

		$updated = $this->call(
			'update-template',
			array(
				'type'         => 'template',
				'slug'         => 'index',
				'expectedHash' => $got['template']['hash'],
				'content'      => $got['template']['content'],
			)
		);

		$this->assertTrue( $updated['folded'] );
		$this->assertTrue( $updated['changed'] );
		$this->assertSame( 'theme', $updated['template']['source'] );
		$this->assertNull( get_post( $post_id ) );
		$this->assertStringContainsString( 'From the Site Editor', file_get_contents( $this->theme_dir . '/templates/index.html' ) );
	}

	public function test_an_update_that_changes_nothing_says_so() {
		$got = $this->call(
			'get-template',
			array(
				'type' => 'template',
				'slug' => 'index',
			)
		);

		$updated = $this->call(
			'update-template',
			array(
				'type'    => 'template',
				'slug'    => 'index',
				'content' => $got['template']['content'],
			)
		);

		$this->assertFalse( $updated['changed'] );
	}

	/**
	 * Metadata alone rewrites the markup that is already there.
	 */
	public function test_update_can_change_only_a_part_s_area() {
		$updated = $this->call(
			'update-template',
			array(
				'type' => 'template-part',
				'slug' => 'header',
				'area' => 'footer',
			)
		);

		$this->assertNotWPError( $updated );
		$this->assertSame( 'footer', $updated['template']['area'] );
		$this->assertSame( "<!-- wp:site-title /-->\n", file_get_contents( $this->theme_dir . '/parts/header.html' ) );
	}

	public function test_update_refuses_a_plugin_template() {
		$this->register_template(
			'pb-test//plugin-landing',
			array(
				'title'   => 'Plugin landing',
				'content' => '<!-- wp:post-content /-->',
			)
		);

		$updated = $this->call(
			'update-template',
			array(
				'type'    => 'template',
				'slug'    => 'plugin-landing',
				'content' => '<!-- wp:post-title /-->',
			)
		);

		$this->assertSame( 'pb_template_is_read_only', $updated->get_error_code() );
	}

	/**
	 * A part that templates still place is refused, with the places named.
	 */
	public function test_delete_refuses_a_part_that_is_still_placed() {
		$deleted = $this->call(
			'delete-template',
			array(
				'type' => 'template-part',
				'slug' => 'header',
			)
		);

		$this->assertSame( 'pb_template_part_in_use', $deleted->get_error_code() );
		$this->assertSame(
			array(
				array(
					'kind' => 'template',
					'name' => 'index',
				),
			),
			$deleted->get_error_data()['usedBy']
		);
		$this->assertFileExists( $this->theme_dir . '/parts/header.html' );
	}

	/**
	 * A user pattern or a page can place a part too, and loses it just the same.
	 */
	public function test_delete_refuses_a_part_a_user_pattern_or_page_places() {
		$this->call(
			'create-template',
			array(
				'type'    => 'template-part',
				'slug'    => 'promo',
				'content' => '<!-- wp:site-title /-->',
			)
		);
		$pattern = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Promo band',
				'post_content' => '<!-- wp:template-part {"slug":"promo"} /-->',
			)
		);
		$page    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Offers',
				'post_content' => '<!-- wp:template-part {"slug": "promo"} /-->',
			)
		);

		$deleted = $this->call(
			'delete-template',
			array(
				'type' => 'template-part',
				'slug' => 'promo',
			)
		);

		$this->assertSame( 'pb_template_part_in_use', $deleted->get_error_code() );
		$this->assertEqualSets( array( $pattern, $page ), wp_list_pluck( $deleted->get_error_data()['usedBy'], 'id' ) );
		$this->assertContains( 'Promo band', wp_list_pluck( $deleted->get_error_data()['usedBy'], 'name' ) );
	}

	/**
	 * A trailing newline the store adds is not a change.
	 */
	public function test_metadata_only_update_of_unchanged_markup_is_no_change() {
		file_put_contents( $this->theme_dir . '/parts/plain.html', '<!-- wp:site-title /-->' );

		$updated = $this->call(
			'update-template',
			array(
				'type' => 'template-part',
				'slug' => 'plain',
				'area' => 'uncategorized',
			)
		);

		$this->assertFalse( $updated['changed'] );
		$this->assertArrayNotHasKey( 'warnings', $updated );
	}

	/**
	 * Removing a child's copy of a part leaves the parent's, so every reference still renders.
	 */
	public function test_delete_of_a_child_copy_of_a_placed_part_is_allowed() {
		$this->use_a_template_theme( true );

		$deleted = $this->call(
			'delete-template',
			array(
				'type' => 'template-part',
				'slug' => 'header',
			)
		);

		$this->assertNotWPError( $deleted );
		$this->assertSame( 'pb-template-parent', $deleted['now']['theme'] );
		$this->assertArrayNotHasKey( 'content', $deleted['now'] );
	}

	public function test_delete_against_a_stale_hash_is_refused() {
		$this->call(
			'create-template',
			array(
				'type'    => 'template',
				'slug'    => 'landing',
				'content' => '<!-- wp:post-content /-->',
			)
		);

		$deleted = $this->call(
			'delete-template',
			array(
				'type'         => 'template',
				'slug'         => 'landing',
				'expectedHash' => str_repeat( '0', 64 ),
			)
		);

		$this->assertSame( 'pb_template_changed', $deleted->get_error_code() );
		$this->assertFileExists( $this->theme_dir . '/templates/landing.html' );
	}

	/**
	 * Delete is destructive and idempotent, so core accepts it over DELETE and nothing else.
	 */
	public function test_delete_arrives_over_http_delete() {
		$this->call(
			'create-template',
			array(
				'type'    => 'template',
				'slug'    => 'landing',
				'content' => '<!-- wp:post-content /-->',
			)
		);
		$route = '/wp-abilities/v1/abilities/pattern-builder/delete-template/run';
		$input = array(
			'input' => array(
				'type' => 'template',
				'slug' => 'landing',
			),
		);

		$post = new WP_REST_Request( 'POST', $route );
		$post->set_header( 'Content-Type', 'application/json' );
		$post->set_body( wp_json_encode( $input ) );
		$this->assertSame( 405, rest_do_request( $post )->get_status() );

		$delete = new WP_REST_Request( 'DELETE', $route );
		$delete->set_query_params( $input );
		$response = rest_do_request( $delete );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'templates/landing.html', $response->get_data()['removed']['file'] );
		$this->assertFileDoesNotExist( $this->theme_dir . '/templates/landing.html' );
	}
}
