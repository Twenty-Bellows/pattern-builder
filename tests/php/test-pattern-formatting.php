<?php
/**
 * How a pattern file is written to disk.
 *
 * A pattern file is PHP in somebody's theme, and it is linted with the rest of that
 * theme. So what this plugin writes has to be what the WordPress standard already
 * accepts — otherwise a pattern committed clean comes back dirty the moment it is
 * saved, which is exactly what used to happen.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Abstract_Pattern;
use TwentyBellows\PatternBuilder\Pattern_Builder_Assets;
use TwentyBellows\PatternBuilder\Pattern_File_Store;

class Test_Pattern_Formatting extends WP_UnitTestCase {
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

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-formatting-test';

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
	 * Writes one pattern and hands back what landed on disk.
	 *
	 * @param string $content The block markup to write.
	 * @param string $name    The pattern's slug.
	 * @return string The file's contents.
	 */
	private function write_pattern( $content, $name = 'formatting-probe' ) {
		$pattern = new Abstract_Pattern(
			array(
				'title'   => 'Formatting Probe',
				'name'    => $name,
				'content' => $content,
			)
		);

		$written = $this->store->update_theme_pattern_file( $pattern );
		$this->assertNotWPError( $written );

		$path = $this->theme_dir . '/patterns/' . $name . '.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Everything after the file's PHP header — the block markup itself.
	 *
	 * @param string $contents A written pattern file.
	 * @return string
	 */
	private function markup_of( $contents ) {
		return substr( $contents, strpos( $contents, "?>\n" ) + 3 );
	}

	/**
	 * Nested markup, as the editor hands it over: one line, no whitespace of its own.
	 *
	 * @return string
	 */
	private function nested_markup() {
		return '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">' .
			'<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' .
			'</div><!-- /wp:group -->';
	}

	/**
	 * Tabs, never spaces. This is the whole of what the WordPress standard asks of
	 * inline HTML: Generic.WhiteSpace.DisallowSpaceIndent wants tabs, and
	 * Universal.WhiteSpace.PrecisionAlignment wants them at whole tab stops. A file
	 * with no space-indented line satisfies both, and phpcbf leaves it alone.
	 */
	public function test_indentation_uses_tabs_and_never_spaces() {
		$contents = $this->write_pattern( $this->nested_markup() );

		// The docblock's own ` * ` alignment is the standard's business, not ours.
		foreach ( explode( "\n", $this->markup_of( $contents ) ) as $number => $line ) {
			$this->assertDoesNotMatchRegularExpression(
				'/^\t* +/',
				$line,
				sprintf( 'Markup line %d is indented with spaces: %s', $number + 1, var_export( $line, true ) )
			);
		}
	}

	/**
	 * The nesting itself. phpcbf cannot check this — it has no model of block
	 * structure — so it is pinned here instead. One level per block: a block's
	 * comment, its own HTML and its closing comment share a depth, and only what
	 * nests inside them goes deeper.
	 */
	public function test_a_block_and_its_own_markup_share_one_level() {
		$markup = $this->markup_of( $this->write_pattern( $this->nested_markup() ) );

		$this->assertSame(
			"<!-- wp:group {\"align\":\"full\"} -->\n" .
			"<div class=\"wp-block-group alignfull\">\n" .
			"\t<!-- wp:paragraph -->\n" .
			"\t<p>Hello</p>\n" .
			"\t<!-- /wp:paragraph -->\n" .
			"</div>\n" .
			"<!-- /wp:group -->\n",
			$markup
		);
	}

	/**
	 * The case that rule has to allow for: a block with no HTML of its own. Nothing
	 * else would indent core/navigation's children, so the delimiter takes the level
	 * instead.
	 */
	public function test_a_block_with_no_html_of_its_own_still_indents_its_children() {
		$markup = $this->markup_of(
			$this->write_pattern(
				'<!-- wp:navigation -->' .
				'<!-- wp:navigation-link {"label":"Docs","url":"/docs/"} /-->' .
				'<!-- wp:navigation-link {"label":"Blog","url":"/blog/"} /-->' .
				'<!-- /wp:navigation -->'
			)
		);

		$this->assertSame(
			"<!-- wp:navigation -->\n" .
			"\t<!-- wp:navigation-link {\"label\":\"Docs\",\"url\":\"/docs/\"} /-->\n" .
			"\t<!-- wp:navigation-link {\"label\":\"Blog\",\"url\":\"/blog/\"} /-->\n" .
			"<!-- /wp:navigation -->\n",
			$markup
		);
	}

	/**
	 * A text file ends with a newline. Not a PHPCS error under every ruleset, but git
	 * reports the absence on every save and .editorconfig's insert_final_newline asks
	 * for it.
	 */
	public function test_the_file_ends_with_exactly_one_newline() {
		$contents = $this->write_pattern( $this->nested_markup() );

		$this->assertStringEndsWith( "\n", $contents );
		$this->assertStringEndsNotWith( "\n\n", $contents );
	}

	/**
	 * The PHP that points a pattern at one of its theme's own assets escapes the URL
	 * it prints. An unescaped `echo` is WordPress.Security.EscapeOutput, which no
	 * amount of reformatting fixes — phpcbf cannot repair it.
	 */
	public function test_a_theme_asset_reference_escapes_the_url_it_prints() {
		$reference = Pattern_Builder_Assets::theme_reference( '/assets/images/mark.svg' );

		$this->assertSame(
			"<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/images/mark.svg' ); ?>",
			$reference
		);
	}

	/**
	 * And nothing writes the unescaped form. There were three generators of this
	 * string; they are one now, and this fails if a fourth appears.
	 */
	public function test_a_written_pattern_never_echoes_an_unescaped_asset_url() {
		$reference = Pattern_Builder_Assets::theme_reference( '/assets/images/mark.svg' );
		$contents  = $this->write_pattern(
			'<!-- wp:image --><figure class="wp-block-image">' .
			'<img src="' . $reference . '" alt=""/>' .
			'</figure><!-- /wp:image -->'
		);

		$this->assertStringContainsString( 'esc_url(', $contents );
		$this->assertStringNotContainsString(
			'<?php echo get_stylesheet_directory_uri()',
			$contents
		);
	}

	/**
	 * A page pattern is mostly core/pattern references, and the words each slot is
	 * filled with are the part a reader came for. They break one slot to a line —
	 * from compact JSON, which is how the editor serializes a reference it has just
	 * written, so the shape does not depend on how the markup arrived.
	 */
	public function test_a_pattern_reference_writes_its_content_one_slot_to_a_line() {
		$markup = $this->markup_of(
			$this->write_pattern(
				'<!-- wp:pattern {"slug":"theme/archive-intro","content":' .
				'{"eyebrow":{"content":"Your account"},"heading":{"content":"Account"}}} /-->'
			)
		);

		$this->assertSame(
			"<!-- wp:pattern {\"slug\":\"theme/archive-intro\",\"content\":{\n" .
			"\t\"eyebrow\":{\"content\":\"Your account\"},\n" .
			"\t\"heading\":{\"content\":\"Account\"}\n" .
			"}} /-->\n",
			$markup
		);
	}

	/**
	 * And it indents with the block it sits in, rather than against the left margin.
	 */
	public function test_a_nested_pattern_reference_breaks_at_its_own_depth() {
		$markup = $this->markup_of(
			$this->write_pattern(
				'<!-- wp:group --><div class="wp-block-group">' .
				'<!-- wp:pattern {"slug":"theme/intro","content":{"heading":{"content":"Hi"}}} /-->' .
				'</div><!-- /wp:group -->'
			)
		);

		$this->assertSame(
			"<!-- wp:group -->\n" .
			"<div class=\"wp-block-group\">\n" .
			"\t<!-- wp:pattern {\"slug\":\"theme/intro\",\"content\":{\n" .
			"\t\t\"heading\":{\"content\":\"Hi\"}\n" .
			"\t}} /-->\n" .
			"</div>\n" .
			"<!-- /wp:group -->\n",
			$markup
		);
	}

	/**
	 * Breaking a delimiter over several lines is only safe because the block parser
	 * reads it the same either way. This is the assertion that says so — if it ever
	 * stops being true, the formatting is not a formatting question any more.
	 */
	public function test_formatting_does_not_change_what_the_block_parser_sees() {
		$source = '<!-- wp:pattern {"slug":"theme/archive-intro","content":' .
			'{"eyebrow":{"content":"Your account"},"lede":{"content":"Plan and details."}}} /-->';

		$formatted = $this->markup_of( $this->write_pattern( $source ) );

		$this->assertEquals(
			parse_blocks( $source )[0]['attrs'],
			parse_blocks( trim( $formatted ) )[0]['attrs']
		);
	}

	/**
	 * The point of all of it: saving a pattern that is already on disk leaves the file
	 * byte-for-byte alone. A pattern committed clean stays clean, however many times
	 * it is opened and saved.
	 */
	public function test_writing_a_pattern_back_out_changes_nothing() {
		$first = $this->write_pattern( $this->nested_markup() );
		$path  = $this->theme_dir . '/patterns/formatting-probe.php';

		$reread = Abstract_Pattern::from_file( $path );
		$this->assertNotWPError( $this->store->update_theme_pattern_file( $reread ) );

		$second = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertSame( $first, $second );
	}
}
