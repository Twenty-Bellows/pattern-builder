<?php

require_once __DIR__ . '/../../includes/class-pattern-manager-api.php';

class Pattern_Manager_API_Integration_Test extends WP_UnitTestCase {

	private $pattern_manager_api;
	private $test_dir;
	private $pattern_dir;

	/**
	 * Set up the environment for each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Create an instance of the class under test
		$this->pattern_manager_api = new Twenty_Bellows_Pattern_Manager_API();

		// Create a temporary directory for the test patterns
		$this->test_dir = sys_get_temp_dir() . '/pattern-manager-test';
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

	private function copy_test_pattern($pattern_file) {
		// Copy the pattern file to the test directory
		copy(__DIR__ . '/../../dev-assets/themes/simple-theme/patterns/' . $pattern_file, $this->test_dir . '/patterns/' . $pattern_file);
	}

	/**
	 * Test the pattern-manager/v1/patterns GET endpoint returns a valid response with patterns
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
	}


}
