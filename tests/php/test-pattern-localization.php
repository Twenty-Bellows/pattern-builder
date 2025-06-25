<?php

use TwentyBellows\PatternBuilder\Pattern_Builder_Controller;
use TwentyBellows\PatternBuilder\Abstract_Pattern;

class Test_Pattern_Localization extends WP_UnitTestCase {

	private $controller;
	private $test_dir;

	public function setUp(): void {
		parent::setUp();
		$this->controller = new Pattern_Builder_Controller();
		
		// Set up test theme directory.
		$this->test_dir = get_temp_dir() . 'pattern-builder-test-' . time();
		wp_mkdir_p( $this->test_dir );
		
		// Mock get_stylesheet_directory to return our test directory.
		add_filter( 'stylesheet_directory', array( $this, 'mock_stylesheet_directory' ) );
		add_filter( 'stylesheet', array( $this, 'mock_stylesheet' ) );
	}

	public function tearDown(): void {
		// Clean up test directory.
		if ( is_dir( $this->test_dir ) ) {
			$this->delete_directory( $this->test_dir );
		}
		
		remove_filter( 'stylesheet_directory', array( $this, 'mock_stylesheet_directory' ) );
		remove_filter( 'stylesheet', array( $this, 'mock_stylesheet' ) );
		
		parent::tearDown();
	}

	public function mock_stylesheet_directory() {
		return $this->test_dir;
	}

	public function mock_stylesheet() {
		return 'test-theme';
	}

	private function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->delete_directory( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * Test that paragraph blocks are localized correctly.
	 */
	public function test_localize_paragraph_block() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:paragraph --><p>Hello World</p><!-- /wp:paragraph -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Hello World', 'test-theme' ); ?>", $localized_pattern->content );
	}

	/**
	 * Test that heading blocks are localized correctly.
	 */
	public function test_localize_heading_block() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:heading --><h2>Welcome to Our Site</h2><!-- /wp:heading -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Welcome to Our Site', 'test-theme' ); ?>", $localized_pattern->content );
	}

	/**
	 * Test that button blocks are localized correctly.
	 */
	public function test_localize_button_block() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Contact Us</a></div><!-- /wp:button -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Contact Us', 'test-theme' ); ?>", $localized_pattern->content );
	}

	/**
	 * Test that pullquote blocks are localized with separate calls for paragraph and citation.
	 */
	public function test_localize_pullquote_block() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Pullquote Quote</p><cite>and the citation</cite></blockquote></figure><!-- /wp:pullquote -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		// Check that the paragraph content is localized separately
		$this->assertStringContainsString( "<p><?php echo wp_kses_post( 'Pullquote Quote', 'test-theme' ); ?></p>", $localized_pattern->content );
		
		// Check that the citation content is localized separately
		$this->assertStringContainsString( "<cite><?php echo wp_kses_post( 'and the citation', 'test-theme' ); ?></cite>", $localized_pattern->content );
		
		// Verify that the blockquote tags themselves are not included in the localization calls
		$this->assertStringNotContainsString( "wp_kses_post( '<blockquote>", $localized_pattern->content );
		$this->assertStringNotContainsString( "wp_kses_post( '<p>", $localized_pattern->content );
		$this->assertStringNotContainsString( "wp_kses_post( '<cite>", $localized_pattern->content );
	}

	/**
	 * Test that image blocks with alt text are localized correctly.
	 */
	public function test_localize_image_block_with_alt() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:image {"alt":"Beautiful sunset"} --><figure class="wp-block-image"><img src="sunset.jpg" alt="Beautiful sunset"/></figure><!-- /wp:image -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		// Check that alt attribute was localized in the HTML.
		$this->assertStringContainsString( 'alt="<?php echo esc_attr__( \'Beautiful sunset\', \'test-theme\' ); ?>"', $localized_pattern->content );
	}

	/**
	 * Test that image blocks with captions are localized correctly.
	 */
	public function test_localize_image_block_with_caption() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:image --><figure class="wp-block-image"><img src="sunset.jpg"/><figcaption>A beautiful sunset over the ocean</figcaption></figure><!-- /wp:image -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		$this->assertStringContainsString( "<?php echo wp_kses_post( 'A beautiful sunset over the ocean', 'test-theme' ); ?>", $localized_pattern->content );
	}

	/**
	 * Test that navigation link blocks are handled (currently not localized due to serialization issues).
	 */
	public function test_localize_navigation_link_block() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:navigation-link {"label":"About Us","url":"/about"} /-->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		// For now, navigation links are not localized due to serialization encoding issues.
		// The content should remain unchanged.
		$this->assertStringContainsString( '{"label":"About Us","url":"/about"}', $localized_pattern->content );
	}

	/**
	 * Test that table blocks are localized correctly.
	 */
	public function test_localize_table_block() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Name</th><th>Age</th></tr></thead><tbody><tr><td>John</td><td>25</td></tr></tbody></table></figure><!-- /wp:table -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Name', 'test-theme' ); ?>", $localized_pattern->content );
		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Age', 'test-theme' ); ?>", $localized_pattern->content );
		$this->assertStringContainsString( "<?php echo wp_kses_post( 'John', 'test-theme' ); ?>", $localized_pattern->content );
		$this->assertStringContainsString( "<?php echo wp_kses_post( '25', 'test-theme' ); ?>", $localized_pattern->content );
	}

	/**
	 * Test that nested blocks are localized correctly.
	 */
	public function test_localize_nested_blocks() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Section Title</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Section content goes here.</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Section Title', 'test-theme' ); ?>", $localized_pattern->content );
		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Section content goes here.', 'test-theme' ); ?>", $localized_pattern->content );
	}

	/**
	 * Test that text with single quotes is properly escaped.
	 */
	public function test_localize_text_with_quotes() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:paragraph --><p>It\'s a beautiful day!</p><!-- /wp:paragraph -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		$this->assertStringContainsString( "<?php echo wp_kses_post( 'It\\'s a beautiful day!', 'test-theme' ); ?>", $localized_pattern->content );
	}

	/**
	 * Test that empty blocks are not affected.
	 */
	public function test_localize_empty_blocks() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:spacer --><div style="height:50px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		// Should not contain any localization functions for empty content.
		$this->assertStringNotContainsString( "wp_kses_post( '', ", $localized_pattern->content );
		$this->assertStringNotContainsString( "wp_kses_post( ' ', ", $localized_pattern->content );
	}

	/**
	 * Test that blocks without translatable content are not affected.
	 */
	public function test_localize_non_translatable_blocks() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		// Should not contain any localization functions.
		$this->assertStringNotContainsString( 'wp_kses_post', $localized_pattern->content );
		$this->assertStringNotContainsString( 'esc_attr__', $localized_pattern->content );
	}

	/**
	 * Test that complex patterns with multiple block types are handled correctly.
	 */
	public function test_localize_complex_pattern() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Our Services</h2><!-- /wp:heading --><!-- wp:paragraph --><p>We provide excellent services for our clients.</p><!-- /wp:paragraph --><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/services">Learn More</a></div><!-- /wp:button --></div><!-- /wp:group -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Our Services', 'test-theme' ); ?>", $localized_pattern->content );
		$this->assertStringContainsString( "<?php echo wp_kses_post( 'We provide excellent services for our clients.', 'test-theme' ); ?>", $localized_pattern->content );
		$this->assertStringContainsString( "<?php echo wp_kses_post( 'Learn More', 'test-theme' ); ?>", $localized_pattern->content );
	}

	/**
	 * Test that pullquote blocks with multiple paragraphs are handled correctly.
	 */
	public function test_localize_pullquote_block_multiple_paragraphs() {
		$pattern = new Abstract_Pattern( array(
			'name'    => 'test-pattern',
			'title'   => 'Test Pattern',
			'content' => '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>First paragraph of the quote.</p><p>Second paragraph of the quote.</p><cite>Quote Author</cite></blockquote></figure><!-- /wp:pullquote -->'
		) );

		$localized_pattern = $this->controller->localize_pattern_content( $pattern );

		// Check that both paragraph contents are localized separately
		$this->assertStringContainsString( "<p><?php echo wp_kses_post( 'First paragraph of the quote.', 'test-theme' ); ?></p>", $localized_pattern->content );
		$this->assertStringContainsString( "<p><?php echo wp_kses_post( 'Second paragraph of the quote.', 'test-theme' ); ?></p>", $localized_pattern->content );
		
		// Check that the citation content is localized separately
		$this->assertStringContainsString( "<cite><?php echo wp_kses_post( 'Quote Author', 'test-theme' ); ?></cite>", $localized_pattern->content );
	}
}