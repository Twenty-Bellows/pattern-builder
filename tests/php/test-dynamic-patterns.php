<?php
/**
 * Pattern files that are programs rather than markup.
 *
 * Core reads a pattern file by running it and keeping the output, and this plugin reads
 * one the same way. That is fine until the pattern is written back: what is in hand is a
 * snapshot of one run, so saving it would put the snapshot where the program was, losing
 * every branch that run did not take, freezing every rendered block as HTML and every
 * translated string in one language. These tests pin down that such a file is recognised
 * and never written, and that no write ever makes one.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Abstract_Pattern;
use TwentyBellows\PatternBuilder\Pattern_Builder_Assets;
use TwentyBellows\PatternBuilder\Pattern_File_Store;

class Test_Dynamic_Patterns extends WP_UnitTestCase {
	/**
	 * The writable theme directory these tests treat as the active theme.
	 *
	 * @var string
	 */
	private $theme_dir;

	/**
	 * The store under test.
	 *
	 * @var Pattern_File_Store
	 */
	private $store;

	public function set_up() {
		parent::set_up();

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-dynamic-test';

		if ( ! is_dir( $this->theme_dir . '/patterns' ) ) {
			mkdir( $this->theme_dir . '/patterns', 0777, true );
		}

		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'template_directory', array( $this, 'theme_dir' ) );
		add_filter( 'stylesheet', array( $this, 'theme_slug' ) );
		add_filter( 'template', array( $this, 'theme_slug' ) );

		$this->store = new Pattern_File_Store();
	}

	public function tear_down() {
		foreach ( (array) glob( $this->theme_dir . '/patterns/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		remove_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		remove_filter( 'template_directory', array( $this, 'theme_dir' ) );
		remove_filter( 'stylesheet', array( $this, 'theme_slug' ) );
		remove_filter( 'template', array( $this, 'theme_slug' ) );

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
	 * The theme's slug, as a filter.
	 *
	 * @return string
	 */
	public function theme_slug() {
		return 'simple-theme';
	}

	/**
	 * Puts a pattern file on disk exactly as given.
	 *
	 * @param string $name     The pattern's slug.
	 * @param string $contents The file's contents.
	 * @return string The path written.
	 */
	private function given_file( $name, $contents ) {
		$path = $this->theme_dir . '/patterns/' . $name . '.php';
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		return $path;
	}

	/**
	 * Reads a pattern file back off disk.
	 *
	 * @param string $name The pattern's slug.
	 * @return string
	 */
	private function file_for( $name ) {
		return file_get_contents( $this->theme_dir . '/patterns/' . $name . '.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * A pattern file of the kind this guard exists for: a branch whose untaken side would
	 * vanish, a translated string, and a path built at render time.
	 *
	 * @return string
	 */
	private function program_file() {
		return "<?php\n" .
			"/**\n" .
			" * Title: Single Q&A Replay\n" .
			" * Slug: simple-theme/single-qa-replay\n" .
			" * Inserter: no\n" .
			" *\n" .
			" * @package WordPress\n" .
			" */\n" .
			"\n" .
			"\$has_video = (bool) get_post_meta( get_the_ID(), 'mpx_video_file_default', true );\n" .
			"?>\n" .
			"<!-- wp:group -->\n" .
			"<div class=\"wp-block-group\">\n" .
			"\t<?php if ( \$has_video ) : ?>\n" .
			"\t\t<p>The player</p>\n" .
			"\t<?php else : ?>\n" .
			"\t\t<p><?php esc_html_e( 'Replay unavailable.', 'simple-theme' ); ?></p>\n" .
			"\t<?php endif; ?>\n" .
			"</div>\n" .
			"<!-- /wp:group -->\n";
	}

	/**
	 * A pattern file of the kind this plugin writes.
	 *
	 * @return string
	 */
	private function markup_file() {
		return "<?php\n" .
			"/**\n" .
			" * Title: Just Markup\n" .
			" * Slug: simple-theme/just-markup\n" .
			" *\n" .
			" * @package WordPress\n" .
			" */\n" .
			"?>\n" .
			"<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->\n";
	}

	public function test_a_file_of_markup_is_not_flagged() {
		$path = $this->given_file( 'just-markup', $this->markup_file() );

		$this->assertFalse( Abstract_Pattern::file_has_custom_php( $path ) );
		$this->assertFalse( Abstract_Pattern::from_file( $path )->hasCustomPhp );
	}

	public function test_a_file_that_runs_php_is_flagged() {
		$path = $this->given_file( 'single-qa-replay', $this->program_file() );

		$this->assertTrue( Abstract_Pattern::file_has_custom_php( $path ) );
		$this->assertTrue( Abstract_Pattern::from_file( $path )->hasCustomPhp );
	}

	/**
	 * An ordinary comment runs nothing, but it sits outside the header comment and so
	 * would be dropped on a write just the same. The safe answer is the conservative one.
	 */
	public function test_an_ordinary_comment_counts_too() {
		$path = $this->given_file(
			'commented',
			"<?php\n/**\n * Title: Commented\n * Slug: simple-theme/commented\n */\n// Kept for reference.\n?>\n<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->\n"
		);

		$this->assertTrue( Abstract_Pattern::file_has_custom_php( $path ) );
	}

	/**
	 * The plugin writes PHP into pattern markup itself: a localized save turns text into
	 * `<?php echo wp_kses_post( 'text', 'domain' ); ?>` and writes it out again the same
	 * way. That round trip works, so it must not be what trips the guard.
	 */
	public function test_the_plugins_own_localized_strings_do_not_count() {
		$path = $this->given_file(
			'localized',
			"<?php\n/**\n * Title: Localized\n * Slug: simple-theme/localized\n */\n?>\n" .
			"<!-- wp:heading -->\n" .
			"<h2 class=\"wp-block-heading\"><?php echo wp_kses_post( 'A heading', 'simple-theme' ); ?></h2>\n" .
			"<!-- /wp:heading -->\n" .
			"<!-- wp:image {\"alt\":\"<?php echo esc_attr__( 'A picture', 'simple-theme' ); ?>\"} -->\n" .
			"<figure class=\"wp-block-image\"><img alt=\"\"/></figure>\n" .
			"<!-- /wp:image -->\n"
		);

		$this->assertFalse( Abstract_Pattern::file_has_custom_php( $path ) );
	}

	/**
	 * The other thing the plugin writes: importing a pattern's images rewrites them as
	 * `Pattern_Builder_Assets::theme_reference()`, which is the same round trip — reading
	 * resolves the URL, writing points it back at the theme.
	 */
	public function test_the_plugins_own_theme_asset_references_do_not_count() {
		$reference = \TwentyBellows\PatternBuilder\Pattern_Builder_Assets::theme_reference( '/assets/images/square-1.svg' );

		$path = $this->given_file(
			'with-an-image',
			"<?php\n/**\n * Title: With an image\n * Slug: simple-theme/with-an-image\n */\n?>\n" .
			"<!-- wp:image -->\n" .
			'<figure class="wp-block-image"><img src="' . $reference . '" alt=""/></figure>' . "\n" .
			"<!-- /wp:image -->\n"
		);

		$this->assertFalse( Abstract_Pattern::file_has_custom_php( $path ) );
		$this->assertFalse( Abstract_Pattern::from_file( $path )->hasCustomPhp );
	}

	/**
	 * The same call reaching for anything but the theme's own directory is not one the
	 * plugin wrote, and is not a round trip it can make.
	 */
	public function test_an_asset_reference_the_plugin_does_not_write_does_count() {
		$path = $this->given_file(
			'foreign-asset',
			"<?php\n/**\n * Title: Foreign asset\n * Slug: simple-theme/foreign-asset\n */\n?>\n" .
			"<!-- wp:image -->\n" .
			'<figure class="wp-block-image"><img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . \'/x.svg\' ); ?>" alt=""/></figure>' . "\n" .
			"<!-- /wp:image -->\n"
		);

		$this->assertTrue( Abstract_Pattern::file_has_custom_php( $path ) );
	}

	/**
	 * Anything else in that position is a call this plugin cannot reproduce — the string
	 * would survive the round trip but the call around it would not.
	 */
	public function test_a_translation_call_the_plugin_does_not_write_does_count() {
		$path = $this->given_file(
			'hand-localized',
			"<?php\n/**\n * Title: Hand Localized\n * Slug: simple-theme/hand-localized\n */\n?>\n" .
			"<!-- wp:paragraph -->\n<p><?php esc_html_e( 'Written by hand', 'simple-theme' ); ?></p>\n<!-- /wp:paragraph -->\n"
		);

		$this->assertTrue( Abstract_Pattern::file_has_custom_php( $path ) );
	}

	/**
	 * A variable where a literal belongs is not a localized string, however much it looks
	 * like one.
	 */
	public function test_a_variable_in_a_localized_string_does_count() {
		$path = $this->given_file(
			'variable',
			"<?php\n/**\n * Title: Variable\n * Slug: simple-theme/variable\n */\n?>\n" .
			"<!-- wp:paragraph -->\n<p><?php echo wp_kses_post( \$text, 'simple-theme' ); ?></p>\n<!-- /wp:paragraph -->\n"
		);

		$this->assertTrue( Abstract_Pattern::file_has_custom_php( $path ) );
	}

	/**
	 * The guarantee, stated as plainly as it can be: the bytes on disk do not move.
	 */
	public function test_the_store_refuses_to_write_over_a_program_and_leaves_it_alone() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$path   = $this->given_file( 'single-qa-replay', $this->program_file() );
		$before = $this->file_for( 'single-qa-replay' );

		$pattern          = Abstract_Pattern::from_file( $path );
		$pattern->content = '<!-- wp:paragraph --><p>Flattened</p><!-- /wp:paragraph -->';

		$result = $this->store->update_theme_pattern( $pattern );

		$this->assertWPError( $result );
		$this->assertSame( 'pattern_file_has_custom_php', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( $before, $this->file_for( 'single-qa-replay' ) );
	}

	/**
	 * The lower-level write is public, so it carries the same guard rather than trusting
	 * every future caller to have gone through the one above.
	 */
	public function test_the_file_write_carries_the_guard_too() {
		$path = $this->given_file( 'single-qa-replay', $this->program_file() );

		$this->assertWPError( $this->store->update_theme_pattern_file( Abstract_Pattern::from_file( $path ) ) );
	}

	public function test_a_pattern_with_no_file_yet_is_still_created() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->store->update_theme_pattern(
			new Abstract_Pattern(
				array(
					'title'   => 'Brand New',
					'name'    => 'simple-theme/brand-new',
					'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
				)
			)
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '<p>Hi</p>', $this->file_for( 'brand-new' ) );
	}

	public function test_a_user_pattern_never_has_php() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'A user pattern',
				'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertFalse( Abstract_Pattern::from_post( $post )->hasCustomPhp );
	}

	public function test_rest_reports_it_and_refuses_the_save() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->given_file( 'single-qa-replay', $this->program_file() );
		$before = $this->file_for( 'single-qa-replay' );

		$read = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/pattern-builder/v1/patterns/simple-theme%2Fsingle-qa-replay' )
		);
		$this->assertSame( 200, $read->get_status() );
		$this->assertTrue( $read->get_data()['hasCustomPhp'] );

		$save = new WP_REST_Request( 'PUT', '/pattern-builder/v1/patterns/simple-theme%2Fsingle-qa-replay' );
		$save->set_body_params( array( 'content' => '<!-- wp:paragraph --><p>Flattened</p><!-- /wp:paragraph -->' ) );
		$saved = rest_get_server()->dispatch( $save );

		$this->assertSame( 409, $saved->get_status() );
		$this->assertSame( $before, $this->file_for( 'single-qa-replay' ) );
	}

	public function test_an_agent_is_told_and_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->given_file( 'single-qa-replay', $this->program_file() );
		$before = $this->file_for( 'single-qa-replay' );

		$listed = wp_get_ability( 'pattern-builder/list-patterns' )->execute( array( 'source' => 'theme' ) );
		$this->assertNotWPError( $listed );

		$found = null;
		foreach ( $listed['patterns'] as $summary ) {
			if ( 'simple-theme/single-qa-replay' === $summary['name'] ) {
				$found = $summary;
			}
		}
		$this->assertNotNull( $found, 'The pattern should still be listed.' );
		$this->assertTrue( $found['hasCustomPhp'] );

		$result = wp_get_ability( 'pattern-builder/update-pattern' )->execute(
			array(
				'id'      => 'simple-theme/single-qa-replay',
				'content' => '<!-- wp:paragraph --><p>Flattened</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( $before, $this->file_for( 'single-qa-replay' ) );
	}

	/**
	 * Writes a new pattern through the store, as an administrator.
	 *
	 * @param array $args    Pattern arguments, over a plain paragraph named `written`.
	 * @param array $options Options for update_theme_pattern().
	 * @return Abstract_Pattern|WP_Error
	 */
	private function write( $args, $options = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		return $this->store->update_theme_pattern(
			new Abstract_Pattern(
				array_merge(
					array(
						'title'   => 'Written',
						'name'    => 'simple-theme/written',
						'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
					),
					$args
				)
			),
			$options
		);
	}

	/**
	 * Markup is the one input a write puts into the file whole, so PHP in it would run on
	 * every render. Nothing reaches the disk, and the refusal says what it found.
	 */
	public function test_php_in_the_markup_is_refused_and_nothing_is_written() {
		$result = $this->write( array( 'content' => "<!-- wp:html -->\n<?php echo chr( 88 ); ?>\n<!-- /wp:html -->" ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pattern_has_custom_php', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'echo chr( 88 );', $result->get_error_message() );
		$this->assertFalse( file_exists( $this->theme_dir . '/patterns/written.php' ) );
	}

	public function test_the_short_echo_tag_is_refused_too() {
		$result = $this->write( array( 'content' => "<!-- wp:paragraph -->\n<p><?= get_bloginfo( 'name' ) ?></p>\n<!-- /wp:paragraph -->" ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pattern_has_custom_php', $result->get_error_code() );
	}

	/**
	 * A call the plugin writes earns no pass for whatever follows it in the same block.
	 */
	public function test_php_after_one_of_the_plugins_own_calls_is_refused() {
		$result = $this->write( array( 'content' => "<!-- wp:paragraph -->\n<p><?php echo wp_kses_post( 'Hi', 'simple-theme' ); echo chr( 88 ); ?></p>\n<!-- /wp:paragraph -->" ) );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'chr( 88 )', $result->get_error_message() );
	}

	/**
	 * The guard has to agree with the code that writes PHP, or the plugin would refuse its
	 * own saves: a localized write and an image the theme carries both go through.
	 */
	public function test_the_plugins_own_php_still_writes() {
		$reference = Pattern_Builder_Assets::theme_reference( '/assets/images/square-1.svg' );

		$result = $this->write(
			array(
				'content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">A heading</h2>\n<!-- /wp:heading -->\n" .
					"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"" . $reference . "\" alt=\"A picture\"/></figure>\n<!-- /wp:image -->",
			),
			array(
				'localize'      => true,
				'import_images' => false,
			)
		);

		$this->assertNotWPError( $result );
		$file = $this->file_for( 'written' );
		$this->assertStringContainsString( "wp_kses_post( 'A heading', 'simple-theme' )", $file );
		$this->assertStringContainsString( "esc_attr__( 'A picture', 'simple-theme' )", $file );
		$this->assertStringContainsString( $reference, $file );
	}

	/**
	 * The title is written inside the header comment, where a closing marker would end the
	 * comment and make the rest of the title code. It stays text instead.
	 */
	public function test_a_comment_closer_in_the_title_stays_inside_the_header() {
		$result = $this->write( array( 'title' => "Hero */ echo chr( 88 ); /*" ) );

		$this->assertNotWPError( $result );
		$this->assertFalse( Abstract_Pattern::file_has_custom_php( $this->theme_dir . '/patterns/written.php' ) );
		$this->assertStringNotContainsString( '*/ echo', $this->file_for( 'written' ) );
	}

	public function test_a_comment_closer_in_the_description_stays_inside_the_header() {
		$result = $this->write( array( 'description' => "A hero band */ echo chr( 88 ); /*" ) );

		$this->assertNotWPError( $result );
		$this->assertFalse( Abstract_Pattern::file_has_custom_php( $this->theme_dir . '/patterns/written.php' ) );
	}

	/**
	 * Each header is one line, so a newline in a value would start a header of its own.
	 */
	public function test_a_newline_in_the_title_cannot_forge_a_header() {
		$result = $this->write( array( 'title' => "Hero\n * Inserter: no" ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( Abstract_Pattern::from_file( $this->theme_dir . '/patterns/written.php' )->inserter );
	}

	/**
	 * Localizing puts text inside a PHP string, so the text has to stay there: a backslash
	 * before a quote used to end the string early and run what followed.
	 */
	public function test_localized_text_cannot_end_its_string_early() {
		$text   = "\\'); echo chr( 88 ); //";
		$result = $this->write(
			array( 'content' => "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->" ),
			array( 'localize' => true )
		);

		$this->assertNotWPError( $result );
		$path = $this->theme_dir . '/patterns/written.php';
		$this->assertFalse( Abstract_Pattern::file_has_custom_php( $path ) );
		$this->assertStringContainsString( $text, Abstract_Pattern::from_file( $path )->content );
	}

	public function test_an_asset_path_cannot_end_its_string_early() {
		$reference = Pattern_Builder_Assets::theme_reference( "/assets/images/x'.chr( 88 ).'.png" );

		$this->assertNull( Abstract_Pattern::find_custom_php( $reference ) );
	}

	public function test_an_agent_writing_php_is_told_why() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = wp_get_ability( 'pattern-builder/create-pattern' )->execute(
			array(
				'title'   => 'Agent PHP',
				'name'    => 'agent-php',
				'content' => "<!-- wp:html -->\n<?php echo chr( 88 ); ?>\n<!-- /wp:html -->",
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pattern_has_custom_php', $result->get_error_code() );
		$this->assertFalse( file_exists( $this->theme_dir . '/patterns/agent-php.php' ) );
	}
}
