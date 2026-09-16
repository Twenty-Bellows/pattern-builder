<?php
/**
 * Pattern files that are programs rather than markup.
 *
 * Core reads a pattern file by running it and keeping the output, and this plugin reads
 * one the same way. That is fine until the pattern is written back: what is in hand is a
 * snapshot of one run, so saving it would put the snapshot where the program was, losing
 * every branch that run did not take, freezing every rendered block as HTML and every
 * translated string in one language. These tests pin down that such a file is recognised
 * and never written.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Abstract_Pattern;
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
}
