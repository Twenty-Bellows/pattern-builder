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

	public function test_import_rejects_foreign_asset_hosts() {
		remove_all_filters( 'pattern_builder_cloud_pre_fetch_asset' );

		$pbp = $this->make_downloaded_pbp();
		remove_all_filters( 'pattern_builder_cloud_pre_fetch_asset' );
		$pbp['assets'][0]['url'] = 'https://evil.example/steal.jpg';

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->import_pbp( $pbp, 'user' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_foreign_asset', $result->get_error_code() );
	}
}
