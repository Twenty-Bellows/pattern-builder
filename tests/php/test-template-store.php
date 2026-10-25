<?php
/**
 * Templates and template parts, read as WordPress resolves them and written as theme files.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Template_Store;

class Test_Template_Store extends Pattern_Test_Case {
	/**
	 * @var Pattern_Builder_Template_Store
	 */
	private $store;

	/**
	 * The active theme's directory.
	 *
	 * @var string
	 */
	private $theme_dir = '';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->theme_dir = $this->use_a_template_theme();
		$this->store     = new Pattern_Builder_Template_Store();
	}

	/**
	 * Save a Site Editor customization of a slug, the way the Site Editor stores one.
	 *
	 * @param string $post_type wp_template or wp_template_part.
	 * @param string $slug Template slug.
	 * @param string $content Block markup.
	 * @return int Post ID.
	 */
	private function customize( string $post_type, string $slug, string $content ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_name'    => $slug,
				'post_title'   => 'Customized ' . $slug,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		return $post_id;
	}

	/**
	 * What an update replaces is the bytes on disk, not core's reading of them, which adds
	 * hooked blocks.
	 */
	public function test_a_theme_file_is_read_as_it_is_on_disk() {
		$index = $this->store->find( 'template', 'index' );

		$this->assertSame( 'theme', $index['source'] );
		$this->assertSame( 'pb-template-test', $index['theme'] );
		$this->assertSame( 'templates/index.html', $index['path'] );
		$this->assertTrue( $index['hasThemeFile'] );
		$this->assertFalse( $index['customized'] );
		$this->assertSame( file_get_contents( $this->theme_dir . '/templates/index.html' ), $index['content'] );
	}

	public function test_a_default_template_takes_its_title_from_wordpress() {
		$index = $this->store->find( 'template', 'index' );

		$this->assertSame( get_default_block_template_types()['index']['title'], $index['title'] );
		$this->assertNotSame( '', $index['description'] );
	}

	public function test_a_part_takes_its_title_and_area_from_theme_json() {
		$header = $this->store->find( 'template-part', 'header' );

		$this->assertSame( 'Header', $header['title'] );
		$this->assertSame( WP_TEMPLATE_PART_AREA_HEADER, $header['area'] );
	}

	public function test_nothing_answers_to_an_unknown_slug() {
		$this->assertNull( $this->store->find( 'template', 'nowhere' ) );
		$this->assertNull( $this->store->find( 'not-a-type', 'index' ) );
	}

	/**
	 * A customization is what renders, so it is what a read returns.
	 */
	public function test_a_customization_renders_in_place_of_the_file() {
		$this->customize( 'wp_template', 'index', '<!-- wp:paragraph --><p>From the Site Editor</p><!-- /wp:paragraph -->' );

		$index = $this->store->find( 'template', 'index' );

		$this->assertSame( 'custom', $index['source'] );
		$this->assertTrue( $index['customized'] );
		$this->assertTrue( $index['hasThemeFile'] );
		$this->assertStringContainsString( 'From the Site Editor', $index['content'] );
	}

	/**
	 * The caller edited the customization it read, so the file takes it over and the row goes,
	 * as the Site Editor's reset removes it.
	 */
	public function test_saving_folds_a_customization_into_the_file() {
		$post_id = $this->customize( 'wp_template', 'index', '<!-- wp:paragraph --><p>From the Site Editor</p><!-- /wp:paragraph -->' );
		$markup  = '<!-- wp:paragraph --><p>Edited from the customization</p><!-- /wp:paragraph -->';

		$saved = $this->store->save( 'template', 'index', $markup );

		$this->assertNotWPError( $saved );
		$this->assertTrue( $saved['folded'] );
		$this->assertNull( get_post( $post_id ) );
		$this->assertSame( 'theme', $saved['template']['source'] );
		$this->assertSame( $markup . "\n", file_get_contents( $this->theme_dir . '/templates/index.html' ) );
	}

	/**
	 * Core keeps a per-process list of a theme's template files; a template written during a
	 * request still has to show up in that request.
	 */
	public function test_a_new_template_is_listed_in_the_request_that_wrote_it() {
		$this->store->all( 'template' );

		$this->store->save( 'template', 'landing', '<!-- wp:post-content /-->', array( 'title' => 'Landing' ) );

		$this->assertArrayHasKey( 'landing', $this->store->all( 'template' ) );
	}

	public function test_a_custom_template_records_its_title_and_post_types_in_theme_json() {
		$saved = $this->store->save(
			'template',
			'landing',
			'<!-- wp:post-content /-->',
			array(
				'title'     => 'Landing',
				'postTypes' => array( 'page' ),
			)
		);

		$this->assertSame( array(), $saved['warnings'] );
		$this->assertSame( 'Landing', $saved['template']['title'] );
		$this->assertSame( array( 'page' ), $saved['template']['postTypes'] );

		$config = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true );
		$this->assertSame(
			array(
				array(
					'name'      => 'landing',
					'title'     => 'Landing',
					'postTypes' => array( 'page' ),
				),
			),
			$config['customTemplates']
		);
	}

	/**
	 * Core reads an empty postTypes as "offered for no post type", which is never what
	 * clearing the list means.
	 */
	public function test_an_empty_post_types_list_lifts_the_restriction() {
		$this->store->save( 'template', 'landing', '<!-- wp:post-content /-->', array( 'postTypes' => array( 'page' ) ) );
		$this->store->save( 'template', 'landing', '<!-- wp:post-content /-->', array( 'postTypes' => array() ) );

		$config = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true );

		$this->assertArrayNotHasKey( 'postTypes', $config['customTemplates'][0] );
		$this->assertArrayNotHasKey( 'postTypes', $this->store->find( 'template', 'landing' ) );
	}

	public function test_a_part_records_its_area_in_theme_json() {
		$saved = $this->store->save(
			'template-part',
			'footer',
			'<!-- wp:site-title /-->',
			array(
				'title' => 'Footer',
				'area'  => 'footer',
			)
		);

		$this->assertSame( WP_TEMPLATE_PART_AREA_FOOTER, $saved['template']['area'] );
		$this->assertSame( 'Footer', $saved['template']['title'] );
		$this->assertFileExists( $this->theme_dir . '/parts/footer.html' );
	}

	/**
	 * theme.json is re-encoded when written, so it is only written when something changed.
	 */
	public function test_unchanged_metadata_leaves_theme_json_alone() {
		$before = file_get_contents( $this->theme_dir . '/theme.json' );

		$this->store->save(
			'template-part',
			'header',
			'<!-- wp:site-title /-->',
			array(
				'title' => 'Header',
				'area'  => 'header',
			)
		);

		$this->assertSame( $before, file_get_contents( $this->theme_dir . '/theme.json' ) );
	}

	public function test_a_default_template_does_not_record_a_title() {
		$saved = $this->store->save( 'template', 'page', '<!-- wp:post-content /-->', array( 'title' => 'My Pages' ) );

		$this->assertSame( 'template_default_metadata', $saved['warnings'][0]['code'] );
		$this->assertSame( get_default_block_template_types()['page']['title'], $saved['template']['title'] );
	}

	/**
	 * A template is read as text, so PHP in one would be sent to the browser as it stands.
	 */
	public function test_php_is_refused() {
		foreach ( array( '<?php echo 1; ?>', '<? echo 1; ?>', '<?= 1 ?>' ) as $php ) {
			$saved = $this->store->save( 'template', 'landing', '<!-- wp:paragraph --><p>' . $php . '</p><!-- /wp:paragraph -->' );

			$this->assertWPError( $saved, $php );
			$this->assertSame( 'pb_template_has_php', $saved->get_error_code() );
		}
		$this->assertFileDoesNotExist( $this->theme_dir . '/templates/landing.html' );
	}

	/**
	 * Core's own export drops the theme attribute a part reference carries for the active
	 * theme; one naming another theme is left for the checks to refuse.
	 */
	public function test_a_reference_loses_only_its_own_theme_attribute() {
		$markup = '<!-- wp:template-part {"slug":"header","theme":"pb-template-test","tagName":"header"} /-->'
			. '<!-- wp:template-part {"slug":"footer","theme":"another-theme"} /-->';

		$this->assertSame(
			'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
				. '<!-- wp:template-part {"slug":"footer","theme":"another-theme"} /-->',
			Pattern_Builder_Template_Store::without_own_theme_attribute( $markup )
		);
	}

	public function test_a_slug_that_is_not_a_flat_filename_is_refused() {
		foreach ( array( '../escape', 'Upper', 'nested/slug', '', '-leading' ) as $slug ) {
			$saved = $this->store->save( 'template', $slug, '<!-- wp:post-content /-->' );

			$this->assertWPError( $saved, $slug );
			$this->assertSame( 'pb_template_bad_slug', $saved->get_error_code(), $slug );
		}
	}

	public function test_a_write_needs_edit_theme_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$saved = $this->store->save( 'template', 'landing', '<!-- wp:post-content /-->' );

		$this->assertWPError( $saved );
		$this->assertSame( 403, $saved->get_error_data()['status'] );
	}

	public function test_deleting_removes_the_file_the_customization_and_the_metadata() {
		$this->store->save( 'template', 'landing', '<!-- wp:post-content /-->', array( 'title' => 'Landing' ) );
		$post_id = $this->customize( 'wp_template', 'landing', '<!-- wp:paragraph --><p>Customized</p><!-- /wp:paragraph -->' );

		$deleted = $this->store->delete( 'template', 'landing' );

		$this->assertNotWPError( $deleted );
		$this->assertSame( 'templates/landing.html', $deleted['removed']['file'] );
		$this->assertTrue( $deleted['removed']['customization'] );
		$this->assertNull( $deleted['now'] );
		$this->assertNull( get_post( $post_id ) );
		$this->assertFileDoesNotExist( $this->theme_dir . '/templates/landing.html' );
		$this->assertArrayNotHasKey( 'customTemplates', json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true ) );
	}

	/**
	 * A theme with no templates/index.html is no longer a block theme.
	 */
	public function test_the_last_index_cannot_be_deleted() {
		$deleted = $this->store->delete( 'template', 'index' );

		$this->assertWPError( $deleted );
		$this->assertSame( 'pb_template_index_required', $deleted->get_error_code() );
		$this->assertFileExists( $this->theme_dir . '/templates/index.html' );
	}

	public function test_deleting_what_is_not_there_says_so() {
		$deleted = $this->store->delete( 'template', 'nowhere' );

		$this->assertWPError( $deleted );
		$this->assertSame( 404, $deleted->get_error_data()['status'] );
	}

	/**
	 * Writing to a parent's template from a child gives the child its own copy, and deleting
	 * that copy brings the parent's back.
	 */
	public function test_a_child_theme_overrides_its_parent_and_reverts_to_it() {
		$child = $this->use_a_template_theme( true );

		$this->assertSame( 'pb-template-parent', $this->store->find( 'template', 'single' )['theme'] );

		$saved = $this->store->save( 'template', 'single', '<!-- wp:post-title /-->' );
		$this->assertSame( 'pb-template-test', $saved['template']['theme'] );
		$this->assertFileExists( $child . '/templates/single.html' );

		$deleted = $this->store->delete( 'template', 'single' );
		$this->assertSame( 'pb-template-parent', $deleted['now']['theme'] );
	}

	public function test_a_parent_template_cannot_be_deleted_from_the_child() {
		$this->use_a_template_theme( true );

		$deleted = $this->store->delete( 'template', 'single' );

		$this->assertWPError( $deleted );
		$this->assertSame( 'pb_template_in_parent', $deleted->get_error_code() );
	}

	/**
	 * With a parent carrying its own index, the child's is only an override.
	 */
	public function test_a_child_index_can_be_deleted_when_the_parent_has_one() {
		$this->use_a_template_theme( true );

		$deleted = $this->store->delete( 'template', 'index' );

		$this->assertNotWPError( $deleted );
		$this->assertSame( 'pb-template-parent', $deleted['now']['theme'] );
	}

	/**
	 * A template a plugin registered is listed and readable, and the theme has nothing of its
	 * own to remove.
	 */
	public function test_a_plugin_template_is_read_only() {
		register_block_template(
			'pb-test//plugin-landing',
			array(
				'title'       => 'Plugin landing',
				'description' => 'A landing page a plugin provides.',
				'content'     => '<!-- wp:post-content /-->',
			)
		);

		$listed  = $this->store->all( 'template' );
		$found   = $this->store->find( 'template', 'plugin-landing' );
		$deleted = $this->store->delete( 'template', 'plugin-landing' );
		unregister_block_template( 'pb-test//plugin-landing' );

		$this->assertArrayHasKey( 'plugin-landing', $listed );
		$this->assertSame( 'plugin', $found['source'] );
		$this->assertSame( 'Plugin landing', $found['title'] );
		$this->assertSame( '<!-- wp:post-content /-->', $found['content'] );
		$this->assertSame( 'pb_template_is_read_only', $deleted->get_error_code() );
	}

	public function test_listing_names_each_slug_once_and_carries_no_content() {
		$this->customize( 'wp_template', 'index', '<!-- wp:post-content /-->' );
		$this->customize( 'wp_template', 'from-site-editor', '<!-- wp:post-content /-->' );

		$listed = $this->store->all( 'template' );

		$this->assertSame( array( 'from-site-editor', 'index' ), array_keys( $listed ) );
		$this->assertSame( 'custom', $listed['index']['source'] );
		$this->assertFalse( $listed['from-site-editor']['hasThemeFile'] );
		$this->assertArrayNotHasKey( 'content', $listed['index'] );
	}
}
