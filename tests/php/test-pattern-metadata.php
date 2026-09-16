<?php
/**
 * What a pattern file carries that this plugin has no field for.
 *
 * Pattern Builder rebuilds a theme pattern's file from the pattern, header and all. That
 * is lossless for the files it wrote itself, and lossy for everything else: the
 * `@package`/`@subpackage`/`@since` block a theme conventionally carries, or a note left
 * for whoever opens the file next, used to disappear on the first save. These tests pin
 * down that it survives, and that preserving it did not open a way to write PHP into a
 * theme.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Abstract_Pattern;
use TwentyBellows\PatternBuilder\Pattern_File_Store;

class Test_Pattern_Metadata extends WP_UnitTestCase {
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

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-metadata-test';

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
	 * Reads a written pattern file back.
	 *
	 * @param string $name The pattern's slug.
	 * @return string
	 */
	private function file_for( $name ) {
		$path = $this->theme_dir . '/patterns/' . $name . '.php';
		$this->assertFileExists( $path );
		return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * A theme pattern file of the shape a theme generator produces: the headers this
	 * plugin knows, then the docblock tags it does not.
	 *
	 * @return string
	 */
	private function theme_authored_file() {
		return "<?php\n" .
			"/**\n" .
			" * Title: Single Q&A Replay\n" .
			" * Slug: simple-theme/single-qa-replay\n" .
			" * Inserter: no\n" .
			" *\n" .
			" * @package WordPress\n" .
			" * @subpackage MSNOW\n" .
			" * @since MSNOW 1.0\n" .
			" */\n" .
			"?>\n" .
			"<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->\n";
	}

	public function test_docblock_tags_a_theme_carries_are_read_off_the_file() {
		$path    = $this->given_file( 'single-qa-replay', $this->theme_authored_file() );
		$pattern = Abstract_Pattern::from_file( $path );

		$this->assertSame(
			"@package WordPress\n@subpackage MSNOW\n@since MSNOW 1.0",
			$pattern->additionalMetadata
		);
		// The headers still land in the fields that own them.
		$this->assertSame( 'Single Q&A Replay', $pattern->title );
		$this->assertFalse( $pattern->inserter );
	}

	public function test_docblock_tags_survive_being_written_back() {
		$path    = $this->given_file( 'single-qa-replay', $this->theme_authored_file() );
		$pattern = Abstract_Pattern::from_file( $path );

		$this->assertNotWPError( $this->store->update_theme_pattern_file( $pattern ) );

		$written = $this->file_for( 'single-qa-replay' );

		$this->assertStringContainsString( " * @package WordPress\n", $written );
		$this->assertStringContainsString( " * @subpackage MSNOW\n", $written );
		$this->assertStringContainsString( " * @since MSNOW 1.0\n", $written );
		// They belong after the headers, behind a blank line, where a theme writes them.
		$this->assertStringContainsString( " * Inserter: no\n *\n * @package WordPress\n", $written );
	}

	public function test_a_note_between_the_headers_and_the_tags_keeps_its_blank_line() {
		$path = $this->given_file(
			'noted',
			"<?php\n" .
			"/**\n" .
			" * Title: Noted\n" .
			" * Slug: simple-theme/noted\n" .
			" *\n" .
			" * Rendered for exclusive-video posts. See the wrapper pattern\n" .
			" * for how the video type is chosen.\n" .
			" *\n" .
			" * @package WordPress\n" .
			" */\n" .
			"?>\n<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->\n"
		);

		$pattern = Abstract_Pattern::from_file( $path );

		$this->assertSame(
			"Rendered for exclusive-video posts. See the wrapper pattern\n" .
			"for how the video type is chosen.\n" .
			"\n" .
			'@package WordPress',
			$pattern->additionalMetadata
		);

		$this->assertNotWPError( $this->store->update_theme_pattern_file( $pattern ) );
		$this->assertStringContainsString(
			" * for how the video type is chosen.\n *\n * @package WordPress\n",
			$this->file_for( 'noted' )
		);
	}

	public function test_writing_twice_changes_nothing() {
		$path = $this->given_file( 'single-qa-replay', $this->theme_authored_file() );

		$this->assertNotWPError( $this->store->update_theme_pattern_file( Abstract_Pattern::from_file( $path ) ) );
		$once = $this->file_for( 'single-qa-replay' );

		$this->assertNotWPError( $this->store->update_theme_pattern_file( Abstract_Pattern::from_file( $path ) ) );
		$this->assertSame( $once, $this->file_for( 'single-qa-replay' ), 'A second save should be a no-op.' );
	}

	public function test_a_pattern_with_nothing_extra_writes_the_header_it_always_did() {
		$pattern = new Abstract_Pattern(
			array(
				'title'   => 'Plain',
				'name'    => 'simple-theme/plain',
				'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertNotWPError( $this->store->update_theme_pattern_file( $pattern ) );

		$this->assertStringContainsString(
			"<?php\n/**\n * Title: Plain\n * Slug: simple-theme/plain\n * Description: \n */\n?>\n",
			$this->file_for( 'plain' )
		);
	}

	public function test_a_user_pattern_has_no_additional_metadata() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'A user pattern',
				'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( '', Abstract_Pattern::from_post( $post )->additionalMetadata );
	}

	/**
	 * The field is free text from the editor, and it is written into a PHP comment. The
	 * one sequence that escapes a block comment is `*` followed by `/`; everything after
	 * it would be parsed as code. Writing a pattern must not be a way to put code into
	 * somebody's theme.
	 */
	public function test_the_field_cannot_carry_code_out_of_the_comment() {
		$pattern = new Abstract_Pattern(
			array(
				'title'              => 'Escape attempt',
				'name'               => 'simple-theme/escape-attempt',
				'content'            => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
				'additionalMetadata' => "@package WordPress\n*/ echo 'pwned'; /*\n@since 1.0",
			)
		);

		$this->assertNotWPError( $this->store->update_theme_pattern_file( $pattern ) );
		$written = $this->file_for( 'escape-attempt' );

		// The parser's own answer: a pattern file this plugin wrote is an opening tag, a
		// doc comment, a closing tag and literal markup, and nothing else.
		$executable = array();
		foreach ( token_get_all( $written ) as $token ) {
			$name = is_array( $token ) ? token_name( $token[0] ) : "literal '{$token}'";
			if ( ! in_array( $name, array( 'T_OPEN_TAG', 'T_CLOSE_TAG', 'T_DOC_COMMENT', 'T_INLINE_HTML', 'T_WHITESPACE' ), true ) ) {
				$executable[] = $name;
			}
		}

		$this->assertSame( array(), $executable, 'The written file should contain no code.' );
		// Neutralised, not censored: the text is still there, and inert.
		$this->assertStringContainsString( "* / echo 'pwned'", $written );
		$this->assertStringContainsString( ' * @package WordPress', $written );
	}

	/**
	 * The bug this whole field exists for, at the layer an agent uses: rewriting a
	 * pattern's markup must not quietly throw away what its file already carried.
	 */
	public function test_an_agent_rewriting_only_the_content_keeps_the_file_s_metadata() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->given_file( 'single-qa-replay', $this->theme_authored_file() );

		$result = wp_get_ability( 'pattern-builder/update-pattern' )->execute(
			array(
				'id'      => 'simple-theme/single-qa-replay',
				'content' => '<!-- wp:paragraph --><p>Rewritten</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertNotWPError( $result );

		$written = $this->file_for( 'single-qa-replay' );
		$this->assertStringContainsString( ' * @subpackage MSNOW', $written );
		$this->assertStringContainsString( '<p>Rewritten</p>', $written );
	}

	/**
	 * The editor stages its edits on the entity and saves over REST, so the field has to
	 * make the round trip through the controller as well as through the store.
	 */
	public function test_the_rest_route_reads_and_writes_the_field() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->given_file( 'single-qa-replay', $this->theme_authored_file() );

		$read = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/pattern-builder/v1/patterns/simple-theme%2Fsingle-qa-replay' )
		);
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame(
			"@package WordPress\n@subpackage MSNOW\n@since MSNOW 1.0",
			$read->get_data()['additionalMetadata']
		);

		$save = new WP_REST_Request( 'PUT', '/pattern-builder/v1/patterns/simple-theme%2Fsingle-qa-replay' );
		$save->set_body_params(
			array(
				'content'            => '<!-- wp:paragraph --><p>Edited</p><!-- /wp:paragraph -->',
				'additionalMetadata' => "@package WordPress\n@subpackage MSNOW\n@since MSNOW 2.0",
			)
		);
		$saved = rest_get_server()->dispatch( $save );
		$this->assertSame( 200, $saved->get_status() );

		$this->assertStringContainsString( ' * @since MSNOW 2.0', $this->file_for( 'single-qa-replay' ) );
	}

	/**
	 * A recognised header typed into the field would sit beside the real one and be
	 * ignored by get_file_data(), which reads the first match. Dropping it keeps the
	 * field's meaning intact: it holds what the other fields do not.
	 */
	public function test_a_header_typed_into_the_field_does_not_become_a_second_header() {
		$pattern = new Abstract_Pattern(
			array(
				'title'              => 'The real title',
				'name'               => 'simple-theme/one-title',
				'content'            => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
				'additionalMetadata' => "Title: Not this one\n@package WordPress",
			)
		);

		$this->assertNotWPError( $this->store->update_theme_pattern_file( $pattern ) );
		$written = $this->file_for( 'one-title' );

		$this->assertSame( 1, substr_count( $written, 'Title:' ) );
		$this->assertStringNotContainsString( 'Not this one', $written );
		$this->assertStringContainsString( ' * @package WordPress', $written );
	}
}
