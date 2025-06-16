<?php

class Pattern_Builder_API_Integration_Test extends WP_UnitTestCase {

	private $test_dir;

	/**
	 * Set up the environment for each test
	 */
	public function setUp(): void {
		parent::setUp();

		$admin_id = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($admin_id);

		// Create a temporary directory for the test patterns
		$this->test_dir = sys_get_temp_dir() . '/pattern-builder-test';
		$this->remove_test_directory($this->test_dir);
		mkdir($this->test_dir);
		mkdir($this->test_dir . '/patterns');

		add_filter('stylesheet_directory', [$this, 'get_test_directory']);
		add_filter( 'should_load_remote_block_patterns', '__return_false' );

		// add a filter to override the stylesheet value to simulate our test theme
		add_filter('stylesheet', function() {
			return 'synced-patterns-test';
		});
	}

	/**
	 * Clean up after each test
	 */
	public function tearDown(): void {
		$this->remove_test_directory($this->test_dir);
		remove_filter('stylesheet_directory', [$this, 'get_test_directory']);
		parent::tearDown();
	}

	/**
	 * Helper function to recursively remove a directory
	 */
	private function remove_test_directory($dir) {
		if (is_dir($dir)) {
			$files = array_diff(scandir($dir), ['.', '..']);
			foreach ($files as $file) {
				(is_dir("$dir/$file")) ? $this->remove_test_directory("$dir/$file") : unlink("$dir/$file");
			}
			rmdir($dir);
		}
	}

	public function get_test_directory() {
		return $this->test_dir;
	}

	private function copy_test_pattern($pattern_file) {
		// Copy the pattern file to the test directory
		copy(__DIR__ . '/../../dev-assets/themes/simple-theme/patterns/' . $pattern_file, $this->test_dir . '/patterns/' . $pattern_file);
	}

	/**
	 * Helper method to create authenticated REST requests
	 */
	private function create_rest_request($method, $route) {
		$rest_nonce = wp_create_nonce('wp_rest');
		$request = new WP_REST_Request($method, $route);
		if (in_array($method, ['PUT', 'POST', 'DELETE'])) {
			$request->set_header('X-WP-Nonce', $rest_nonce);
		}
		return $request;
	}

	// TESTS ////////////////////////////////////////////////////


	/**
	 * Test the /wp/v2/blocks endpoint to ensure a synced theme pattern is returned when fetching all patterns
	 */
	public function test_core_get_patterns_api_with_synced_theme_pattern() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());
		$this->assertCount(1, $data);

		$pattern = $data[0];

		// This is in the format of the core API wp_block response
		$this->assertIsInt($pattern['id']);
		$this->assertEquals('Theme Synced Pattern', $pattern['title']['raw']);
		$this->assertEquals('synced-patterns-test/theme-synced-pattern', $pattern['slug']);
		$this->assertEquals('A SYNCED pattern that comes with the theme to be used for testing.', $pattern['excerpt']['raw']);
		$this->assertEquals('', $pattern['wp_pattern_sync_status']);
		$this->assertCount(1, $pattern['wp_pattern_category']);
		$this->assertIsInt($pattern['wp_pattern_category'][0]);
	}

	/**
	 * Test the /wp/v2/blocks endpoint to ensure a synced theme pattern is returned when fetching it
	 */
	public function test_core_get_blocks_api_with_synced_theme_pattern() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$request = new WP_REST_Request('GET', '/wp/v2/blocks/' . $pattern['id']);
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data;

		$this->assertEquals(200, $response->get_status());
		$this->assertEquals('theme', $pattern['source']);
		$this->assertEquals('Theme Synced Pattern', $pattern['title']['raw']);
		$this->assertEquals('synced-patterns-test/theme-synced-pattern', $pattern['slug']);
		$this->assertEquals('A SYNCED pattern that comes with the theme to be used for testing.', $pattern['excerpt']['raw']);
		$this->assertEquals('', $pattern['wp_pattern_sync_status']);
		$this->assertCount(1, $pattern['wp_pattern_category']);
		$this->assertIsInt($pattern['wp_pattern_category'][0]);
		$this->assertEquals('wp_block', $pattern['type']);
	}

	/**
	 * Test that a theme synced pattern is registered and returned in this route: /wp/v2/block-patterns/patterns.
	 * It should be hidden from the inserter and reference the corresponding pb_block.
	 */
	public function test_core_get_pattern_api_with_synced_theme_pattern() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		// trigger the 'init' action to register the patterns
		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/block-patterns/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// one of the items in the $data array should be the pattern.
		$pattern = array_find($data, function ($pattern) {
			return $pattern['name'] === 'synced-patterns-test/theme-synced-pattern';
		});

		$this->assertEquals('synced-patterns-test/theme-synced-pattern', $pattern['name']);
		$this->assertEquals('Theme Synced Pattern', $pattern['title']);
		$this->assertEquals(false, $pattern['inserter']);
		$this->stringContains('<!-- wp:block {"ref":', $pattern['content']);

	}

	/**
	 * Test the /wp/v2/blocks endpoint to ensure an unsynced theme pattern is returned when fetching it
	 */
	public function test_core_get_blocks_api_with_unsynced_theme_pattern() {

		$this->copy_test_pattern('theme_unsynced_pattern.php');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$request = new WP_REST_Request('GET', '/wp/v2/blocks/' . $pattern['id']);
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data;

		$this->assertEquals(200, $response->get_status());
		$this->assertEquals('Theme Unsynced Pattern', $pattern['title']['raw']);
		$this->assertEquals('synced-patterns-test/theme-unsynced-pattern', $pattern['slug']);
		$this->assertEquals('An UNSYNCED pattern that comes with the theme to be used for testing.', $pattern['excerpt']['raw']);
		$this->assertEquals('', $pattern['wp_pattern_sync_status']);
		$this->assertCount(1, $pattern['wp_pattern_category']);
		$this->assertIsInt($pattern['wp_pattern_category'][0]);
		$this->assertEquals('wp_block', $pattern['type']);
	}

	/**
	 * Test that a theme unsynced pattern is registered and returned in this route: /wp/v2/block-patterns/patterns.
	 * It should be hidden from the inserter and contain the original pattern content.
	 */
	public function test_core_get_pattern_api_with_unsynced_theme_pattern() {

		$this->copy_test_pattern('theme_unsynced_pattern.php');

		// trigger the 'init' action to register the patterns
		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/block-patterns/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// one of the items in the $data array should be the pattern.
		$pattern = array_find($data, function ($pattern) {
			return $pattern['name'] === 'synced-patterns-test/theme-unsynced-pattern';
		});

		$this->assertEquals('synced-patterns-test/theme-unsynced-pattern', $pattern['name']);
		$this->assertEquals('Theme Unsynced Pattern', $pattern['title']);
		$this->assertEquals(false, $pattern['inserter']);
		$this->stringContains('This is a Theme UNSYNCED Pattern', $pattern['content']);
	}

	/**
	 * Test that a theme synced pattern can be updated.
	 */
	public function test_update_pattern_api_with_synced_theme_pattern() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		// update the pattern
		$pattern_updates = [
			'content' => '<!-- wp:paragraph -->Updated content<!-- /wp:paragraph -->',
			'title' => 'Updated Title',
			'excerpt' => 'Updated description',
		];

		$request = $this->create_rest_request('PUT', '/wp/v2/blocks/' . $pattern['id']);
		$request->set_body(json_encode($pattern_updates));
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was updated
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertEquals('Updated Title', $pattern['title']['raw']);
		$this->assertEquals('Updated description', $pattern['excerpt']['raw']);
		$this->assertEquals('<!-- wp:paragraph -->Updated content<!-- /wp:paragraph -->', $pattern['content']['raw']);

		// fetch the pattern file to ensure it was updated
		$pattern_file = $this->test_dir . '/patterns/theme_synced_pattern.php';
		$this->assertFileExists($pattern_file);
		$pattern_content = file_get_contents($pattern_file);
		$this->assertStringContainsString('Updated content', $pattern_content);
		$this->assertStringContainsString('Updated Title', $pattern_content);
		$this->assertStringContainsString('Updated description', $pattern_content);

		// TODO: Test manipulation of categories, keywords, blockTypes, templateTypes, and postTypes

		// $this->assertTrue(in_array('text', $pattern->categories));
		// $this->assertTrue(in_array('design', $pattern->categories));

		// $this->assertTrue(in_array('updated', $pattern->keywords));
		// $this->assertTrue(in_array('test', $pattern->keywords));

		// $this->assertTrue(in_array('core/paragraph', $pattern->blockTypes));
		// $this->assertTrue(in_array('post', $pattern->templateTypes));
		// $this->assertTrue(in_array('post', $pattern->postTypes));
	}

	/**
	 * Test converting a theme pattern to a user pattern via the API
	 */
	public function test_convert_theme_pattern_to_user_pattern() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		// update the pattern
		$pattern_updates = [
			'source' => 'user',
		];

		$request = $this->create_rest_request('PUT', '/wp/v2/blocks/' . $pattern['id']);
		$request->set_body(json_encode($pattern_updates));
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was updated
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertArrayNotHasKey('source', $pattern);
		$this->assertEquals('theme-synced-pattern', $pattern['slug']);

		//Ensure there is not a pb_block post for this pattern
		// get app 'pb_block' posts
		$all_pb_block_posts = get_posts([
			'post_type' => 'pb_block',
			'numberposts' => -1,
			'post_status' => 'any',
		]);
		$this->assertEmpty($all_pb_block_posts, 'There should be no pb_block posts after converting the theme pattern to a user pattern.');

		// Make sure the pattern file has been removed
		$pattern_file = $this->test_dir . '/patterns/theme_synced_pattern.php';
		$this->assertFileDoesNotExist($pattern_file);

	}

	/**
	 * Test converting a user pattern to a theme pattern via the API
	 */
	public function test_convert_user_pattern_to_theme_pattern() {

		// create a user pattern
		$post_id = wp_insert_post([
			'post_title'   => 'Test User Pattern',
			'post_name'    => 'test-user-pattern',
			'post_content' => '<!-- wp:paragraph -->This is a test user pattern<!-- /wp:paragraph -->',
			'post_excerpt' => 'A test user pattern for API testing',
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
		]);

		// update the pattern
		$pattern_updates = [
			'source' => 'theme',
		];

		$request = $this->create_rest_request('PUT', '/wp/v2/blocks/' . $post_id);
		$request->set_body(json_encode($pattern_updates));
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was updated
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertEquals('theme', $pattern['source']);

		// Make sure the pattern file has been created
		$pattern_file = $this->test_dir . '/patterns/test_user_pattern.php';
		$this->assertFileExists($pattern_file);
	}

	/**
	 * Test converting a theme pattern to a user pattern and back via the API
	 */
	public function test_convert_theme_pattern_to_user_pattern_and_back() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		// update the pattern
		$pattern_updates = [
			'source' => 'user',
		];

		$request = $this->create_rest_request('PUT', '/wp/v2/blocks/' . $pattern['id']);
		$request->set_body(json_encode($pattern_updates));
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was updated
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertArrayNotHasKey('source', $pattern);

		//Ensure there is not a pb_block post for this pattern
		// get app 'pb_block' posts
		$all_pb_block_posts = get_posts([
			'post_type' => 'pb_block',
			'numberposts' => -1,
			'post_status' => 'any',
		]);
		$this->assertEmpty($all_pb_block_posts, 'There should be no pb_block posts after converting the theme pattern to a user pattern.');

		// Make sure the pattern file has been removed
		$pattern_file = $this->test_dir . '/patterns/theme_synced_pattern.php';
		$this->assertFileDoesNotExist($pattern_file);

		// update the pattern
		$pattern_updates = [
			'source' => 'theme',
		];

		$request = $this->create_rest_request('PUT', '/wp/v2/blocks/' . $pattern['id']);
		$request->set_body(json_encode($pattern_updates));
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was updated
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertEquals('theme', $pattern['source']);
		$this->assertEquals('synced-patterns-test/theme-synced-pattern', $pattern['slug']);

		// Make sure the pattern file has been created
		$pattern_file = $this->test_dir . '/patterns/theme_synced_pattern.php';
		$this->assertFileExists($pattern_file);
	}

	/**
	 * Test that a theme pattern can be delete via the API
	 */
	public function test_delete_theme_pattern() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		//delete the pattern
		$request = $this->create_rest_request('DELETE', '/wp/v2/blocks/' . $pattern['id']);
		$response = rest_do_request($request);

		$this->assertEquals(200, $response->get_status());

		// confirm that there are no pb_block posts
		$all_pb_block_posts = get_posts([
			'post_type' => 'pb_block',
			'numberposts' => -1,
			'post_status' => 'any',
		]);
		$this->assertEmpty($all_pb_block_posts, 'There should be no pb_block posts after deleting the theme pattern.');

		//confirm that the pattern file has been deleted
		$pattern_file = $this->test_dir . '/patterns/theme_synced_pattern.php';
		$this->assertFileDoesNotExist($pattern_file, 'The pattern file should be deleted after deleting the theme pattern.');

		//confirm that no blocks are returned from the API
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();

		//assert that the $data array is empty
		$this->assertEmpty($data, 'There should be no blocks returned from the API after deleting the theme pattern.');
	}

}
