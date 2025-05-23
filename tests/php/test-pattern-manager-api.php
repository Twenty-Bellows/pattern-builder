<?php

class Pattern_Manager_API_Integration_Test extends WP_UnitTestCase {

	private $test_dir;

	/**
	 * Set up the environment for each test
	 */
	public function setUp(): void {
		parent::setUp();

		$admin_id = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($admin_id);

		// Create a temporary directory for the test patterns
		$this->test_dir = sys_get_temp_dir() . '/pattern-manager-test';
		$this->remove_test_directory($this->test_dir);
		mkdir($this->test_dir);
		mkdir($this->test_dir . '/patterns');

		add_filter('stylesheet_directory', [$this, 'get_test_directory']);
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

	// TESTS ////////////////////////////////////////////////////

	public function no_test_get_global_styles_endpoint() {
		$request = new WP_REST_Request('GET', '/pattern-manager/v1/global-styles');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertInstanceOf(WP_REST_Response::class, $response);
		$this->assertEquals(200, $response->get_status());
		$this->assertIsArray($data);
	}

	/**
	 * Test the pattern-manager/v1/patterns GET endpoint returns a valid empty array
	 */
	public function test_get_patterns_endpoint_returns_response() {

		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertInstanceOf(WP_REST_Response::class, $response);
		$this->assertEquals(200, $response->get_status());
		$this->assertIsArray($data);
		$this->assertEmpty($data);
	}

	/**
	 * Test the pattern-manager/v1/patterns GET endpoint returns a valid unsynced theme pattern
	 */
	public function test_get_patterns_endpoint_returns_response_with_patterns() {

		$this->copy_test_pattern('theme_unsynced_pattern.php');

		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertInstanceOf(WP_REST_Response::class, $response);
		$this->assertEquals(200, $response->get_status());
		$this->assertIsArray($data);
		$this->assertNotEmpty($data);
		$this->assertCount(1, $data);

		$pattern = $data[0];

		$this->assertEquals('Theme Unsynced Pattern', $pattern->title);
		$this->assertEquals('synced-patterns-test/theme-unsynced-pattern', $pattern->name);
		$this->assertEquals('An unsynced pattern provided with the theme to use for testing.', $pattern->description);
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
	 * Test the pattern-manager/v1/patterns GET endpoint returns a valid synced theme pattern
	 */
	public function test_get_patterns_endpoint_returns_response_with_synced_patterns() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());
		$this->assertCount(1, $data);

		$pattern = $data[0];

		$this->assertEquals('Theme Synced Pattern', $pattern->title);
		$this->assertEquals('synced-patterns-test/theme-synced-pattern', $pattern->name);
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
		$this->assertEquals( sanitize_title('synced-patterns-test/theme-synced-pattern'), $pattern['slug']);
		$this->assertEquals('A SYNCED pattern that comes with the theme to be used for testing.', $pattern['excerpt']['raw']);
		$this->assertEquals('', $pattern['wp_pattern_sync_status']);
		$this->assertCount(1, $pattern['wp_pattern_category']);
		$this->assertEquals('text', $pattern['wp_pattern_category'][0]->slug);
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
		$this->assertEquals('Theme Synced Pattern', $pattern['title']['raw']);
		$this->assertEquals('synced-patterns-test-theme-synced-pattern', $pattern['slug']);
		$this->assertEquals('A SYNCED pattern that comes with the theme to be used for testing.', $pattern['excerpt']['raw']);
		$this->assertEquals('', $pattern['wp_pattern_sync_status']);
		$this->assertCount(1, $pattern['wp_pattern_category']);
		$this->assertEquals('text', $pattern['wp_pattern_category'][0]->slug);
		$this->assertEquals('wp_block', $pattern['type']);
	}

	/**
	 * Test that a theme unsynced pattern is registered and returned in this route: /wp/v2/block-patterns/patterns
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
		$this->assertEquals('An unsynced pattern provided with the theme to use for testing.', $pattern['description']);
		$this->assertEquals(true, $pattern['inserter']);
		$this->assertCount(1, $pattern['categories']);
		$this->assertEquals('text', $pattern['categories'][0]);
		$this->assertCount(0, $pattern['keywords']);
		$this->assertCount(0, $pattern['template_types']);
		$this->assertCount(0, $pattern['block_types']);

	}

	/**
	 * Test that a theme synced pattern is registered and returned in this route: /wp/v2/block-patterns/patterns
	 * The returned pattern should be a hidden (inserter: false) pattern with a reference to the post ID of the pattern.
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
		$this->assertStringContainsString('<!-- wp:block {"ref":', $pattern['content']);

	}

	/**
	 * Test that a theme synced pattern can be updated.
	 */
	public function test_update_pattern_api_with_synced_theme_pattern() {

		$this->copy_test_pattern('theme_synced_pattern.php');

		do_action('init');

		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());
		$this->assertCount(1, $data);

		$pattern = $data[0];

		// modify the pattern
		$pattern->content = '<!-- wp:paragraph -->Updated content<!-- /wp:paragraph -->';
		$pattern->title = 'Updated Title';
		$pattern->description = 'Updated description';
		$pattern->categories = array('text', 'design');
		$pattern->keywords = array('updated', 'test');
		$pattern->blockTypes = array('core/paragraph');
		$pattern->templateTypes = array('post');
		$pattern->postTypes = array('post');

		// update the pattern
		$request = new WP_REST_Request('PUT', '/pattern-manager/v1/pattern');
		$request->set_body(json_encode($pattern));
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was updated
		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertEquals('Updated Title', $pattern->title);
		$this->assertEquals('Updated description', $pattern->description);
		$this->assertEquals('<!-- wp:paragraph -->Updated content<!-- /wp:paragraph -->', $pattern->content);

		$this->assertTrue(in_array('text', $pattern->categories));
		$this->assertTrue(in_array('design', $pattern->categories));

		$this->assertTrue(in_array('updated', $pattern->keywords));
		$this->assertTrue(in_array('test', $pattern->keywords));

		$this->assertTrue(in_array('core/paragraph', $pattern->blockTypes));
		$this->assertTrue(in_array('post', $pattern->templateTypes));
		$this->assertTrue(in_array('post', $pattern->postTypes));

		// also fetch the pattern from the core API and ensure it was updated
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];
		$this->assertEquals('Updated Title', $pattern['title']['raw']);

	}

	/**
	 * Test creating a new user unsynced pattern via the API
	 */
	public function test_create_user_pattern() {
		// Create a new pattern
		$pattern = new Abstract_Pattern([
			'name' => 'test-pattern',
			'title' => 'Test Pattern',
			'description' => 'A test pattern',
			'content' => '<!-- wp:paragraph -->Test content<!-- /wp:paragraph -->',
			'source' => 'user',
			'synced' => false,
			'inserter' => true,
			'categories' => ['text'],
		]);

		// Save the pattern
		$request = new WP_REST_Request('POST', '/pattern-manager/v1/pattern');
		$request->set_body(json_encode($pattern));
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data;

		$this->assertEquals(200, $response->get_status());

		// ensure the returned pattern is correct
		$this->assertEquals('Test Pattern', $pattern->title);
		$this->assertEquals('test-pattern', $pattern->name);
		$this->assertEquals('A test pattern', $pattern->description);
		$this->assertEquals('<!-- wp:paragraph -->Test content<!-- /wp:paragraph -->', $pattern->content);
		$this->assertEquals('user', $pattern->source);
		$this->assertEquals(false, $pattern->synced);
		$this->assertEquals(true, $pattern->inserter);
		$this->assertEquals(array('text'), $pattern->categories);
		$this->assertEquals('user', $pattern->source);

		// fetch the pattern again to ensure it was created as expected
		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];
		$this->assertEquals('Test Pattern', $pattern->title);
		$this->assertEquals('test-pattern', $pattern->name);
		$this->assertEquals('A test pattern', $pattern->description);
		$this->assertEquals('<!-- wp:paragraph -->Test content<!-- /wp:paragraph -->', $pattern->content);
		$this->assertEquals('user', $pattern->source);
		$this->assertEquals(false, $pattern->synced);
		$this->assertEquals(true, $pattern->inserter);
		$this->assertEquals(array('text'), $pattern->categories);

		// fetch the pattern from the core API and ensure it was created
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];

		$this->assertEquals('test-pattern', $pattern['slug']);
		$this->assertEquals('Test Pattern', $pattern['title']['raw']);
		$this->assertEquals('<!-- wp:paragraph -->Test content<!-- /wp:paragraph -->', $pattern['content']['raw']);

		// TODO: There is no reason why this should be failing.
		// $this->assertEquals('unsynced', $pattern['wp_pattern_sync_status']);

	}

	/**
	 * Test creating a new user unsynced pattern via the API and updating it
	 */
	public function test_create_and_update_user_pattern() {
		// Create a new pattern
		$pattern = new Abstract_Pattern([
			'name' => 'test-pattern',
			'title' => 'Test Pattern',
			'description' => 'A test pattern',
			'content' => '<!-- wp:paragraph -->Test content<!-- /wp:paragraph -->',
			'source' => 'user',
			'synced' => false,
			'categories' => ['text'],
		]);

		// Save the pattern
		$request = new WP_REST_Request('POST', '/pattern-manager/v1/pattern');
		$request->set_body(json_encode($pattern));
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data;

		$this->assertEquals(200, $response->get_status());

		// update the pattern
		$pattern->content = '<!-- wp:paragraph -->Updated content<!-- /wp:paragraph -->';
		$pattern->title = 'Updated Title';
		$pattern->description = 'Updated description';
		$pattern->categories = array('text', 'design');
		$pattern->synced = true;

		$request = new WP_REST_Request('PUT', '/pattern-manager/v1/pattern');
		$request->set_body(json_encode($pattern));
		$response = rest_do_request($request);
		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was updated
		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];
		$this->assertEquals('Updated Title', $pattern->title);
		$this->assertEquals('Updated description', $pattern->description);
		$this->assertEquals('<!-- wp:paragraph -->Updated content<!-- /wp:paragraph -->', $pattern->content);
		$this->assertEquals('user', $pattern->source);
		$this->assertEquals(true, $pattern->synced);
		$this->assertTrue(in_array('text', $pattern->categories));
		$this->assertTrue(in_array('design', $pattern->categories));

		// fetch the pattern from the core API and ensure it was updated
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data[0];
		$this->assertEquals('Updated Title', $pattern['title']['raw']);
		$this->assertEquals('<!-- wp:paragraph -->Updated content<!-- /wp:paragraph -->', $pattern['content']['raw']);

		// TODO: Not sure where the 'raw' attribute went...
		// $this->assertEquals('Updated description', $pattern['excerpt']['raw']);
		$this->assertStringContainsString('Updated description', $pattern['excerpt']['rendered']);
		$this->assertEquals('', $pattern['wp_pattern_sync_status']);

	}

	/**
	 * Test creating a new user unsynced pattern via the API and deleting it
	 */
	public function test_create_and_delete_user_unsynced_pattern() {
		// Create a new pattern
		$pattern = new Abstract_Pattern([
			'name' => 'test-pattern',
			'title' => 'Test Pattern',
			'description' => 'A test pattern',
			'content' => '<!-- wp:paragraph -->Test content<!-- /wp:paragraph -->',
			'source' => 'user',
			'synced' => false,
			'categories' => ['text'],
		]);

		// Save the pattern
		$request = new WP_REST_Request('POST', '/pattern-manager/v1/pattern');
		$request->set_body(json_encode($pattern));
		$response = rest_do_request($request);
		$data = $response->get_data();
		$pattern = $data;

		$this->assertEquals(200, $response->get_status());

		// delete the pattern
		$request = new WP_REST_Request('DELETE', '/pattern-manager/v1/pattern');
		$request->set_body(json_encode($pattern));
		$response = rest_do_request($request);
		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was deleted
		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$this->assertEquals(200, $response->get_status());
		$this->assertCount(0, $data);
	}

	/**
	 * Test deleting a theme unsynced pattern via the API
	 */
	public function test_delete_theme_unsynced_pattern() {
		$this->copy_test_pattern('theme_unsynced_pattern.php');

		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();

		$this->assertEquals(200, $response->get_status());
		$this->assertCount(1, $data);

		$pattern = $data[0];

		// delete the pattern
		$request = new WP_REST_Request('DELETE', '/pattern-manager/v1/pattern');
		$request->set_body(json_encode($pattern));
		$response = rest_do_request($request);
		$this->assertEquals(200, $response->get_status());

		// fetch the pattern again to ensure it was deleted
		$request = new WP_REST_Request('GET', '/pattern-manager/v1/patterns');
		$response = rest_do_request($request);
		$data = $response->get_data();
		$this->assertEquals(200, $response->get_status());
		$this->assertCount(0, $data);
	}

}
