<?php
/**
 * Moving the references to a pattern: finding every place that names one, and pointing it
 * at the pattern's new address.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_References as References;

class Test_Pattern_References extends Pattern_Test_Case {

	/**
	 * The active theme's directory: one the test built, never the suite's fixture theme.
	 *
	 * @var string
	 */
	private $theme_dir = '';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->theme_dir = $this->use_a_template_theme();
		mkdir( $this->theme_dir . '/patterns' );
	}

	/**
	 * Write a file into the test theme.
	 *
	 * @param string $relative Path under the theme.
	 * @param string $contents File contents.
	 * @return string Absolute path.
	 */
	private function theme_file( $relative, $contents ) {
		$path = $this->theme_dir . '/' . $relative;
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $path;
	}

	public function test_a_slug_reference_becomes_a_ref_and_keeps_what_it_fills() {
		$markup = '<!-- wp:pattern {"slug":"acme/hero","content":{"headline":{"content":"Hello \u003cem\u003eyou\u003c/em\u003e"}},"lock":{"move":true}} /-->';

		$this->assertSame(
			'<!-- wp:block {"ref":42,"content":{"headline":{"content":"Hello \u003cem\u003eyou\u003c/em\u003e"}},"lock":{"move":true}} /-->',
			References::repoint( $markup, 'slug:acme/hero', 'ref:42' )
		);
	}

	public function test_a_ref_becomes_a_slug_reference_and_keeps_what_it_fills() {
		$markup = '<!-- wp:block {"ref":42,"content":{"headline":{"content":"Hello"}}} /-->';

		$this->assertSame(
			'<!-- wp:pattern {"slug":"acme/hero","content":{"headline":{"content":"Hello"}}} /-->',
			References::repoint( $markup, 'ref:42', 'slug:acme/hero' )
		);
	}

	public function test_only_the_named_pattern_moves_and_everything_around_it_is_left_byte_for_byte() {
		$around = "<?php\n/**\n * Title: Page\n * Slug: acme/page\n */\n?>\n<!-- wp:paragraph -->\n<p><?php echo esc_html__( 'acme/hero in prose', 'acme' ); ?></p>\n<!-- /wp:paragraph -->\n"
			. '<!-- wp:pattern {"slug":"acme/footer"} /-->' . "\n"
			. '<!-- wp:block {"ref":7} /-->' . "\n";

		$this->assertSame(
			$around . '<!-- wp:block {"ref":42} /-->',
			References::repoint( $around . '<!-- wp:pattern {"slug":"acme/hero"} /-->', 'slug:acme/hero', 'ref:42' )
		);
	}

	public function test_the_forms_hand_written_markup_takes_are_all_found() {
		$this->assertTrue( References::has( '<!-- wp:pattern {"slug":"acme\/hero"} /-->', 'slug:acme/hero' ), 'an escaped slash' );
		$this->assertTrue( References::has( '<!-- wp:core/pattern {"slug":"acme/hero"} /-->', 'slug:acme/hero' ), 'a namespaced block name' );
		$this->assertTrue( References::has( '<!-- wp:block {"ref":"42"} /-->', 'ref:42' ), 'an ID written as a string' );
		$this->assertFalse( References::has( '<!-- wp:block {"ref":420} /-->', 'ref:42' ), 'a longer ID that starts the same' );
		$this->assertFalse( References::has( '<!-- wp:paragraph --><p>acme/hero</p><!-- /wp:paragraph -->', 'slug:acme/hero' ), 'the name in prose' );
	}

	public function test_an_empty_content_object_stays_an_object() {
		$this->assertSame(
			'<!-- wp:block {"ref":42,"content":{}} /-->',
			References::repoint( '<!-- wp:pattern {"slug":"acme/hero","content":{}} /-->', 'slug:acme/hero', 'ref:42' )
		);
	}

	public function test_every_kind_of_place_is_found() {
		$reference = '<!-- wp:pattern {"slug":"acme/hero"} /-->';

		$page    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $reference,
			)
		);
		$draft   = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => $reference,
			)
		);
		$trashed = self::factory()->post->create(
			array(
				'post_status'  => 'trash',
				'post_content' => $reference,
			)
		);
		$user    = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_content' => $reference,
			)
		);
		$custom  = $this->customize_template( 'wp_template', 'index', $reference );
		self::factory()->post->create( array( 'post_content' => '<!-- wp:paragraph --><p>acme/hero</p><!-- /wp:paragraph -->' ) );
		self::factory()->post->create(
			array(
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $page,
				'post_content' => $reference,
			)
		);

		$this->theme_file( 'patterns/page.php', "<?php\n/**\n * Title: Page\n * Slug: acme/page\n */\n?>\n" . $reference );
		$this->theme_file( 'templates/index.html', $reference );
		$this->theme_file( 'parts/header.html', $reference );
		$this->register_pattern( 'a-plugin/landing', $reference );

		$places = References::places( 'slug:acme/hero' );

		$this->assertEqualsCanonicalizing( array( $page, $draft, $trashed, $user, $custom ), wp_list_pluck( $places['posts'], 'id' ) );
		$this->assertSame(
			array(
				'parts/header.html'    => 'template-part',
				'patterns/page.php'    => 'pattern',
				'templates/index.html' => 'template',
			),
			wp_list_pluck( $places['files'], 'kind', 'file' )
		);
		$this->assertSame( array( 'a-plugin/landing' ), wp_list_pluck( $places['plugins'], 'name' ) );
	}

	public function test_a_parent_themes_files_are_found_too() {
		$this->use_a_template_theme( true );
		file_put_contents( get_template_directory() . '/templates/single.html', '<!-- wp:block {"ref":42} /-->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$places = References::places( 'ref:42' );

		$this->assertSame( array( get_template_directory() . '/templates/single.html' ), wp_list_pluck( $places['files'], 'path' ) );
	}

	public function test_rows_and_files_are_rewritten_and_the_rest_of_each_is_kept() {
		$reference = '<!-- wp:block {"ref":42,"content":{"headline":{"content":"A \u0026 B"}}} /-->';
		$moved     = '<!-- wp:pattern {"slug":"acme/hero","content":{"headline":{"content":"A \u0026 B"}}} /-->';
		$before    = '<!-- wp:group {"metadata":{"name":"\u003cWrapper\u003e"}} --><div class="wp-block-group">';
		$after     = '</div><!-- /wp:group -->';

		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => wp_slash( $before . $reference . $after ),
			)
		);
		$custom = $this->customize_template( 'wp_template_part', 'header', wp_slash( $reference ) );
		$other  = self::factory()->post->create( array( 'post_content' => '<!-- wp:block {"ref":7} /-->' ) );
		$file   = $this->theme_file( 'patterns/page.php', "<?php\n/**\n * Title: Page\n * Slug: acme/page\n */\n?>\n<p><?php echo esc_html( 'kept' ); ?></p>\n" . $reference );
		$part   = $this->theme_file( 'parts/header.html', $reference );

		$report = References::repoint_everywhere( 'ref:42', 'slug:acme/hero' );

		$this->assertEqualsCanonicalizing( array( $page, $custom ), wp_list_pluck( $report['posts'], 'id' ) );
		$this->assertSame( array( 'parts/header.html', 'patterns/page.php' ), wp_list_pluck( $report['files'], 'file' ) );

		$this->assertSame( $before . $moved . $after, get_post( $page )->post_content );
		$this->assertSame( $moved, get_post( $custom )->post_content );
		$this->assertSame( '<!-- wp:block {"ref":7} /-->', get_post( $other )->post_content );
		$this->assertSame( "<?php\n/**\n * Title: Page\n * Slug: acme/page\n */\n?>\n<p><?php echo esc_html( 'kept' ); ?></p>\n" . $moved, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( $moved, file_get_contents( $part ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * An administrator on multisite has no unfiltered_html, and saving a post as one runs its
	 * markup through kses. Moving a reference must not cost a page the rest of what is in it.
	 */
	public function test_a_rewrite_does_not_filter_the_rest_of_a_post() {
		$markup = '<!-- wp:html --><iframe src="https://example.com/embed"></iframe><!-- /wp:html --><!-- wp:block {"ref":42} /-->';
		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $markup,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'map_meta_cap', array( $this, 'deny_unfiltered_html' ), 10, 2 );
		kses_init();

		References::repoint_everywhere( 'ref:42', 'slug:acme/hero' );

		remove_filter( 'map_meta_cap', array( $this, 'deny_unfiltered_html' ), 10 );

		$this->assertSame(
			'<!-- wp:html --><iframe src="https://example.com/embed"></iframe><!-- /wp:html --><!-- wp:pattern {"slug":"acme/hero"} /-->',
			get_post( $page )->post_content
		);
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'kses is back on for a user it applies to' );
	}

	/**
	 * Take unfiltered_html away, as multisite does.
	 *
	 * @param string[] $caps Primitive capabilities.
	 * @param string   $cap The capability asked about.
	 * @return string[]
	 */
	public function deny_unfiltered_html( $caps, $cap ) {
		return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
	}

	/**
	 * A `core/block` follows its post whatever the post's sync status: the status decides
	 * what the inserter inserts, and nothing reads it at render. So a reference repointed at
	 * an unsynced user pattern keeps rendering.
	 */
	public function test_core_block_renders_an_unsynced_user_pattern() {
		$unsynced = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_content' => '<!-- wp:paragraph --><p>From the pattern</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $unsynced, 'wp_pattern_sync_status', 'unsynced' );

		$this->assertStringContainsString( 'From the pattern', do_blocks( '<!-- wp:block {"ref":' . $unsynced . '} /-->' ) );
	}
}
