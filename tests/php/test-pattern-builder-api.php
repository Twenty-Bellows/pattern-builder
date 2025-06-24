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
			return 'simple-theme';
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

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());
		$this->assertCount(1, $data);

		$pattern = $data[0];

		// This is in the format of the core API wp_block response
		$this->assertIsInt($pattern['id']);
		$this->assertEquals('Theme Synced Pattern', $pattern['title']['raw']);
		$this->assertEquals('simple-theme/theme-synced-pattern', $pattern['slug']);
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

		do_action('init');

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
		$this->assertEquals('simple-theme/theme-synced-pattern', $pattern['slug']);
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

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/block-patterns/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// one of the items in the $data array should be the pattern.
		$pattern = array_find($data, function ($pattern) {
			return $pattern['name'] === 'simple-theme/theme-synced-pattern';
		});

		$this->assertEquals('simple-theme/theme-synced-pattern', $pattern['name']);
		$this->assertEquals('Theme Synced Pattern', $pattern['title']);
		$this->assertEquals(false, $pattern['inserter']);
		$this->stringContains('<!-- wp:block {"ref":', $pattern['content']);

	}

	/**
	 * Test the /wp/v2/blocks endpoint to ensure an unsynced theme pattern is returned when fetching it
	 */
	public function test_core_get_blocks_api_with_unsynced_theme_pattern() {

		$this->copy_test_pattern('theme_unsynced_pattern.php');

		do_action('init');

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
		$this->assertEquals('simple-theme/theme-unsynced-pattern', $pattern['slug']);
		$this->assertEquals('An UNSYNCED pattern that comes with the theme to be used for testing.', $pattern['excerpt']['raw']);
		$this->assertEquals('unsynced', $pattern['wp_pattern_sync_status']);
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
			return $pattern['name'] === 'simple-theme/theme-unsynced-pattern';
		});

		$this->assertEquals('simple-theme/theme-unsynced-pattern', $pattern['name']);
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
		$this->assertCount(1, $data);

		//fetch all of the pb_block posts
		$all_pb_block_posts = get_posts([
			'post_type' => 'pb_block',
			'numberposts' => -1,
			'post_status' => 'any',
		]);
		$this->assertCount(1, $all_pb_block_posts, 'There should be one pb_block post after converting the user pattern to a theme pattern.');

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
		$this->assertCount(1, $data);

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
		$this->assertEquals('simple-theme/theme-synced-pattern', $pattern['slug']);

		// Make sure the pattern file has been created
		$pattern_file = $this->test_dir . '/patterns/theme_synced_pattern.php';
		$this->assertFileExists($pattern_file);
	}

	/**
	 * Test converting a theme pattern with an image to a user pattern
	 * verifies that the image is copied to the WordPress upload directory
	 * and the pattern content references the uploaded image without PHP
	 */
	public function test_convert_theme_image_pattern_to_user_pattern() {

		$this->copy_test_pattern('theme_image_test.php');

		do_action('init');

		// Copy the logo image to the test theme assets directory
		$assets_dir = $this->test_dir . '/assets/images';
		mkdir($assets_dir, 0777, true);
		copy(__DIR__ . '/../../dev-assets/themes/simple-theme/assets/images/twenty_bellows_logo.png', $assets_dir . '/twenty_bellows_logo.png');

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		// Convert the pattern to user pattern
		$pattern_updates = [
			'source' => 'user',
		];

		$request = $this->create_rest_request('PUT', '/wp/v2/blocks/' . $pattern['id']);
		$request->set_body(json_encode($pattern_updates));
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// Fetch the pattern again to check the conversion
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertArrayNotHasKey('source', $pattern);
		$this->assertEquals('theme-image-test', $pattern['slug']);

		// Get the converted content
		$converted_content = $pattern['content']['raw'];

		// Verify PHP has been removed from the content
		$this->assertStringNotContainsString('<?php', $converted_content);
		$this->assertStringNotContainsString('get_stylesheet_directory_uri()', $converted_content);

		// Verify the image URL now points to the uploads directory
		$upload_dir = wp_upload_dir();
		$this->assertStringContainsString($upload_dir['baseurl'], $converted_content);

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

	/**
	 * Test the pattern-builder/v1/patterns GET endpoint returns a valid empty array
	 */
	public function test_get_patterns_endpoint_returns_response() {

		$request = new WP_REST_Request('GET', '/pattern-builder/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertInstanceOf(WP_REST_Response::class, $response);
		$this->assertEquals(200, $response->get_status());
		$this->assertIsArray($data);
		$this->assertEmpty($data);
	}

	/**
	 * Test the pattern-builder/v1/patterns GET endpoint returns a valid unsynced theme pattern
	 */
	public function test_get_patterns_endpoint_returns_response_with_patterns() {

		$this->copy_test_pattern('theme_unsynced_pattern.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/pattern-builder/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertInstanceOf(WP_REST_Response::class, $response);
		$this->assertEquals(200, $response->get_status());
		$this->assertIsArray($data);
		$this->assertNotEmpty($data);
		$this->assertCount(1, $data);

		$pattern = $data[0];

		$this->assertEquals('Theme Unsynced Pattern', $pattern->title);
		$this->assertEquals('simple-theme/theme-unsynced-pattern', $pattern->name);
		$this->assertEquals('An UNSYNCED pattern that comes with the theme to be used for testing.', $pattern->description);
		$this->assertEquals('theme', $pattern->source);
		$this->assertEquals(false, $pattern->synced);
		$this->assertEquals(true, $pattern->inserter);
		$this->assertEquals(array('text'), $pattern->categories);
		$this->assertEquals(array(), $pattern->keywords);
		$this->assertEquals(array(), $pattern->blockTypes);
		$this->assertEquals(array(), $pattern->templateTypes);
		$this->assertEquals(array(), $pattern->postTypes);
	}

	/**
	 * Test the pattern-builder/v1/patterns GET endpoint returns a valid synced theme pattern
	 */
	public function test_get_patterns_endpoint_returns_response_with_synced_patterns() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/pattern-builder/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());
		$this->assertCount(1, $data);

		$pattern = $data[0];

		$this->assertEquals('Theme Synced Pattern', $pattern->title);
		$this->assertEquals('simple-theme/theme-synced-pattern', $pattern->name);
		$this->assertEquals('A SYNCED pattern that comes with the theme to be used for testing.', $pattern->description);
		$this->assertEquals('theme', $pattern->source);
		$this->assertEquals(true, $pattern->synced);
		$this->assertEquals(true, $pattern->inserter);
		$this->assertEquals(array('text'), $pattern->categories);
		$this->assertEquals(array(), $pattern->keywords);
		$this->assertEquals(array(), $pattern->blockTypes);
		$this->assertEquals(array(), $pattern->templateTypes);
		$this->assertEquals(array(), $pattern->postTypes);
	}

	/**
	 * Test a pattern that has restrictions
	 */
	function test_theme_pattern_with_restrictions() {

		$this->copy_test_pattern('theme_restrictions_test.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertEquals(200, $response->get_status());

		$this->assertEquals(array('core/post-content'), $pattern['wp_pattern_block_types']);
		$this->assertEquals(array('page'), $pattern['wp_pattern_post_types']);
		$this->assertEquals(array('front-page'), $pattern['wp_pattern_template_types']);
		$this->assertEquals('no', $pattern['wp_pattern_inserter']);

	}

	/**
	 * Test updating a pattern that has restrictions
	 */
	function test_updating_a_theme_pattern_with_restrictions() {

		$this->copy_test_pattern('theme_restrictions_test.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertEquals(200, $response->get_status());

		$pattern_updates = [
			'wp_pattern_block_types' => ['core/paragraph'],
		];

		$request = $this->create_rest_request('PUT', '/wp/v2/blocks/' . $pattern['id']);
		$request->set_body(json_encode($pattern_updates));
		$response = rest_do_request($request);
		$data = $response->get_data();
		$this->assertEquals(200, $response->get_status());
		$pattern = $data;

		$this->assertEquals(array('core/paragraph'), $pattern['wp_pattern_block_types']);


	}


}
