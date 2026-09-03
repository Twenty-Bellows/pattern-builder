<?php
/**
 * The preview document.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Abstract_Pattern;
use TwentyBellows\PatternBuilder\Pattern_Builder_Preview;

/**
 * Rendering a pattern as something a browser can open.
 */
class Test_Preview extends WP_UnitTestCase {

	/**
	 * @var Pattern_Builder_Preview
	 */
	private $preview;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->preview = new Pattern_Builder_Preview();
	}

	/**
	 * Reach a private builder, since the route wiring is core's business.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function call( $method, $args = array() ) {
		$m = new ReflectionMethod( $this->preview, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->preview, $args );
	}

	private function a_pattern( $content = '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->' ) {
		return new Abstract_Pattern(
			array(
				'title'   => 'Preview Probe',
				'name'    => 'preview-probe',
				'content' => $content,
			)
		);
	}

	/**
	 * The whole reason this exists: the document carries the site's stylesheets.
	 *
	 * `render-pattern` answers with markup, which cannot show that a band is
	 * rendering at the wrong width. Without wp_head() this route would be the
	 * same answer in a bigger envelope.
	 */
	public function test_the_document_carries_the_sites_styles() {
		$html = $this->call( 'standalone_document', array( $this->a_pattern() ) );

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( '<title>Preview Probe</title>', $html );
		$this->assertStringContainsString( 'Body copy.', $html );
		$this->assertStringContainsString( 'wp-block-library', $html );
		$this->assertStringContainsString( 'global-styles', $html );
	}

	/**
	 * Blocks are rendered, not handed over as comments.
	 */
	public function test_blocks_are_rendered() {
		$html = $this->call( 'standalone_document', array( $this->a_pattern() ) );

		$this->assertStringNotContainsString( '<!-- wp:paragraph -->', $html );
	}

	/**
	 * The stand-in is what makes the page context possible.
	 *
	 * `core/post-content` renders nothing without a post, and the point of that
	 * context is the wrappers it puts around the pattern — so the pattern goes
	 * in as a page's content and a stand-in post carries it, primed into the
	 * object cache for one request rather than written anywhere.
	 */
	public function test_the_stand_in_lets_post_content_render_the_pattern() {
		$this->call( 'pose_as_a_page', array( '<!-- wp:paragraph --><p>Inside the template.</p><!-- /wp:paragraph -->' ) );

		$html = do_blocks( '<!-- wp:post-content /-->' );

		$this->call( 'stop_posing' );

		$this->assertStringContainsString( 'Inside the template.', $html );
	}

	/**
	 * And it leaves nothing behind: no row, and no cached post either.
	 */
	public function test_the_stand_in_is_cleaned_up() {
		$this->call( 'pose_as_a_page', array( '<!-- wp:paragraph --><p>Transient.</p><!-- /wp:paragraph -->' ) );
		$this->call( 'stop_posing' );

		$this->assertFalse( wp_cache_get( Pattern_Builder_Preview::STAND_IN_ID, 'posts' ) );
		$this->assertNull( get_post( Pattern_Builder_Preview::STAND_IN_ID ) );
		$this->assertArrayNotHasKey( 'pattern_builder_preview_post', $GLOBALS );
	}

	/**
	 * A pattern that is not here is a 404, not an empty document.
	 */
	public function test_an_unknown_pattern_is_refused() {
		$found = $this->call( 'find', array( 'no-such-theme/no-such-pattern' ) );

		$this->assertWPError( $found );
		$this->assertSame( 'pb_preview_not_found', $found->get_error_code() );
	}

	/**
	 * Naming nothing is a different mistake and says so.
	 */
	public function test_naming_no_pattern_is_refused() {
		$found = $this->call( 'find', array( '' ) );

		$this->assertWPError( $found );
		$this->assertSame( 'pb_preview_no_pattern', $found->get_error_code() );
	}

	/**
	 * A user pattern previews by post ID.
	 */
	public function test_a_user_pattern_previews_by_id() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Reusable',
				'post_content' => '<!-- wp:paragraph --><p>From the database.</p><!-- /wp:paragraph -->',
			)
		);

		$found = $this->call( 'find', array( (string) $id ) );

		$this->assertNotWPError( $found );
		$this->assertStringContainsString( 'From the database.', $found->content );
	}

	/**
	 * The URL is what render-pattern hands back, so its shape is part of the contract.
	 */
	public function test_the_url_names_the_pattern_and_context() {
		$url = Pattern_Builder_Preview::url_for( 'my-theme/hero', 'page' );

		$this->assertStringContainsString( 'pattern-builder', $url );
		$this->assertStringContainsString( 'preview', $url );
		$this->assertStringContainsString( 'pattern=my-theme%2Fhero', $url );
		$this->assertStringContainsString( 'context=page', $url );
	}

	/**
	 * The route is registered where the answer says it is.
	 */
	public function test_the_route_is_registered() {
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/pattern-builder/v1/preview', $routes );
	}
}
