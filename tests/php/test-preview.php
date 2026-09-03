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
	 * Rendering against another theme, without changing the site.
	 *
	 * The whole point of carrying blank-theme is being able to look at a pattern
	 * with no design system under it. Doing that by switching the site's theme
	 * would change what every visitor sees, so the swap lasts one request: the
	 * four values deciding which theme WordPress reads are filtered, the
	 * theme.json caches cleared either side, and the active theme is never
	 * touched.
	 */
	public function test_wearing_blank_theme_leaves_the_site_alone() {
		$before = get_stylesheet();

		$this->call( 'wear_theme', array( 'blank-theme' ) );

		$this->assertSame( 'blank-theme', get_stylesheet() );
		$this->assertStringEndsWith( '/themes/blank-theme', get_stylesheet_directory() );

		$this->call( 'take_theme_off' );

		$this->assertSame( $before, get_stylesheet(), 'The active theme must be exactly as it was.' );
	}

	/**
	 * And what it renders really has none of core's presets in it.
	 *
	 * This is the assertion that says the swap reached theme.json rather than
	 * merely renaming the theme: blank-theme's functions.php is what empties
	 * core's palette, and it only runs if the preview loaded it.
	 */
	public function test_a_blank_preview_carries_no_core_presets() {
		$this->call( 'wear_theme', array( 'blank-theme' ) );

		$css  = wp_get_global_stylesheet( array( 'variables' ) );
		$html = $this->call( 'standalone_document', array( $this->a_pattern() ) );

		$this->call( 'take_theme_off' );

		foreach ( array( 'vivid-red', 'pale-pink', 'vivid-purple' ) as $slug ) {
			$this->assertStringNotContainsString(
				'--wp--preset--color--' . $slug,
				$css,
				'A blank preview emitted a core preset it is meant to have none of.'
			);
		}

		$this->assertStringContainsString( 'Body copy.', $html, 'It still has to render the pattern.' );
	}

	/**
	 * The opinionated theme brings its own, which is the other half of the pair.
	 */
	public function test_an_opinionated_preview_carries_that_themes_presets() {
		$this->call( 'wear_theme', array( 'opinionated-theme' ) );

		// The stylesheet rather than the document: wp_head() fires its enqueue
		// actions once per process, so a second document in the same run has an
		// empty head and would pass this by saying nothing at all.
		$css = wp_get_global_stylesheet( array( 'variables' ) );

		$this->call( 'take_theme_off' );

		$this->assertStringContainsString( '--wp--preset--color--base', $css );
		$this->assertStringContainsString( '--wp--preset--color--contrast', $css );
		$this->assertStringContainsString( '--wp--preset--font-size--xx-large', $css );
	}

	/**
	 * A theme nobody has is a 404 that names what there is.
	 */
	public function test_an_unknown_theme_is_refused() {
		$refused = $this->call( 'wear_theme', array( 'no-such-theme' ) );

		$this->assertWPError( $refused );
		$this->assertSame( 'pb_preview_no_theme', $refused->get_error_code() );
		$this->assertStringContainsString( 'blank-theme', $refused->get_error_message() );
	}

	/**
	 * Both bundled themes are found where the preview looks for them.
	 */
	public function test_the_bundled_themes_are_locatable() {
		$themes = Pattern_Builder_Preview::bundled_themes();

		$this->assertArrayHasKey( 'blank-theme', $themes );
		$this->assertArrayHasKey( 'opinionated-theme', $themes );

		foreach ( $themes as $slug => $dir ) {
			$this->assertFileExists( $dir . '/theme.json', $slug . ' must carry a theme.json.' );
			$this->assertFileExists( $dir . '/style.css', $slug . ' must carry a style.css.' );
		}
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
