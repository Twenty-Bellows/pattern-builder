<?php

use TwentyBellows\PatternBuilder\Abstract_Pattern;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter;
use TwentyBellows\PatternBuilder\Pattern_File_Store;

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

	/**
	 * A user pattern with no image, carrying whatever origin the test wants.
	 *
	 * @param string $origin The origin to record.
	 * @return int The post ID.
	 */
	private function make_user_pattern_with_origin( $origin ) {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Has an origin',
				'post_name'    => 'has-an-origin',
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $post_id, \TwentyBellows\PatternBuilder\Pattern_File_Store::META_ORIGIN, $origin );

		return $post_id;
	}

	/**
	 * The service resolves an origin to a pattern page and links it, so only a name it can
	 * hold travels. A cloud name is bare; anything else carries a scheme and stays here.
	 */
	public function test_export_sends_a_cloud_origin() {
		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'user', $this->make_user_pattern_with_origin( 'studio-a/heroes/hero' ) );

		$this->assertSame( 'studio-a/heroes/hero', $exported['pbp']['origin']['pattern'] );
	}

	public function test_export_keeps_a_plugin_origin_off_the_wire() {
		$porter   = new Pattern_Builder_Cloud_Porter();
		$exported = $porter->export_local( 'user', $this->make_user_pattern_with_origin( 'plugin:twentig/hero' ) );

		$this->assertSame( '', $exported['pbp']['origin']['pattern'] );
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
		$this->assertStringNotContainsString( $fixture['attachment_url'], $pbp['content'] );
		$this->assertStringContainsString( 'pbp-asset://', $pbp['content'] );
		$this->assertCount( 1, $pbp['assets'] );

		$key = $pbp['assets'][0]['key'];
		$this->assertArrayHasKey( $key, $exported['files'] );
		$this->assertFileExists( $exported['files'][ $key ] );
		$this->assertSame( 'image/jpeg', $pbp['assets'][0]['mime'] );
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
	 * Anything the package leaves pointing at a URL is refused by the service ("Patterns
	 * may only reference images uploaded with them"), so every reference it checks — src, a
	 * block attribute's url, CSS url() — has to be bundled, however the URL is written.
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
	 * A link is not an image, wherever the pattern keeps it: an anchor's href, or a social
	 * link's `url` attribute.
	 */
	/**
	 * An attachment id means nothing on another site, so the ids and the `wp-image-N`
	 * classes naming them are dropped on the way out; the image itself travels in the
	 * package.
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

	/**
	 * A link to a page of this site means that page wherever the pattern lands, so it
	 * travels as the home plus the path, in an href or a block's url attribute.
	 */
	public function test_export_carries_a_link_to_this_site_as_home() {
		$home     = untrailingslashit( home_url() );
		$exported = $this->export_content(
			'<!-- wp:paragraph --><p><a href="' . $home . '/about/#team">About</a> <a href="' . $home . '">Home</a></p><!-- /wp:paragraph -->'
			. '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Contact","url":"' . $home . '/contact/"} /--><!-- /wp:navigation -->'
		);

		$this->assertIsArray( $exported );
		$this->assertStringContainsString( 'href="pbp-home:/about/#team"', $exported['pbp']['content'] );
		$this->assertStringContainsString( 'href="pbp-home:"', $exported['pbp']['content'] );
		$this->assertStringContainsString( '"url":"pbp-home:/contact/"', $exported['pbp']['content'] );
		$this->assertStringNotContainsString( $home, $exported['pbp']['content'] );
	}

	/**
	 * The export takes the links a theme save turns into home_url(), and only those.
	 */
	public function test_export_leaves_other_links_to_this_site_as_written() {
		$home  = untrailingslashit( home_url() );
		$links = array(
			$home . '/wp-admin/',
			$home . '/wp-content/uploads/menu.pdf',
			$home . '/?p=12',
		);

		$exported = $this->export_content(
			'<!-- wp:paragraph --><p><a href="' . implode( '">x</a> <a href="', $links ) . '">x</a></p><!-- /wp:paragraph -->'
		);

		$this->assertIsArray( $exported );
		foreach ( $links as $link ) {
			$this->assertStringContainsString( 'href="' . $link . '"', $exported['pbp']['content'] );
		}
		$this->assertStringNotContainsString( 'pbp-home:', $exported['pbp']['content'] );
	}

	/**
	 * A theme pattern is exported as it renders, so its home_url() calls arrive as this
	 * site's address and leave as home.
	 */
	public function test_export_carries_a_theme_patterns_home_url_call_as_home() {
		$patterns = $this->use_a_temporary_theme();
		$saved    = ( new Pattern_File_Store() )->update_theme_pattern(
			new Abstract_Pattern(
				array(
					'title'   => 'Header',
					'name'    => 'simple-theme/header',
					'content' => '<!-- wp:paragraph --><p><a href="<?php echo esc_url( home_url( \'/shop/\' ) ); ?>">Shop</a></p><!-- /wp:paragraph -->'
						. '<!-- wp:navigation --><!-- wp:navigation-link {"label":"About","url":"<?php echo esc_url( home_url( \'/about/\' ) ); ?>"} /--><!-- /wp:navigation -->',
				)
			)
		);
		$this->assertNotWPError( $saved );
		$this->assertStringContainsString( "home_url( '/shop/' )", file_get_contents( $patterns . '/header.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$exported = ( new Pattern_Builder_Cloud_Porter() )->export_local( 'theme', 'simple-theme/header' );

		$this->assertIsArray( $exported, is_wp_error( $exported ) ? $exported->get_error_message() : '' );
		$this->assertStringContainsString( 'href="pbp-home:/shop/"', $exported['pbp']['content'] );
		$this->assertStringContainsString( '"url":"pbp-home:/about/"', $exported['pbp']['content'] );
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
	 * Makes an empty temporary directory the active theme, so a test writes its theme
	 * patterns there rather than into the suite's fixture theme.
	 *
	 * @return string The theme's patterns directory.
	 */
	private function use_a_temporary_theme() {
		$theme = sys_get_temp_dir() . '/pattern-builder-home-links-test';
		if ( ! is_dir( $theme . '/patterns' ) ) {
			mkdir( $theme . '/patterns', 0777, true );
		}
		foreach ( (array) glob( $theme . '/patterns/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$dir  = static function () use ( $theme ) {
			return $theme;
		};
		$slug = static function () {
			return 'simple-theme';
		};
		add_filter( 'stylesheet_directory', $dir );
		add_filter( 'template_directory', $dir );
		add_filter( 'stylesheet', $slug );
		add_filter( 'template', $slug );

		return $theme . '/patterns';
	}

	/**
	 * A downloadable PBP carrying links to the home it lands on, and no assets.
	 *
	 * @return array
	 */
	private function make_downloaded_pbp_with_home_links() {
		$pbp            = $this->make_downloaded_pbp();
		$pbp['content'] = '<!-- wp:paragraph --><p><a href="pbp-home:/about/#team">About</a> <a href="pbp-home:">Home</a></p><!-- /wp:paragraph -->'
			. '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Contact","url":"pbp-home:/contact/"} /--><!-- /wp:navigation -->';
		$pbp['assets']  = array();

		return $pbp;
	}

	/**
	 * A downloadable PBP whose asset resolves to a local test image (the pre-fetch filter
	 * stands in for the service download).
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
			'inserterCategories' => array( 'Heroes', 'Featured' ),
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
		$this->assertStringNotContainsString( 'pbp-asset:', $post->post_content );
		$uploads = wp_get_upload_dir();
		$this->assertStringContainsString( $uploads['baseurl'], $post->post_content );
		$terms = wp_get_object_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'names' ) );
		$this->assertEqualSets( array( 'Heroes', 'Featured' ), $terms );
	}

	/**
	 * The export drops attachment ids because they mean nothing elsewhere; a user pattern's
	 * images do land in this site's media library, so the blocks are pointed back at them
	 * in local terms.
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
		$this->assertStringContainsString( wp_get_attachment_url( (int) $id[1] ), $content );
	}

	/**
	 * A theme pattern's images are moved into the theme's own assets directory and
	 * referenced from there, so there is no attachment for a block to name.
	 */
	public function test_import_leaves_a_theme_pattern_without_attachment_ids() {
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

	public function test_a_download_lands_under_its_own_namespace() {
		$test_dir = sys_get_temp_dir() . '/pattern-builder-namespace-test';
		if ( ! is_dir( $test_dir . '/patterns' ) ) {
			mkdir( $test_dir . '/patterns', 0777, true );
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
		$first          = $this->make_downloaded_pbp();
		$first['slug']  = 'hero';
		$first['title'] = 'Studio A Hero';
		$first['namespace'] = 'studio-a/heroes/hero';

		$second              = $this->make_downloaded_pbp();
		$second['slug']      = 'hero';
		$second['title']     = 'Studio B Hero';
		$second['namespace'] = 'studio-b/heroes/hero';

		$a = $porter->import_pbp( $first, 'theme' );
		$b = $porter->import_pbp( $second, 'theme' );

		$this->assertIsArray( $a, is_wp_error( $a ) ? $a->get_error_message() : '' );
		$this->assertIsArray( $b, is_wp_error( $b ) ? $b->get_error_message() : '' );
		$this->assertSame( 'studio-a/heroes/hero', $a['id'] );
		$this->assertSame( 'studio-b/heroes/hero', $b['id'] );

		$file_a = $test_dir . '/patterns/studio-a/heroes/hero.php';
		$file_b = $test_dir . '/patterns/studio-b/heroes/hero.php';
		$this->assertFileExists( $file_a );
		$this->assertFileExists( $file_b );
		$this->assertStringContainsString( 'Title: Studio A Hero', file_get_contents( $file_a ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$this->assertStringContainsString( 'Title: Studio B Hero', file_get_contents( $file_b ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$store = new \TwentyBellows\PatternBuilder\Pattern_File_Store();
		$names = wp_list_pluck( $store->get_theme_patterns(), 'name' );
		$this->assertContains( 'studio-a/heroes/hero', $names );
		$this->assertContains( 'studio-b/heroes/hero', $names );

		unlink( $file_a ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $file_b ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		remove_filter( 'stylesheet_directory', $dir_filter );
		remove_filter( 'stylesheet', $slug_filter );
	}

	public function test_a_package_without_a_namespace_lands_in_the_theme() {
		$test_dir = sys_get_temp_dir() . '/pattern-builder-namespace-test';
		if ( ! is_dir( $test_dir . '/patterns' ) ) {
			mkdir( $test_dir . '/patterns', 0777, true );
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
		$this->assertSame( 'simple-theme/downloaded-hero', $result['id'] );
		$this->assertFileExists( $test_dir . '/patterns/downloaded-hero.php' );

		unlink( $test_dir . '/patterns/downloaded-hero.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		remove_filter( 'stylesheet_directory', $dir_filter );
		remove_filter( 'stylesheet', $slug_filter );
	}

	/**
	 * A user pattern cannot run home_url(), so its links land as this site's address: what
	 * the editor's own link control writes, and what a re-upload recognizes as home again.
	 */
	public function test_import_lands_a_home_link_on_this_site() {
		$result = ( new Pattern_Builder_Cloud_Porter() )->import_pbp( $this->make_downloaded_pbp_with_home_links(), 'user' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$content = get_post( $result['id'] )->post_content;
		$this->assertStringContainsString( 'href="' . home_url( '/about/#team' ) . '"', $content );
		$this->assertStringContainsString( 'href="' . home_url() . '"', $content );
		$this->assertStringContainsString( '"url":"' . home_url( '/contact/' ) . '"', $content );
		$this->assertStringNotContainsString( 'pbp-home:', $content );
	}

	/**
	 * A block's attributes can come back from the service with their slashes escaped, and
	 * a home link in them is still read.
	 */
	public function test_import_reads_a_home_link_whose_slashes_are_escaped() {
		$pbp            = $this->make_downloaded_pbp_with_home_links();
		$pbp['content'] = '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Contact","url":"pbp-home:\\/contact\\/"} /--><!-- /wp:navigation -->';

		$result = ( new Pattern_Builder_Cloud_Porter() )->import_pbp( $pbp, 'user' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$content = get_post( $result['id'] )->post_content;
		$this->assertStringContainsString( home_url( '/contact/' ), $content );
		$this->assertStringNotContainsString( 'pbp-home:', $content );
	}

	/**
	 * A theme pattern gets the call back, so the link follows the theme too.
	 */
	public function test_import_writes_a_home_link_into_a_theme_pattern_as_the_call() {
		$patterns = $this->use_a_temporary_theme();

		$result = ( new Pattern_Builder_Cloud_Porter() )->import_pbp( $this->make_downloaded_pbp_with_home_links(), 'theme' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$file = file_get_contents( $patterns . '/downloaded-hero.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( "href=\"<?php echo esc_url( home_url( '/about/#team' ) ); ?>\"", $file );
		$this->assertStringContainsString( 'href="<?php echo esc_url( home_url() ); ?>"', $file );
		$this->assertStringContainsString( "\"url\":\"<?php echo esc_url( home_url( '/contact/' ) ); ?>\"", $file );
		$this->assertStringNotContainsString( 'pbp-home:', $file );
	}

	/**
	 * The point of it all: exported from one site, a link lands on another as that site's
	 * page, including under a home in a subdirectory.
	 */
	public function test_a_home_link_follows_the_pattern_to_another_site() {
		$exported = $this->export_content(
			'<!-- wp:paragraph --><p><a href="' . home_url( '/about/' ) . '">About</a></p><!-- /wp:paragraph -->'
		);
		$this->assertIsArray( $exported );

		add_filter(
			'pre_option_home',
			static function () {
				return 'https://elsewhere.test/blog';
			}
		);
		$result = ( new Pattern_Builder_Cloud_Porter() )->import_pbp( $exported['pbp'], 'user' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringContainsString( 'href="https://elsewhere.test/blog/about/"', get_post( $result['id'] )->post_content );
	}

	/**
	 * The export only writes paths a home_url() call can carry. Anything else is a package
	 * this site did not make, and nothing of it is written.
	 */
	public function test_import_refuses_a_home_link_it_cannot_read() {
		$patterns = $this->use_a_temporary_theme();
		$porter   = new Pattern_Builder_Cloud_Porter();

		foreach ( array( "pbp-home:/a'.phpinfo().'/", 'pbp-home:/?p=12', 'pbp-home:/wp-admin/' ) as $link ) {
			$pbp            = $this->make_downloaded_pbp_with_home_links();
			$pbp['content'] = '<!-- wp:paragraph --><p><a href="' . $link . '">x</a></p><!-- /wp:paragraph -->';

			$result = $porter->import_pbp( $pbp, 'theme' );

			$this->assertWPError( $result, $link );
			$this->assertSame( 'pb_cloud_bad_home_link', $result->get_error_code(), $link );
			$this->assertFileDoesNotExist( $patterns . '/downloaded-hero.php', $link );
		}
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
		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_asset_failed', $result->get_error_code() );
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

	/**
	 * Stand in a writable theme directory for the duration of a test.
	 *
	 * @param string $handle The connected account's handle, or '' for none.
	 * @return array { dir: string, cleanup: callable }
	 */
	private function in_a_theme( $handle = '' ) {
		$dir = sys_get_temp_dir() . '/pattern-builder-origin-test';
		if ( ! is_dir( $dir . '/patterns' ) ) {
			mkdir( $dir . '/patterns', 0777, true );
		}

		$dir_filter  = static function () use ( $dir ) {
			return $dir;
		};
		$slug_filter = static function () {
			return 'simple-theme';
		};
		add_filter( 'stylesheet_directory', $dir_filter );
		add_filter( 'stylesheet', $slug_filter );

		if ( '' !== $handle ) {
			update_user_meta(
				get_current_user_id(),
				\TwentyBellows\PatternBuilder\Pattern_Builder_Cloud::META_ACCOUNT,
				array(
					'id'     => 7,
					'handle' => $handle,
				)
			);
		}

		return array(
			'dir'     => $dir,
			'cleanup' => static function () use ( $dir_filter, $slug_filter ) {
				remove_filter( 'stylesheet_directory', $dir_filter );
				remove_filter( 'stylesheet', $slug_filter );
			},
		);
	}

	public function test_a_pattern_from_another_account_is_stamped_with_its_name() {
		$theme  = $this->in_a_theme( 'studio-b' );
		$porter = new Pattern_Builder_Cloud_Porter();

		$pbp              = $this->make_downloaded_pbp();
		$pbp['namespace'] = 'studio-a/heroes/downloaded-hero';

		$result = $porter->import_pbp( $pbp, 'theme' );
		$theme['cleanup']();

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$file = $theme['dir'] . '/patterns/studio-a/heroes/downloaded-hero.php';
		$this->assertFileExists( $file );
		$this->assertStringContainsString( 'Origin: studio-a/heroes/downloaded-hero', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$pattern = \TwentyBellows\PatternBuilder\Abstract_Pattern::from_file( $file );
		$this->assertSame( 'studio-a/heroes/downloaded-hero', $pattern->origin );

		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_an_origin_already_on_the_package_is_carried_unchanged() {
		$theme  = $this->in_a_theme( 'studio-c' );
		$porter = new Pattern_Builder_Cloud_Porter();
		$pbp              = $this->make_downloaded_pbp();
		$pbp['namespace'] = 'studio-b/mine/downloaded-hero';
		$pbp['origin']    = array( 'pattern' => 'studio-a/heroes/downloaded-hero' );

		$result = $porter->import_pbp( $pbp, 'theme' );
		$theme['cleanup']();

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$file = $theme['dir'] . '/patterns/studio-b/mine/downloaded-hero.php';
		$this->assertStringContainsString( 'Origin: studio-a/heroes/downloaded-hero', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_your_own_pattern_coming_home_is_not_stamped() {
		$theme  = $this->in_a_theme( 'studio-a' );
		$porter = new Pattern_Builder_Cloud_Porter();

		$pbp              = $this->make_downloaded_pbp();
		$pbp['namespace'] = 'studio-a/heroes/downloaded-hero';

		$result = $porter->import_pbp( $pbp, 'theme' );
		$theme['cleanup']();

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$file = $theme['dir'] . '/patterns/studio-a/heroes/downloaded-hero.php';
		$this->assertStringNotContainsString( 'Origin:', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_a_user_pattern_records_its_origin_as_meta() {
		update_user_meta(
			get_current_user_id(),
			\TwentyBellows\PatternBuilder\Pattern_Builder_Cloud::META_ACCOUNT,
			array(
				'id'     => 7,
				'handle' => 'studio-b',
			)
		);

		$porter           = new Pattern_Builder_Cloud_Porter();
		$pbp              = $this->make_downloaded_pbp();
		$pbp['namespace'] = 'studio-a/heroes/downloaded-hero';

		$result = $porter->import_pbp( $pbp, 'user' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			'studio-a/heroes/downloaded-hero',
			get_post_meta( $result['id'], \TwentyBellows\PatternBuilder\Pattern_File_Store::META_ORIGIN, true )
		);
	}

	/**
	 * A package says which WordPress it needs.
	 */
	public function test_version_problem_compares_against_this_site() {
		$here = Pattern_Builder_Cloud_Porter::wordpress_version();
		$this->assertNull( Pattern_Builder_Cloud_Porter::version_problem( array() ) );
		$this->assertNull( Pattern_Builder_Cloud_Porter::version_problem( array( 'minWordPress' => '' ) ) );
		$this->assertNull( Pattern_Builder_Cloud_Porter::version_problem( array( 'minWordPress' => $here ) ) );
		$this->assertNull( Pattern_Builder_Cloud_Porter::version_problem( array( 'minWordPress' => '5.0' ) ) );

		$problem = Pattern_Builder_Cloud_Porter::version_problem( array( 'minWordPress' => '99.0' ) );
		$this->assertWPError( $problem );
		$this->assertSame( 'pb_cloud_needs_newer_wordpress', $problem->get_error_code() );
		$data = $problem->get_error_data();
		$this->assertSame( '99.0', $data['minWordPress'] );
		$this->assertSame( $here, $data['wordPress'] );
	}

	/**
	 * A release candidate sorts *below* the release it leads to, so a site on 7.2-RC1 must
	 * not be told it is too old for a 7.2 pattern.
	 */
	public function test_a_release_suffix_does_not_count_as_older() {
		$version = Pattern_Builder_Cloud_Porter::wordpress_version();
		$this->assertMatchesRegularExpression( '/^\d+(\.\d+)*$/', $version );
		$this->assertStringStartsWith( $version, (string) get_bloginfo( 'version' ) );

		$this->assertNull(
			Pattern_Builder_Cloud_Porter::version_problem( array( 'minWordPress' => $version ) )
		);
	}

	/**
	 * And the gate runs before anything is written, so a refusal leaves nothing half-
	 * applied.
	 */
	public function test_installing_a_pattern_this_site_is_too_old_for_is_refused() {
		$pbp                 = $this->make_downloaded_pbp();
		$pbp['minWordPress'] = '99.0';

		$before = count( get_posts( array( 'post_type' => 'wp_block', 'post_status' => 'any', 'fields' => 'ids' ) ) );

		add_filter(
			'pre_http_request',
			static function () use ( $pbp ) {
				return array(
					'headers'  => array(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $pbp ),
				);
			}
		);

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->install_cloud_pattern( 42, 'user', false );
		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_needs_newer_wordpress', $result->get_error_code() );

		$after = count( get_posts( array( 'post_type' => 'wp_block', 'post_status' => 'any', 'fields' => 'ids' ) ) );
		$this->assertSame( $before, $after, 'A refused install still created a pattern.' );
	}
}
