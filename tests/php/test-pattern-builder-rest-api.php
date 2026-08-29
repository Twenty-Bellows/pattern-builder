<?php
/**
 * Integration tests for the Pattern Builder REST API.
 *
 * Theme patterns are file-backed entities under /pattern-builder/v1/patterns
 * with string IDs (their namespaced pattern name). These tests exercise the
 * whole surface: listing, reading, writing files, metadata round-trips,
 * conversions in both directions, deletion, and permissions.
 *
 * @package Pattern_Builder
 */

class Pattern_Builder_REST_API_Test extends WP_UnitTestCase {

	private $test_dir;

	private $admin_id;

	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Remove all existing wp_block posts.
		$all_wp_block_posts = get_posts(
			array(
				'post_type'   => 'wp_block',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);
		foreach ( $all_wp_block_posts as $post ) {
			wp_delete_post( $post->ID, true );
		}

		// Create a temporary directory for the test patterns.
		$this->test_dir = sys_get_temp_dir() . '/pattern-builder-test';
		$this->remove_test_directory( $this->test_dir );
		mkdir( $this->test_dir );
		mkdir( $this->test_dir . '/patterns' );

		add_filter( 'stylesheet_directory', array( $this, 'get_test_directory' ) );
		add_filter( 'should_load_remote_block_patterns', '__return_false' );
		add_filter( 'stylesheet', array( $this, 'get_test_stylesheet' ) );
	}

	public function tearDown(): void {
		$this->remove_test_directory( $this->test_dir );
		remove_filter( 'stylesheet_directory', array( $this, 'get_test_directory' ) );
		remove_filter( 'stylesheet', array( $this, 'get_test_stylesheet' ) );
		parent::tearDown();
	}

	private function remove_test_directory( $dir ) {
		if ( is_dir( $dir ) ) {
			$files = array_diff( scandir( $dir ), array( '.', '..' ) );
			foreach ( $files as $file ) {
				( is_dir( "$dir/$file" ) ) ? $this->remove_test_directory( "$dir/$file" ) : unlink( "$dir/$file" );
			}
			rmdir( $dir );
		}
	}

	public function get_test_directory() {
		return $this->test_dir;
	}

	public function get_test_stylesheet() {
		return 'simple-theme';
	}

	private function copy_test_pattern( $pattern_file ) {
		copy(
			__DIR__ . '/../../dev-assets/themes/simple-theme/patterns/' . $pattern_file,
			$this->test_dir . '/patterns/' . $pattern_file
		);
	}

	private function create_rest_request( $method, $route ) {
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		$request    = new WP_REST_Request( $method, $route );
		if ( in_array( $method, array( 'PUT', 'POST', 'DELETE' ), true ) ) {
			$request->set_header( 'X-WP-Nonce', $rest_nonce );
		}
		return $request;
	}

	private function find_in_list( $data, $id ) {
		foreach ( $data as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	// TESTS ////////////////////////////////////////////////////

	public function test_list_returns_synced_theme_pattern() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );

		$request  = $this->create_rest_request( 'GET', '/pattern-builder/v1/patterns' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$pattern = $this->find_in_list( $response->get_data(), 'simple-theme/theme-synced-pattern' );

		$this->assertNotNull( $pattern );
		$this->assertEquals( 'Theme Synced Pattern', $pattern['title']['raw'] );
		$this->assertEquals( 'simple-theme/theme-synced-pattern', $pattern['name'] );
		$this->assertEquals( 'theme', $pattern['source'] );
		$this->assertEquals( 'pb_pattern', $pattern['type'] );
		$this->assertTrue( $pattern['synced'] );
		$this->assertContains( 'text', $pattern['categories'] );
		$this->assertNotEmpty( $pattern['content']['raw'] );
	}

	public function test_list_returns_user_patterns_with_numeric_ids() {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'My User Pattern',
				'post_name'    => 'my-user-pattern',
				'post_content' => '<!-- wp:paragraph --><p>User content</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);

		$request  = $this->create_rest_request( 'GET', '/pattern-builder/v1/patterns' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$pattern = $this->find_in_list( $response->get_data(), $post_id );

		$this->assertNotNull( $pattern );
		$this->assertEquals( 'user', $pattern['source'] );
		$this->assertEquals( 'wp_block', $pattern['type'] );
		$this->assertEquals( 'My User Pattern', $pattern['title']['raw'] );
	}

	public function test_get_single_theme_pattern() {
		$this->copy_test_pattern( 'theme_unsynced_pattern.php' );

		$request  = $this->create_rest_request( 'GET', '/pattern-builder/v1/patterns/simple-theme/theme-unsynced-pattern' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertEquals( 'simple-theme/theme-unsynced-pattern', $data['id'] );
		$this->assertEquals( 'publish', $data['status'] );
		$this->assertFalse( $data['synced'] );
		$this->assertTrue( $data['inserter'] );
		$this->assertStringContainsString( 'wp:group', $data['content']['raw'] );

		// The editor's save button reads the publish action link off the
		// record; without it, it degrades to "Submit for Review".
		$this->assertArrayHasKey( '_links', $data );
		$this->assertArrayHasKey( 'wp:action-publish', $data['_links'] );
	}

	public function test_get_unknown_pattern_is_404() {
		$request  = $this->create_rest_request( 'GET', '/pattern-builder/v1/patterns/simple-theme/no-such-pattern' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	public function test_update_writes_the_pattern_file() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params(
			array(
				'title'       => 'Updated Title',
				'description' => 'Updated description',
				'content'     => '<!-- wp:paragraph --><p>Updated content</p><!-- /wp:paragraph -->',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'Updated Title', $data['title']['raw'] );
		$this->assertEquals( 'Updated description', $data['description'] );
		$this->assertStringContainsString( 'Updated content', $data['content']['raw'] );

		$file_contents = file_get_contents( $this->test_dir . '/patterns/theme_synced_pattern.php' );
		$this->assertStringContainsString( 'Title: Updated Title', $file_contents );
		$this->assertStringContainsString( 'Description: Updated description', $file_contents );
		$this->assertStringContainsString( 'Updated content', $file_contents );
		$this->assertStringContainsString( 'Synced: yes', $file_contents );
	}

	public function test_update_round_trips_all_metadata() {
		$this->copy_test_pattern( 'theme_unsynced_pattern.php' );

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-unsynced-pattern' );
		$request->set_body_params(
			array(
				'categories'    => array( 'featured', 'banner' ),
				'keywords'      => array( 'hero', 'landing' ),
				'blockTypes'    => array( 'core/post-content' ),
				'postTypes'     => array( 'page' ),
				'templateTypes' => array( 'front-page' ),
				'viewportWidth' => 800,
				'inserter'      => false,
				'synced'        => true,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$file_contents = file_get_contents( $this->test_dir . '/patterns/theme_unsynced_pattern.php' );
		$this->assertStringContainsString( 'Categories: featured, banner', $file_contents );
		$this->assertStringContainsString( 'Keywords: hero, landing', $file_contents );
		$this->assertStringContainsString( 'Block Types: core/post-content', $file_contents );
		$this->assertStringContainsString( 'Post Types: page', $file_contents );
		$this->assertStringContainsString( 'Template Types: front-page', $file_contents );
		$this->assertStringContainsString( 'Viewport Width: 800', $file_contents );
		$this->assertStringContainsString( 'Inserter: no', $file_contents );
		$this->assertStringContainsString( 'Synced: yes', $file_contents );

		// And back out through a fresh read.
		$request  = $this->create_rest_request( 'GET', '/pattern-builder/v1/patterns/simple-theme/theme-unsynced-pattern' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( array( 'featured', 'banner' ), $data['categories'] );
		$this->assertEquals( array( 'hero', 'landing' ), $data['keywords'] );
		$this->assertEquals( array( 'core/post-content' ), $data['blockTypes'] );
		$this->assertEquals( array( 'page' ), $data['postTypes'] );
		$this->assertEquals( array( 'front-page' ), $data['templateTypes'] );
		$this->assertEquals( 800, $data['viewportWidth'] );
		$this->assertFalse( $data['inserter'] );
		$this->assertTrue( $data['synced'] );
	}

	public function test_update_can_unsync_a_pattern() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params( array( 'synced' => false ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['synced'] );

		$file_contents = file_get_contents( $this->test_dir . '/patterns/theme_synced_pattern.php' );
		$this->assertStringNotContainsString( 'Synced:', $file_contents );
	}

	public function test_create_theme_pattern() {
		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params(
			array(
				'title'       => 'Brand New Pattern',
				'description' => 'Created by a test',
				'content'     => '<!-- wp:paragraph --><p>Fresh</p><!-- /wp:paragraph -->',
				'synced'      => true,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'simple-theme/brand-new-pattern', $data['id'] );
		$this->assertEquals( 'theme', $data['source'] );
		$this->assertTrue( $data['synced'] );

		$this->assertFileExists( $this->test_dir . '/patterns/brand-new-pattern.php' );
	}

	public function test_create_requires_a_title() {
		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params( array( 'content' => '<!-- wp:paragraph --><p>No title</p><!-- /wp:paragraph -->' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	public function test_create_rejects_duplicate_names() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );

		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params(
			array(
				'title' => 'Whatever',
				'name'  => 'simple-theme/theme-synced-pattern',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	public function test_convert_theme_pattern_to_user_pattern() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params( array( 'source' => 'user' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'user', $data['source'] );
		$this->assertEquals( 'wp_block', $data['type'] );
		$this->assertIsInt( $data['id'] );

		// The post exists; the file is gone.
		$post = get_post( $data['id'] );
		$this->assertEquals( 'wp_block', $post->post_type );
		$this->assertEquals( 'Theme Synced Pattern', $post->post_title );
		$this->assertFileDoesNotExist( $this->test_dir . '/patterns/theme_synced_pattern.php' );
	}

	public function test_convert_user_pattern_to_theme_pattern() {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Test User Pattern',
				'post_name'    => 'test-user-pattern',
				'post_content' => '<!-- wp:paragraph --><p>User pattern content</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);

		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params( array( 'fromWpBlock' => $post_id ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'simple-theme/test-user-pattern', $data['id'] );
		$this->assertEquals( 'theme', $data['source'] );

		// The file exists; the post is gone.
		$this->assertFileExists( $this->test_dir . '/patterns/test-user-pattern.php' );
		$this->assertNull( get_post( $post_id ) );

		$file_contents = file_get_contents( $this->test_dir . '/patterns/test-user-pattern.php' );
		$this->assertStringContainsString( 'User pattern content', $file_contents );
		$this->assertStringContainsString( 'Slug: simple-theme/test-user-pattern', $file_contents );
	}

	public function test_convert_theme_pattern_to_user_pattern_and_back() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );

		// Theme -> user.
		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params( array( 'source' => 'user' ) );
		$converted = rest_get_server()->dispatch( $request )->get_data();

		// User -> theme.
		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params( array( 'fromWpBlock' => $converted['id'] ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'simple-theme/theme-synced-pattern', $data['id'] );
		$this->assertFileExists( $this->test_dir . '/patterns/theme-synced-pattern.php' );
		$this->assertCount(
			0,
			get_posts(
				array(
					'post_type'   => 'wp_block',
					'numberposts' => -1,
					'post_status'  => 'any',
				)
			)
		);
	}

	public function test_convert_theme_image_pattern_exports_assets() {
		$this->copy_test_pattern( 'theme_image_test.php' );
		mkdir( $this->test_dir . '/assets' );
		mkdir( $this->test_dir . '/assets/images' );
		copy(
			__DIR__ . '/../../dev-assets/themes/simple-theme/assets/images/twenty_bellows_logo.png',
			$this->test_dir . '/assets/images/twenty_bellows_logo.png'
		);

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-image-test' );
		$request->set_body_params( array( 'source' => 'user' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$post = get_post( $response->get_data()['id'] );

		$this->assertStringNotContainsString( '<?php', $post->post_content );
		$this->assertStringNotContainsString( 'get_stylesheet_directory_uri', $post->post_content );
		$this->assertStringContainsString( wp_upload_dir()['baseurl'], $post->post_content );
	}

	public function test_delete_theme_pattern() {
		$this->copy_test_pattern( 'theme_unsynced_pattern.php' );

		$request  = $this->create_rest_request( 'DELETE', '/pattern-builder/v1/patterns/simple-theme/theme-unsynced-pattern' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertFileDoesNotExist( $this->test_dir . '/patterns/theme_unsynced_pattern.php' );
	}

	public function test_unauthenticated_requests_are_rejected() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );
		wp_set_current_user( 0 );

		foreach ( array(
			array( 'GET', '/pattern-builder/v1/patterns' ),
			array( 'GET', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' ),
			array( 'POST', '/pattern-builder/v1/patterns' ),
			array( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' ),
			array( 'DELETE', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' ),
		) as $route ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( $route[0], $route[1] ) );
			$this->assertEquals( 401, $response->get_status(), "{$route[0]} {$route[1]} should be rejected for anonymous users" );
		}

		// The file survived every attempt.
		$this->assertFileExists( $this->test_dir . '/patterns/theme_synced_pattern.php' );
	}

	public function test_subscribers_cannot_write() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request  = $this->create_rest_request( 'DELETE', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
		$this->assertFileExists( $this->test_dir . '/patterns/theme_synced_pattern.php' );
	}

	public function test_core_blocks_route_is_not_intercepted() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );

		// No v1-style injection: /wp/v2/blocks lists only real wp_block posts.
		$request  = $this->create_rest_request( 'GET', '/wp/v2/blocks' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 0, $response->get_data() );

		// And nothing from this plugin hijacks dispatch anymore.
		global $wp_filter;
		$this->assertFalse(
			isset( $wp_filter['rest_pre_dispatch'] ) && $this->has_plugin_callback( $wp_filter['rest_pre_dispatch'] ),
			'The plugin must not intercept rest_pre_dispatch'
		);
	}

	private function has_plugin_callback( $hook ) {
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && is_object( $function[0] )
					&& 0 === strpos( get_class( $function[0] ), 'TwentyBellows\\PatternBuilder' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public function test_process_theme_rewrites_every_pattern_file() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );
		$this->copy_test_pattern( 'theme_unsynced_pattern.php' );

		$request  = $this->create_rest_request( 'POST', '/pattern-builder/v1/process-theme' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 2, $data['stats']['total'] );
		$this->assertEquals( 2, $data['stats']['processed'] );
	}
}
