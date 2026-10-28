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
		$this->customize_template( 'wp_template', 'index', '<!-- wp:paragraph --><p>From the Site Editor</p><!-- /wp:paragraph -->' );

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
		$post_id = $this->customize_template( 'wp_template', 'index', '<!-- wp:paragraph --><p>From the Site Editor</p><!-- /wp:paragraph -->' );
		$markup  = '<!-- wp:paragraph --><p>Edited from the customization</p><!-- /wp:paragraph -->';

		$saved = $this->store->save( 'template', 'index', $markup );

		$this->assertNotWPError( $saved );
		$this->assertTrue( $saved['folded'] );
		$this->assertNull( get_post( $post_id ) );
		$this->assertSame( 'theme', $saved['template']['source'] );
		$this->assertSame( $markup . "\n", file_get_contents( $this->theme_dir . '/templates/index.html' ) );
	}

	/**
	 * A part made in the Site Editor keeps its title and area on the row; folding it removes
	 * the row, so they have to land in theme.json or the part comes back uncategorized.
	 */
	public function test_folding_keeps_what_the_customization_said_about_itself() {
		$post_id = $this->customize_template( 'wp_template_part', 'promo', '<!-- wp:site-title /-->' );
		wp_set_post_terms( $post_id, WP_TEMPLATE_PART_AREA_FOOTER, 'wp_template_part_area' );

		$before = $this->store->find( 'template-part', 'promo' );
		$saved  = $this->store->save( 'template-part', 'promo', '<!-- wp:site-title /-->' );

		$this->assertSame( WP_TEMPLATE_PART_AREA_FOOTER, $before['area'] );
		$this->assertTrue( $saved['folded'] );
		$this->assertSame( WP_TEMPLATE_PART_AREA_FOOTER, $saved['template']['area'] );
		$this->assertSame( 'Customized promo', $saved['template']['title'] );
	}

	/**
	 * What the caller sends wins over what the row said.
	 */
	public function test_folding_takes_the_caller_s_metadata_first() {
		$post_id = $this->customize_template( 'wp_template_part', 'promo', '<!-- wp:site-title /-->' );
		wp_set_post_terms( $post_id, WP_TEMPLATE_PART_AREA_FOOTER, 'wp_template_part_area' );

		$saved = $this->store->save( 'template-part', 'promo', '<!-- wp:site-title /-->', array( 'area' => 'header' ) );

		$this->assertSame( WP_TEMPLATE_PART_AREA_HEADER, $saved['template']['area'] );
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
	 * Core offers a template whose entry names no post types to pages alone, and one whose
	 * entry names an empty list to nothing; clearing the list means the first.
	 */
	public function test_an_empty_post_types_list_goes_back_to_pages() {
		$this->store->save( 'template', 'landing', '<!-- wp:post-content /-->', array( 'postTypes' => array( 'post' ) ) );
		$this->store->save( 'template', 'landing', '<!-- wp:post-content /-->', array( 'postTypes' => array() ) );

		$config = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true );

		$this->assertArrayNotHasKey( 'postTypes', $config['customTemplates'][0] );
		$this->assertSame( array( 'page' ), $this->store->find( 'template', 'landing' )['postTypes'] );
	}

	/**
	 * With no entry at all, core offers a custom template for every post type, so there is no
	 * list to report.
	 */
	public function test_a_custom_template_with_no_entry_names_no_post_types() {
		$this->store->save( 'template', 'landing', '<!-- wp:post-content /-->' );

		$this->assertArrayNotHasKey( 'postTypes', $this->store->find( 'template', 'landing' ) );
	}

	/**
	 * Core lays a child theme's list over its parent's by position, so an entry appended to a
	 * child's shorter list would overwrite one of the parent's.
	 */
	public function test_a_child_theme_s_entry_does_not_overwrite_the_parent_s() {
		$child = $this->use_a_template_theme( true );
		file_put_contents( $child . '/theme.json', wp_json_encode( array( 'version' => 3 ) ) );

		$this->store->save(
			'template-part',
			'header-dark',
			'<!-- wp:site-title /-->',
			array(
				'title' => 'Dark header',
				'area'  => 'header',
			)
		);

		$parent = new WP_Theme_JSON( json_decode( file_get_contents( get_template_directory() . '/theme.json' ), true ) );
		$parent->merge( new WP_Theme_JSON( json_decode( file_get_contents( $child . '/theme.json' ), true ) ) );
		$parts = $parent->get_template_parts();

		$this->assertSame( 'header', $parts['header']['area'] );
		$this->assertSame( 'Header', $parts['header']['title'] );
		$this->assertSame( 'header', $parts['header-dark']['area'] );
		$this->assertSame( 'header', $this->store->find( 'template-part', 'header' )['area'] );
	}

	/**
	 * Removing the child's copy leaves the parent's rendering, and whatever the child said
	 * about it still applies.
	 */
	public function test_deleting_a_child_s_copy_keeps_its_metadata_for_the_parent_s() {
		$child = $this->use_a_template_theme( true );
		$this->store->save( 'template-part', 'header', '<!-- wp:site-title /-->', array( 'title' => 'Site header' ) );

		$this->store->delete( 'template-part', 'header' );

		$config = json_decode( file_get_contents( $child . '/theme.json' ), true );
		$this->assertSame( 'Site header', $config['templateParts'][0]['title'] );
		$this->assertSame( 'Site header', $this->store->find( 'template-part', 'header' )['title'] );
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
	 * Core reads a part entry's area whenever the entry exists and reports an empty one as
	 * unsupported on every listing, so a part recorded for its title alone gets an area too.
	 */
	public function test_a_part_recorded_for_its_title_alone_is_uncategorized_rather_than_arealess() {
		$saved = $this->store->save( 'template-part', 'promo', '<!-- wp:site-title /-->', array( 'title' => 'Promo' ) );

		$this->assertSame( 'Promo', $saved['template']['title'] );
		$this->assertSame( WP_TEMPLATE_PART_AREA_UNCATEGORIZED, $saved['template']['area'] );

		$config = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true );
		$entry  = array_column( $config['templateParts'], null, 'name' )['promo'];
		$this->assertSame( WP_TEMPLATE_PART_AREA_UNCATEGORIZED, $entry['area'] );
		$this->assertSame( WP_TEMPLATE_PART_AREA_UNCATEGORIZED, _add_block_template_part_area_info( array( 'slug' => 'promo' ) )['area'] );
	}

	/**
	 * A part's area has nowhere to live but theme.json, so a theme without one is given one
	 * rather than losing the area with the row.
	 */
	public function test_folding_in_a_theme_without_theme_json_writes_one() {
		unlink( $this->theme_dir . '/theme.json' );
		wp_clean_theme_json_cache();
		$post_id = $this->customize_template( 'wp_template_part', 'promo', '<!-- wp:site-title /-->' );
		wp_set_post_terms( $post_id, WP_TEMPLATE_PART_AREA_FOOTER, 'wp_template_part_area' );

		$saved = $this->store->save( 'template-part', 'promo', '<!-- wp:site-title /-->' );

		$this->assertSame( array(), $saved['warnings'] );
		$this->assertTrue( $saved['folded'] );
		$this->assertSame( WP_TEMPLATE_PART_AREA_FOOTER, $saved['template']['area'] );
		$this->assertFileExists( $this->theme_dir . '/theme.json' );
		$this->assertSame( 3, json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true )['version'] );
	}

	/**
	 * The template hierarchy reaches page-about and single-product on its own and WordPress
	 * names them itself, so neither a folded row's title nor a caller's makes one a custom
	 * template.
	 */
	public function test_a_hierarchy_variant_is_not_recorded_as_a_custom_template() {
		$this->customize_template( 'wp_template', 'page-about', '<!-- wp:post-content /-->' );

		$folded  = $this->store->save( 'template', 'page-about', '<!-- wp:post-content /-->' );
		$titled  = $this->store->save( 'template', 'single-product', '<!-- wp:post-content /-->', array( 'title' => 'Product' ) );
		$config  = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true );

		$this->assertTrue( $folded['folded'] );
		$this->assertSame( array(), $folded['warnings'] );
		$this->assertSame( 'template_default_metadata', $titled['warnings'][0]['code'] );
		$this->assertArrayNotHasKey( 'customTemplates', $config );
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
		$post_id = $this->customize_template( 'wp_template', 'landing', '<!-- wp:paragraph --><p>Customized</p><!-- /wp:paragraph -->' );

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
		$this->register_template(
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

		$this->assertArrayHasKey( 'plugin-landing', $listed );
		$this->assertSame( 'plugin', $found['source'] );
		$this->assertSame( 'Plugin landing', $found['title'] );
		$this->assertSame( '<!-- wp:post-content /-->', $found['content'] );
		$this->assertSame( 'pb_template_is_read_only', $deleted->get_error_code() );
	}

	public function test_listing_names_each_slug_once_and_carries_no_content() {
		$this->customize_template( 'wp_template', 'index', '<!-- wp:post-content /-->' );
		$this->customize_template( 'wp_template', 'from-site-editor', '<!-- wp:post-content /-->' );

		$listed = $this->store->all( 'template' );

		$this->assertSame( array( 'from-site-editor', 'index' ), array_keys( $listed ) );
		$this->assertSame( 'custom', $listed['index']['source'] );
		$this->assertFalse( $listed['from-site-editor']['hasThemeFile'] );
		$this->assertArrayNotHasKey( 'content', $listed['index'] );
	}
}
