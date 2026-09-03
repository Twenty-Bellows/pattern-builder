<?php
/**
 * Getting a file onto the site, and finding what is already here.
 *
 * The route is the interesting half: it exists because an ability cannot
 * carry bytes, so these check the two shapes it accepts and the fact that
 * every media-facing ability tells a caller it is there.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Assets;

class Test_Assets extends WP_UnitTestCase {

	/**
	 * The writable theme directory these tests treat as the active theme.
	 *
	 * @var string
	 */
	private $theme_dir;

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-assets-test';

		if ( ! is_dir( $this->theme_dir . '/assets/images' ) ) {
			mkdir( $this->theme_dir . '/assets/images', 0777, true );
		}

		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'template_directory', array( $this, 'theme_dir' ) );
		add_filter( 'stylesheet', array( $this, 'theme_slug' ) );
		add_filter( 'template', array( $this, 'theme_slug' ) );

		// The route is registered on rest_api_init, which the REST test
		// helpers fire; instantiating the component hooks it up.
		new Pattern_Builder_Assets();
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		foreach ( (array) glob( $this->theme_dir . '/assets/images/*' ) as $file ) {
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
	 * A placeholder is drawn at the size asked for, and labels itself with
	 * its own dimensions when nothing else is given.
	 */
	public function test_placeholder_is_drawn_at_the_size_asked_for() {
		$svg = Pattern_Builder_Assets::placeholder_svg(
			array(
				'width'  => 1400,
				'height' => 600,
			)
		);

		$this->assertStringContainsString( 'viewBox="0 0 1400 600"', $svg );
		$this->assertStringContainsString( 'width="1400"', $svg );
		$this->assertStringContainsString( '1400 × 600', $svg );
	}

	/**
	 * A label replaces the dimensions, escaped.
	 */
	public function test_placeholder_label_is_escaped() {
		$svg = Pattern_Builder_Assets::placeholder_svg(
			array(
				'label' => 'Tom & Jerry <hero>',
			)
		);

		$this->assertStringContainsString( 'Tom &amp; Jerry', $svg );
		$this->assertStringNotContainsString( '<hero>', $svg );
	}

	/**
	 * An absurd size is clamped rather than accepted.
	 */
	public function test_placeholder_size_is_clamped() {
		$svg = Pattern_Builder_Assets::placeholder_svg(
			array(
				'width'  => 999999,
				'height' => -5,
			)
		);

		$this->assertStringContainsString( 'viewBox="0 0 8000 16"', $svg );
	}

	/**
	 * Scripts, handlers and external references come out of an SVG.
	 */
	public function test_svg_sanitization_strips_what_executes() {
		$svg = Pattern_Builder_Assets::sanitize_svg(
			'<svg xmlns="http://www.w3.org/2000/svg" onload="steal()">' .
			'<script>alert(1)</script>' .
			'<a href="javascript:alert(2)"><rect width="10" height="10"/></a>' .
			'<image href="https://elsewhere.example/tracker.png"/>' .
			'</svg>'
		);

		$this->assertNotWPError( $svg );
		$this->assertStringNotContainsString( 'script', $svg );
		$this->assertStringNotContainsString( 'onload', $svg );
		$this->assertStringNotContainsString( 'javascript:', $svg );
		$this->assertStringNotContainsString( 'elsewhere.example', $svg );
		// The drawing itself survives.
		$this->assertStringContainsString( '<rect width="10" height="10"/>', $svg );
	}

	/**
	 * A doctype — the way an XXE arrives — is removed.
	 */
	public function test_svg_sanitization_strips_doctype() {
		$svg = Pattern_Builder_Assets::sanitize_svg(
			'<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>' .
			'<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'
		);

		$this->assertNotWPError( $svg );
		$this->assertStringNotContainsString( 'DOCTYPE', $svg );
		$this->assertStringNotContainsString( 'ENTITY', $svg );
	}

	/**
	 * Something that is not an SVG at all is refused, not cleaned.
	 */
	public function test_svg_sanitization_refuses_a_non_svg() {
		$result = Pattern_Builder_Assets::sanitize_svg( '<html><body>hello</body></html>' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_asset_not_svg', $result->get_error_code() );
	}

	/**
	 * An SVG stored in the theme is written to assets/images, and the
	 * reference handed back is the PHP template tag a pattern file needs —
	 * not a URL, which would break when the theme moved.
	 */
	public function test_storing_an_svg_in_the_theme_returns_a_template_tag() {
		$stored = Pattern_Builder_Assets::store(
			'mark.svg',
			'<svg xmlns="http://www.w3.org/2000/svg"><rect width="4" height="4"/></svg>',
			'theme'
		);

		$this->assertNotWPError( $stored );
		$this->assertSame( 'theme', $stored['destination'] );
		$this->assertSame( 'mark.svg', $stored['filename'] );
		$this->assertFileExists( $this->theme_dir . '/assets/images/mark.svg' );
		$this->assertSame(
			"<?php echo get_stylesheet_directory_uri() . '/assets/images/mark.svg'; ?>",
			$stored['reference']
		);
	}

	/**
	 * Two files of the same name coexist: a pattern may bring its own
	 * hero.svg to a theme that already has one.
	 */
	public function test_a_name_collision_is_numbered_not_overwritten() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="4" height="4"/></svg>';

		$first  = Pattern_Builder_Assets::store( 'hero.svg', $svg, 'theme' );
		$second = Pattern_Builder_Assets::store( 'hero.svg', $svg, 'theme' );

		$this->assertSame( 'hero.svg', $first['filename'] );
		$this->assertSame( 'hero-2.svg', $second['filename'] );
		$this->assertFileExists( $this->theme_dir . '/assets/images/hero.svg' );
		$this->assertFileExists( $this->theme_dir . '/assets/images/hero-2.svg' );
	}

	/**
	 * SVG is refused for the media library rather than quietly enabling a
	 * site-wide upload type to satisfy one pattern.
	 */
	public function test_svg_is_refused_for_the_media_library() {
		$result = Pattern_Builder_Assets::store(
			'mark.svg',
			'<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
			'media'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_asset_svg_not_media', $result->get_error_code() );
	}

	/**
	 * A type a pattern cannot carry is named in the refusal.
	 */
	public function test_a_type_a_pattern_cannot_carry_is_refused() {
		$result = Pattern_Builder_Assets::store( 'brochure.pdf', 'x', 'theme' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_asset_bad_type', $result->get_error_code() );
	}

	/**
	 * A file whose contents are not the image its name claims is refused —
	 * the name arrived over the wire, the bytes are what count.
	 */
	public function test_contents_are_checked_against_the_name() {
		$result = Pattern_Builder_Assets::store( 'hero.png', 'this is not a png', 'theme' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_asset_type_mismatch', $result->get_error_code() );
	}

	/**
	 * An oversized image is shrunk on the way in. Nothing else resizes a
	 * file written straight into a theme, so without this a pattern ships
	 * whatever came off the camera.
	 */
	public function test_an_oversized_image_is_constrained() {
		$image = imagecreatetruecolor( 3200, 400 );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		$stored = Pattern_Builder_Assets::store( 'wide.png', $png, 'theme' );

		$this->assertNotWPError( $stored );
		$this->assertSame( 2400, $stored['width'] );
		$this->assertSame( 300, $stored['height'] );
	}

	/**
	 * A resized image is left readable by the web server.
	 *
	 * The image editor chmods its output from the temporary directory's own
	 * mode, so a resize in a 0777 temp directory produces a world-writable
	 * file; moving that into the theme carried the mode across and suEXEC
	 * hosts answer 403 for it. Nothing about the stored image's dimensions
	 * or content shows the problem, so the mode is what has to be asserted.
	 */
	public function test_a_resized_image_is_stored_with_a_servable_mode() {
		$image = imagecreatetruecolor( 3200, 400 );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		$stored = Pattern_Builder_Assets::store( 'perms-resized.png', $png, 'theme' );

		$this->assertNotWPError( $stored );

		$path = get_stylesheet_directory() . '/assets/images/' . $stored['filename'];
		$this->assertFileExists( $path );

		$mode = fileperms( $path ) & 0777;
		$this->assertSame(
			0,
			$mode & 0022,
			sprintf( 'A stored image must not be group- or world-writable; got %04o.', $mode )
		);
		$this->assertSame( 0044, $mode & 0044, 'A stored image must be readable by the web server.' );
	}

	/**
	 * An unresized image is stored with the same servable mode.
	 */
	public function test_an_unresized_image_is_stored_with_a_servable_mode() {
		$image = imagecreatetruecolor( 400, 300 );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		$stored = Pattern_Builder_Assets::store( 'perms-plain.png', $png, 'theme' );

		$this->assertNotWPError( $stored );

		$path = get_stylesheet_directory() . '/assets/images/' . $stored['filename'];
		$mode = fileperms( $path ) & 0777;
		$this->assertSame( 0, $mode & 0022, sprintf( 'Got %04o.', $mode ) );
	}

	/**
	 * An image inside the cap is stored as it is.
	 */
	public function test_an_image_within_the_cap_is_untouched() {
		$image = imagecreatetruecolor( 800, 600 );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		$stored = Pattern_Builder_Assets::store( 'small.png', $png, 'theme' );

		$this->assertNotWPError( $stored );
		$this->assertSame( 800, $stored['width'] );
		$this->assertSame( 600, $stored['height'] );
	}

	/**
	 * The add-asset ability records alt text, as the upload route does.
	 *
	 * Both surfaces advertise an `alt`, and the ability used to accept it and
	 * drop it — which reads as success while leaving the attachment with no
	 * alt text at all, so find-media could not match on it either.
	 */
	public function test_alt_text_is_recorded_on_a_media_attachment() {
		$image = imagecreatetruecolor( 400, 300 );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		$stored = Pattern_Builder_Assets::apply_alt(
			Pattern_Builder_Assets::store( 'alt-probe.png', $png, 'media' ),
			'A wood-fired kiln at dusk'
		);

		$this->assertNotWPError( $stored );
		$this->assertArrayHasKey( 'id', $stored );
		$this->assertSame( 'A wood-fired kiln at dusk', $stored['alt'] );
		$this->assertSame(
			'A wood-fired kiln at dusk',
			get_post_meta( $stored['id'], '_wp_attachment_image_alt', true )
		);
	}

	/**
	 * A theme asset has nowhere to keep alt text, and says so by omission.
	 */
	public function test_alt_text_on_a_theme_asset_is_not_claimed() {
		$stored = Pattern_Builder_Assets::apply_alt(
			Pattern_Builder_Assets::store( 'alt-theme.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"/>', 'theme' ),
			'Ignored for a theme file'
		);

		$this->assertNotWPError( $stored );
		$this->assertArrayNotHasKey( 'alt', $stored );
	}

	/**
	 * Alt text is searchable once it is actually stored.
	 */
	public function test_find_matches_on_recorded_alt_text() {
		$image = imagecreatetruecolor( 400, 300 );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		$stored = Pattern_Builder_Assets::apply_alt(
			Pattern_Builder_Assets::store( 'unmatchable-name.png', $png, 'media' ),
			'Glaze tests on a wire rack'
		);
		$this->assertNotWPError( $stored );

		$found = Pattern_Builder_Assets::find( array( 'search' => 'wire rack' ) );
		$ids   = wp_list_pluck( $found['media'], 'id' );

		$this->assertContains( $stored['id'], $ids );
	}

	/**
	 * find() reports the theme's own assets, which no core route lists.
	 */
	public function test_find_reports_the_theme_assets() {
		Pattern_Builder_Assets::store(
			'logo.svg',
			'<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
			'theme'
		);

		$found = Pattern_Builder_Assets::find( array( 'source' => 'theme' ) );

		$this->assertCount( 1, $found['theme'] );
		$this->assertSame( 'logo.svg', $found['theme'][0]['filename'] );
		$this->assertSame( 'assets/images/logo.svg', $found['theme'][0]['path'] );
		$this->assertStringContainsString( 'get_stylesheet_directory_uri', $found['theme'][0]['reference'] );
	}

	/**
	 * The search filters by filename.
	 */
	public function test_find_filters_theme_assets_by_name() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
		Pattern_Builder_Assets::store( 'hero-wide.svg', $svg, 'theme' );
		Pattern_Builder_Assets::store( 'footer-mark.svg', $svg, 'theme' );

		$found = Pattern_Builder_Assets::find(
			array(
				'source' => 'theme',
				'search' => 'hero',
			)
		);

		$this->assertCount( 1, $found['theme'] );
		$this->assertSame( 'hero-wide.svg', $found['theme'][0]['filename'] );
	}

	/**
	 * Make a media library attachment to search for.
	 *
	 * @param string $filename Filename to record.
	 * @param string $title    Attachment title.
	 * @param string $alt      Alternative text.
	 * @return int
	 */
	private function make_attachment( $filename, $title, $alt = '' ) {
		$id = self::factory()->attachment->create_object(
			$filename,
			0,
			array(
				'post_title'     => $title,
				'post_mime_type' => 'image/webp',
				'post_status'    => 'inherit',
			)
		);

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	/**
	 * A search finds an attachment by its filename. Core does not do this by
	 * default, and for media the filename is the likeliest thing to match:
	 * looking for "hero" should turn up hero.webp whatever its title says.
	 */
	public function test_media_is_found_by_filename() {
		$id = $this->make_attachment( 'hero-wide.webp', 'Untitled' );

		$found = Pattern_Builder_Assets::find(
			array(
				'source' => 'media',
				'search' => 'hero',
			)
		);

		$this->assertSame( array( $id ), wp_list_pluck( $found['media'], 'id' ) );
	}

	/**
	 * A search finds an attachment by its alt text, which is where a site
	 * records what an image actually shows — and which `s` never reaches,
	 * because it lives in postmeta.
	 */
	public function test_media_is_found_by_alt_text() {
		$id = $this->make_attachment( 'dsc00417.webp', 'DSC00417', 'A potter trimming a bowl' );

		$found = Pattern_Builder_Assets::find(
			array(
				'source' => 'media',
				'search' => 'potter',
			)
		);

		$this->assertSame( array( $id ), wp_list_pluck( $found['media'], 'id' ) );
		$this->assertSame( 'A potter trimming a bowl', $found['media'][0]['alt'] );
	}

	/**
	 * An attachment matching on both counts is reported once: the two passes
	 * merge by id rather than appending.
	 */
	public function test_media_matching_twice_is_listed_once() {
		$id = $this->make_attachment( 'kiln.webp', 'The kiln', 'The kiln at dusk' );

		$found = Pattern_Builder_Assets::find(
			array(
				'source' => 'media',
				'search' => 'kiln',
			)
		);

		$this->assertSame( array( $id ), wp_list_pluck( $found['media'], 'id' ) );
	}

	/**
	 * A media library result's reference is its URL, which is what a pattern
	 * points at — and what the theme localiser rewrites on save.
	 */
	public function test_a_media_result_references_its_url() {
		$id = $this->make_attachment( 'bowl.webp', 'Bowl' );

		$found = Pattern_Builder_Assets::find( array( 'source' => 'media' ) );

		$this->assertSame( wp_get_attachment_url( $id ), $found['media'][0]['reference'] );
	}

	/**
	 * The route takes a raw body with the filename in Content-Disposition,
	 * which is the shape core's own media endpoint accepts and therefore the
	 * one an agent already knows.
	 */
	public function test_the_route_accepts_a_raw_body() {
		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/assets' );
		$request->set_header( 'content_disposition', 'attachment; filename="banner.svg"' );
		$request->set_header( 'content_type', 'image/svg+xml' );
		$request->set_body( '<svg xmlns="http://www.w3.org/2000/svg"><rect width="8" height="8"/></svg>' );

		$response = rest_do_request( $request );

		$this->assertFalse( $response->is_error(), wp_json_encode( $response->get_data() ) );

		$data = $response->get_data();

		$this->assertSame( 'banner.svg', $data['filename'] );
		$this->assertFileExists( $this->theme_dir . '/assets/images/banner.svg' );
	}

	/**
	 * A body with no filename anywhere is refused with the fix in the
	 * message, rather than being stored under a made-up name.
	 */
	public function test_the_route_needs_a_filename() {
		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/assets' );
		$request->set_body( '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>' );

		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'pb_asset_no_filename', $response->as_error()->get_error_code() );
	}

	/**
	 * An empty body is refused.
	 */
	public function test_the_route_needs_a_body() {
		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/assets' );
		$request->set_header( 'content_disposition', 'attachment; filename="x.svg"' );

		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'pb_asset_empty', $response->as_error()->get_error_code() );
	}

	/**
	 * The route is not open: writing into the theme is the same authority the
	 * pattern routes ask for.
	 */
	public function test_the_route_requires_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/assets' );
		$request->set_header( 'content_disposition', 'attachment; filename="x.svg"' );
		$request->set_body( '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>' );

		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The filename may also be a query argument, for a client that finds
	 * setting the header awkward.
	 */
	public function test_the_route_accepts_a_filename_parameter() {
		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/assets' );
		$request->set_param( 'filename', 'from-param.svg' );
		$request->set_body( '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>' );

		$response = rest_do_request( $request );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 'from-param.svg', $response->get_data()['filename'] );
	}
}
