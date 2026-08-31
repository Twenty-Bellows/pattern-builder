<?php

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter;

/**
 * The cloud porter: local pattern ↔ Portable Pattern Package conversion.
 */
class Test_Cloud_Porter extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Create a wp_block user pattern referencing one uploaded image.
	 *
	 * @return array { post_id: int, attachment_url: string }
	 */
	private function make_user_pattern_with_image() {
		$attachment_id  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$attachment_url = wp_get_attachment_url( $attachment_id );

		$content = "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"{$attachment_url}\" alt=\"\"/></figure>\n<!-- /wp:image -->";

		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Cloud Export Test',
				'post_name'    => 'cloud-export-test',
				'post_excerpt' => 'A pattern that travels.',
				'post_content' => $content,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );

		return array(
			'post_id'        => $post_id,
			'attachment_url' => $attachment_url,
		);
	}

	public function test_export_user_pattern_bundles_assets() {
		$fixture = $this->make_user_pattern_with_image();

		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'user', $fixture['post_id'] );

		$this->assertIsArray( $exported );
		$pbp = $exported['pbp'];

		$this->assertSame( 'pbp/1', $pbp['format'] );
		$this->assertSame( 'Cloud Export Test', $pbp['title'] );
		$this->assertSame( 'A pattern that travels.', $pbp['description'] );
		$this->assertSame( 'user', $pbp['origin']['kind'] );

		// The local URL became a placeholder and its file travels along.
		$this->assertStringNotContainsString( $fixture['attachment_url'], $pbp['content'] );
		$this->assertStringContainsString( 'pbp-asset://', $pbp['content'] );
		$this->assertCount( 1, $pbp['assets'] );

		$key = $pbp['assets'][0]['key'];
		$this->assertArrayHasKey( $key, $exported['files'] );
		$this->assertFileExists( $exported['files'][ $key ] );
		$this->assertSame( 'image/jpeg', $pbp['assets'][0]['mime'] );
		$this->assertSame( 'user:' . $fixture['post_id'], $exported['localKey'] );
	}

	/**
	 * Export one wp_block whose content is given, and hand back the package.
	 *
	 * @param string $content Block markup.
	 * @return array|WP_Error
	 */
	private function export_content( $content ) {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Asset Scan',
				'post_content' => $content,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);

		$porter = new Pattern_Builder_Cloud_Porter();
		return $porter->export_local( 'user', $post_id );
	}

	/**
	 * Anything the package leaves pointing at a URL is refused by the
	 * service ("Patterns may only reference images uploaded with them"), so
	 * every reference it checks — src, a block attribute's url, CSS url() —
	 * has to be bundled, however the URL is written.
	 */
	public function test_export_bundles_every_reference_the_service_checks() {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$url           = wp_get_attachment_url( $attachment_id );

		$cases = array(
			'query string' => '<!-- wp:image --><figure><img src="' . $url . '?ver=2" alt=""/></figure><!-- /wp:image -->',
			'https for http' => '<!-- wp:image --><figure><img src="' . str_replace( 'http://', 'https://', $url ) . '" alt=""/></figure><!-- /wp:image -->',
			'css url()' => '<!-- wp:group --><div class="wp-block-group" style="background-image:url(' . $url . ')"></div><!-- /wp:group -->',
			'attribute url' => '<!-- wp:cover {"url":"' . $url . '"} --><div class="wp-block-cover"></div><!-- /wp:cover -->',
		);

		foreach ( $cases as $label => $content ) {
			$exported = $this->export_content( $content );

			$this->assertIsArray( $exported, $label );
			$this->assertCount( 1, $exported['pbp']['assets'], $label );
			$this->assertStringContainsString( 'pbp-asset://', $exported['pbp']['content'], $label );
			$this->assertDoesNotMatchRegularExpression(
				'/(?:src="|"url"\s*:\s*"|url\()https?:/i',
				$exported['pbp']['content'],
				$label
			);
		}
	}

	/**
	 * A link is not an image, wherever the pattern keeps it: an anchor's
	 * href, or a social link's `url` attribute. Neither is the exporter's
	 * business, and neither may block an upload.
	 */
	/**
	 * An attachment id means nothing on another site, so the ids and the
	 * `wp-image-N` classes naming them are dropped on the way out; the image
	 * itself travels in the package.
	 */
	public function test_export_forgets_which_attachment_an_image_was() {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$url           = wp_get_attachment_url( $attachment_id );

		$content = '<!-- wp:image {"id":' . $attachment_id . ',"sizeSlug":"full","linkDestination":"none"} -->'
			. '<figure class="wp-block-image size-full"><img src="' . $url . '" alt="" class="wp-image-' . $attachment_id . '"/></figure>'
			. '<!-- /wp:image -->'
			. '<!-- wp:cover {"url":"' . $url . '","id":' . $attachment_id . ',"dimRatio":50} --><div class="wp-block-cover"></div><!-- /wp:cover -->'
			. '<!-- wp:media-text {"mediaId":' . $attachment_id . ',"mediaType":"image"} --><div class="wp-block-media-text"><figure><img src="' . $url . '" alt="" class="wp-image-' . $attachment_id . '"/></figure></div><!-- /wp:media-text -->';

		$exported = $this->export_content( $content );

		$this->assertIsArray( $exported );
		$package = $exported['pbp']['content'];

		$this->assertStringNotContainsString( '"id":' . $attachment_id, $package );
		$this->assertStringNotContainsString( '"mediaId":' . $attachment_id, $package );
		$this->assertStringNotContainsString( 'wp-image-' . $attachment_id, $package );
		$this->assertStringNotContainsString( 'class=""', $package );

		// Everything else about the blocks survives.
		$this->assertStringContainsString( '"sizeSlug":"full"', $package );
		$this->assertStringContainsString( '"dimRatio":50', $package );
		$this->assertStringContainsString( '"mediaType":"image"', $package );
		$this->assertStringContainsString( 'class="wp-block-image size-full"', $package );
		$this->assertStringContainsString( 'pbp-asset://', $package );
	}

	public function test_export_keeps_a_block_valid_when_only_the_id_was_there() {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$url           = wp_get_attachment_url( $attachment_id );

		$exported = $this->export_content(
			'<!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img src="' . $url . '" alt=""/></figure><!-- /wp:image -->'
			. '<!-- wp:gallery {"ids":[' . $attachment_id . ']} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->'
		);

		$this->assertIsArray( $exported );
		$package = $exported['pbp']['content'];

		$this->assertStringContainsString( '<!-- wp:image -->', $package );
		$this->assertStringContainsString( '<!-- wp:gallery -->', $package );
		$this->assertSame( 2, substr_count( $package, '<!-- /wp:' ) );

		// It still parses back to the same blocks.
		$blocks = array_values(
			array_filter(
				parse_blocks( $package ),
				static function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);
		$this->assertSame( array( 'core/image', 'core/gallery' ), wp_list_pluck( $blocks, 'blockName' ) );
		$this->assertSame( array(), $blocks[0]['attrs'] );
	}

	public function test_export_leaves_links_alone() {
		$content = '<!-- wp:paragraph --><p><a href="https://wordpress.org">View on WordPress.org</a></p><!-- /wp:paragraph -->'
			. '<!-- wp:social-links --><ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://wordpress.org","service":"wordpress"} /--></ul><!-- /wp:social-links -->';

		$exported = $this->export_content( $content );

		$this->assertIsArray( $exported );
		$this->assertSame( array(), $exported['pbp']['assets'] );
		$this->assertStringContainsString( 'href="https://wordpress.org"', $exported['pbp']['content'] );
		$this->assertStringContainsString( '"url":"https://wordpress.org"', $exported['pbp']['content'] );
	}

	public function test_export_names_an_image_hosted_elsewhere() {
		$exported = $this->export_content(
			'<!-- wp:image --><figure><img src="https://images.example.com/photo.jpg" alt=""/></figure><!-- /wp:image -->'
		);

		$this->assertWPError( $exported );
		$this->assertSame( 'pb_cloud_foreign_asset_source', $exported->get_error_code() );
		$this->assertStringContainsString( 'images.example.com/photo.jpg', $exported->get_error_message() );
	}

	public function test_export_names_an_image_type_a_package_cannot_carry() {
		$uploads = wp_get_upload_dir();
		$svg     = $uploads['basedir'] . '/pattern-builder-test.svg';
		file_put_contents( $svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$exported = $this->export_content(
			'<!-- wp:image --><figure><img src="' . $uploads['baseurl'] . '/pattern-builder-test.svg" alt=""/></figure><!-- /wp:image -->'
		);

		unlink( $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertWPError( $exported );
		$this->assertSame( 'pb_cloud_unsupported_asset', $exported->get_error_code() );
		$this->assertStringContainsString( 'pattern-builder-test.svg', $exported->get_error_message() );
	}

	public function test_export_refuses_to_climb_out_of_the_uploads_directory() {
		$exported = $this->export_content(
			'<!-- wp:image --><figure><img src="' . wp_get_upload_dir()['baseurl'] . '/../../../wp-config.php" alt=""/></figure><!-- /wp:image -->'
		);

		$this->assertWPError( $exported );
		$this->assertSame( 'pb_cloud_unresolvable_asset', $exported->get_error_code() );
	}

	public function test_export_missing_pattern_errors() {
		$porter = new Pattern_Builder_Cloud_Porter();

		$this->assertWPError( $porter->export_local( 'user', 999999 ) );
		$this->assertWPError( $porter->export_local( 'theme', 'no-theme/nope' ) );
	}

	/**
	 * A downloadable PBP whose asset resolves to a local test image (the
	 * pre-fetch filter stands in for the service download).
	 *
	 * @return array
	 */
	private function make_downloaded_pbp() {
		add_filter(
			'pattern_builder_cloud_pre_fetch_asset',
			static function () {
				return DIR_TESTDATA . '/images/canola.jpg';
			}
		);

		return array(
			'format'      => 'pbp/1',
			'title'       => 'Downloaded Hero',
			'slug'        => 'downloaded-hero',
			'description' => 'From the cloud.',
			'categories'  => array( 'Heroes', 'Featured' ),
			'synced'      => false,
			'content'     => "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"pbp-asset://hero-img\" alt=\"\"/></figure>\n<!-- /wp:image -->",
			'assets'      => array(
				array(
					'key'      => 'hero-img',
					'mime'     => 'image/jpeg',
					'filename' => 'hero.jpg',
					'url'      => 'https://patternbuilderwp.com/wp-content/uploads/hero.jpg',
				),
			),
		);
	}

	public function test_import_as_user_pattern() {
		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $this->make_downloaded_pbp(), 'user' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'user', $result['type'] );

		$post = get_post( $result['id'] );
		$this->assertSame( 'wp_block', $post->post_type );
		$this->assertSame( 'Downloaded Hero', $post->post_title );
		$this->assertSame( 'unsynced', get_post_meta( $post->ID, 'wp_pattern_sync_status', true ) );

		// The placeholder became a local media-library URL.
		$this->assertStringNotContainsString( 'pbp-asset:', $post->post_content );
		$uploads = wp_get_upload_dir();
		$this->assertStringContainsString( $uploads['baseurl'], $post->post_content );

		// Categories landed as wp_pattern_category terms.
		$terms = wp_get_object_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'names' ) );
		$this->assertEqualSets( array( 'Heroes', 'Featured' ), $terms );
	}

	/**
	 * The export drops attachment ids because they mean nothing elsewhere;
	 * a user pattern's images do land in this site's media library, so the
	 * blocks are pointed back at them in local terms.
	 */
	public function test_import_names_the_attachments_it_created_for_a_user_pattern() {
		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $this->make_downloaded_pbp(), 'user' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$content = get_post( $result['id'] )->post_content;

		$this->assertMatchesRegularExpression( '/<!-- wp:image \{[^}]*"id":\d+/', $content );

		preg_match( '/"id":(\d+)/', $content, $id );
		$this->assertSame( 'attachment', get_post_type( (int) $id[1] ) );
		$this->assertStringContainsString( 'wp-image-' . $id[1], $content );

		// And it names the attachment the image actually shows.
		$this->assertStringContainsString( wp_get_attachment_url( (int) $id[1] ), $content );
	}

	/**
	 * A theme pattern's images are moved into the theme's own assets
	 * directory and referenced from there, so there is no attachment for a
	 * block to name.
	 */
	public function test_import_leaves_a_theme_pattern_without_attachment_ids() {
		// A writable theme directory, as test_import_as_theme_pattern_writes_file does.
		$test_dir = sys_get_temp_dir() . '/pattern-builder-cloud-test';
		if ( ! is_dir( $test_dir . '/patterns' ) ) {
			mkdir( $test_dir, 0777, true );
			mkdir( $test_dir . '/patterns' );
		}
		$dir_filter = static function () use ( $test_dir ) {
			return $test_dir;
		};
		add_filter( 'stylesheet_directory', $dir_filter );

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $this->make_downloaded_pbp(), 'theme' );

		remove_filter( 'stylesheet_directory', $dir_filter );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$file = $test_dir . '/patterns/downloaded-hero.php';
		$this->assertFileExists( $file );

		$written = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$this->assertDoesNotMatchRegularExpression( '/"id":\d+/', $written );
		$this->assertStringNotContainsString( 'wp-image-', $written );

		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_import_as_theme_pattern_writes_file() {
		// Stand in a writable theme directory, the way the REST API tests do.
		$test_dir = sys_get_temp_dir() . '/pattern-builder-cloud-test';
		if ( ! is_dir( $test_dir . '/patterns' ) ) {
			mkdir( $test_dir, 0777, true );
			mkdir( $test_dir . '/patterns' );
		}
		$dir_filter  = static function () use ( $test_dir ) {
			return $test_dir;
		};
		$slug_filter = static function () {
			return 'simple-theme';
		};
		add_filter( 'stylesheet_directory', $dir_filter );
		add_filter( 'stylesheet', $slug_filter );

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $this->make_downloaded_pbp(), 'theme' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'theme', $result['type'] );
		$this->assertSame( 'simple-theme/downloaded-hero', $result['id'] );

		$file = $test_dir . '/patterns/downloaded-hero.php';
		$this->assertFileExists( $file );

		$contents = file_get_contents( $file );
		$this->assertStringContainsString( 'Title: Downloaded Hero', $contents );
		$this->assertStringNotContainsString( 'pbp-asset:', $contents );

		unlink( $file );
		remove_filter( 'stylesheet_directory', $dir_filter );
		remove_filter( 'stylesheet', $slug_filter );
	}

	public function test_import_rejects_unsafe_content() {
		$pbp            = $this->make_downloaded_pbp();
		$pbp['content'] = "<!-- wp:paragraph -->\n<p><a href=\"javascript:alert(1)\">x</a></p>\n<!-- /wp:paragraph -->";
		$pbp['assets']  = array();

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $pbp, 'user' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_unsafe_content', $result->get_error_code() );
	}

	public function test_import_strips_scripts() {
		$pbp            = $this->make_downloaded_pbp();
		$pbp['content'] = "<!-- wp:paragraph -->\n<p>hello <script>alert(1)</script>there</p>\n<!-- /wp:paragraph -->";
		$pbp['assets']  = array();

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $pbp, 'user' );

		$this->assertIsArray( $result );
		$content = get_post( $result['id'] )->post_content;
		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringContainsString( 'there', $content );
	}

	public function test_import_fails_on_undelivered_asset() {
		$pbp           = $this->make_downloaded_pbp();
		$pbp['assets'] = array();

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $pbp, 'user' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_missing_asset', $result->get_error_code() );
	}

	public function test_import_fetches_assets_from_the_configured_origin_only() {
		remove_all_filters( 'pattern_builder_cloud_pre_fetch_asset' );

		$pbp = $this->make_downloaded_pbp();
		remove_all_filters( 'pattern_builder_cloud_pre_fetch_asset' );
		// The package names a foreign host; the path must be re-rooted onto
		// the configured service origin, never fetched where it points.
		$pbp['assets'][0]['url'] = 'https://evil.example/steal.jpg?sig=abc';

		$fetched = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$fetched ) {
				$fetched[] = $url;
				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'body'     => '',
				);
			},
			10,
			3
		);

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $pbp, 'user' );
		remove_all_filters( 'pre_http_request' );

		// The 404 on the service surfaces as a failed asset download…
		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_asset_failed', $result->get_error_code() );

		// …and the one request went to the service origin with the
		// package's path, not to the foreign host.
		$this->assertCount( 1, $fetched );
		$service_host = wp_parse_url( \TwentyBellows\PatternBuilder\Pattern_Builder_Cloud::service_url(), PHP_URL_HOST );
		$this->assertSame( $service_host, wp_parse_url( $fetched[0], PHP_URL_HOST ) );
		$this->assertSame( '/steal.jpg', wp_parse_url( $fetched[0], PHP_URL_PATH ) );
	}

	public function test_import_rejects_unfetchable_asset_urls() {
		remove_all_filters( 'pattern_builder_cloud_pre_fetch_asset' );

		$pbp = $this->make_downloaded_pbp();
		remove_all_filters( 'pattern_builder_cloud_pre_fetch_asset' );
		$pbp['assets'][0]['url'] = 'data:image/png;base64,AAAA';

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $pbp, 'user' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_foreign_asset', $result->get_error_code() );
	}
}
