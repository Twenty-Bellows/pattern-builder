<?php
/**
 * Patterns a plugin registered: reading them out of the block pattern registry, telling them
 * apart from the ones WordPress and the theme put there, and copying one into something this
 * site owns.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Preview;
use TwentyBellows\PatternBuilder\Pattern_File_Store;

/**
 * The Plugin collection.
 */
class Test_Plugin_Patterns extends WP_UnitTestCase {
	/**
	 * Slugs registered by the running test.
	 *
	 * @var string[]
	 */
	private $registered = array();

	/**
	 * A theme directory the test can write patterns into.
	 *
	 * @var string
	 */
	private $theme_dir;

	/**
	 * @var Pattern_File_Store
	 */
	private $store;

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'should_load_remote_block_patterns', '__return_false' );

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-plugin-patterns';
		$this->remove_directory( $this->theme_dir );
		mkdir( $this->theme_dir );
		mkdir( $this->theme_dir . '/patterns' );

		add_filter( 'stylesheet_directory', array( $this, 'filtered_theme_dir' ) );
		add_filter( 'template_directory', array( $this, 'filtered_theme_dir' ) );
		add_filter( 'stylesheet', array( $this, 'filtered_stylesheet' ) );
		add_filter( 'template', array( $this, 'filtered_stylesheet' ) );

		$this->store = new Pattern_File_Store();
	}

	public function tear_down() {
		$registry = WP_Block_Patterns_Registry::get_instance();

		foreach ( $this->registered as $slug ) {
			if ( $registry->is_registered( $slug ) ) {
				$registry->unregister( $slug );
			}
		}

		$this->registered = array();

		remove_filter( 'stylesheet_directory', array( $this, 'filtered_theme_dir' ) );
		remove_filter( 'template_directory', array( $this, 'filtered_theme_dir' ) );
		remove_filter( 'stylesheet', array( $this, 'filtered_stylesheet' ) );
		remove_filter( 'template', array( $this, 'filtered_stylesheet' ) );
		$this->remove_directory( $this->theme_dir );

		parent::tear_down();
	}

	public function filtered_theme_dir() {
		return $this->theme_dir;
	}

	public function filtered_stylesheet() {
		return 'plugin-pattern-test-theme';
	}

	private function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $file ) {
			if ( is_dir( "$dir/$file" ) ) {
				$this->remove_directory( "$dir/$file" );
			} else {
				unlink( "$dir/$file" );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Registers a pattern the way a plugin does, for the duration of the test.
	 *
	 * @param string $slug Pattern name including namespace.
	 * @param array  $properties Properties to register it with.
	 * @return string The slug.
	 */
	private function register( $slug, $properties = array() ) {
		register_block_pattern(
			$slug,
			array_merge(
				array(
					'title'   => 'Registered ' . $slug,
					'content' => '<!-- wp:paragraph --><p>From a plugin.</p><!-- /wp:paragraph -->',
				),
				$properties
			)
		);

		$this->registered[] = $slug;

		return $slug;
	}

	private function names_of( $patterns ) {
		return array_map(
			static function ( $pattern ) {
				return $pattern->name;
			},
			$patterns
		);
	}

	private function find( $patterns, $name ) {
		foreach ( $patterns as $pattern ) {
			if ( $pattern->name === $name ) {
				return $pattern;
			}
		}

		return null;
	}

	private function request( $method, $route ) {
		$request = new WP_REST_Request( $method, $route );

		if ( 'GET' !== $method ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		return $request;
	}

	public function test_a_registered_pattern_is_read_with_its_metadata() {
		$this->register(
			'acme/banner',
			array(
				'title'         => 'Acme Banner',
				'description'   => 'A banner.',
				'categories'    => array( 'banner' ),
				'keywords'      => array( 'promo' ),
				'viewportWidth' => 1366,
			)
		);

		$pattern = $this->find( $this->store->get_plugin_patterns(), 'acme/banner' );

		$this->assertNotNull( $pattern );
		$this->assertSame( 'plugin', $pattern->source );
		$this->assertSame( 'acme/banner', $pattern->id );
		$this->assertSame( 'Acme Banner', $pattern->title );
		$this->assertSame( 'A banner.', $pattern->description );
		$this->assertSame( array( 'banner' ), $pattern->categories );
		$this->assertSame( array( 'promo' ), $pattern->keywords );
		$this->assertSame( 1366, $pattern->viewportWidth );
		$this->assertStringContainsString( 'From a plugin.', $pattern->content );
		// Nothing writes a registered pattern, so it is never one of ours to sync.
		$this->assertFalse( $pattern->synced );
		$this->assertNull( $pattern->filePath );
	}

	public function test_the_label_falls_back_to_the_namespace_when_no_plugin_claims_it() {
		$this->register( 'nobody-installed-this/banner' );

		$pattern = $this->find( $this->store->get_plugin_patterns(), 'nobody-installed-this/banner' );

		$this->assertNotNull( $pattern );
		$this->assertSame( 'nobody-installed-this', $pattern->plugin );
	}

	public function test_wordpresss_own_patterns_are_not_plugin_patterns() {
		$this->register( 'acme/kept' );
		$this->register( 'fake/core-pattern', array( 'source' => 'core' ) );
		$this->register( 'fake/directory-pattern', array( 'source' => 'pattern-directory/theme' ) );

		$names = $this->names_of( $this->store->get_plugin_patterns() );

		$this->assertContains( 'acme/kept', $names );
		$this->assertNotContains( 'fake/core-pattern', $names );
		$this->assertNotContains( 'fake/directory-pattern', $names );
	}

	public function test_a_slug_the_themes_files_claim_is_not_a_plugin_pattern() {
		// Core registers the theme's pattern files under the same `register_block_pattern()`
		// everything else uses, and marks them no differently, so the theme's files are what
		// decide it. A child theme overriding a parent's pattern is the same slug either way.
		file_put_contents(
			$this->theme_dir . '/patterns/owned.php',
			"<?php\n/**\n * Title: Owned by the theme\n * Slug: plugin-pattern-test-theme/owned\n */\n?>\n<!-- wp:paragraph --><p>Theme copy.</p><!-- /wp:paragraph -->\n"
		);

		$this->register( 'plugin-pattern-test-theme/owned' );
		$this->register( 'acme/kept' );

		$names = $this->names_of( $this->store->get_plugin_patterns() );

		$this->assertNotContains( 'plugin-pattern-test-theme/owned', $names );
		$this->assertContains( 'acme/kept', $names );
		$this->assertNull( $this->store->find_plugin_pattern( 'plugin-pattern-test-theme/owned' ) );
	}

	public function test_find_plugin_pattern_answers_by_name() {
		$this->register( 'acme/banner' );

		$this->assertSame( 'acme/banner', $this->store->find_plugin_pattern( 'acme/banner' )->name );
		$this->assertNull( $this->store->find_plugin_pattern( 'acme/nothing' ) );
	}

	public function test_the_listing_asks_for_plugin_patterns_and_leaves_them_out_otherwise() {
		$this->register( 'acme/banner', array( 'title' => 'Acme Banner' ) );
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'A user pattern',
				'post_content' => '<!-- wp:paragraph --><p>Mine.</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);

		$own = rest_get_server()->dispatch( $this->request( 'GET', '/pattern-builder/v1/patterns' ) );
		$this->assertSame( 200, $own->get_status() );
		$this->assertNotContains( 'acme/banner', wp_list_pluck( $own->get_data(), 'name' ) );
		$this->assertContains( $post_id, wp_list_pluck( $own->get_data(), 'id' ) );

		$request = $this->request( 'GET', '/pattern-builder/v1/patterns' );
		$request->set_param( 'source', 'plugin' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$names = wp_list_pluck( $response->get_data(), 'name' );
		$this->assertContains( 'acme/banner', $names );
		$this->assertNotContains( $post_id, wp_list_pluck( $response->get_data(), 'id' ) );

		$banner = null;
		foreach ( $response->get_data() as $item ) {
			if ( 'acme/banner' === $item['name'] ) {
				$banner = $item;
			}
		}

		$this->assertSame( 'plugin', $banner['source'] );
		$this->assertSame( 'acme/banner', $banner['id'] );
		$this->assertArrayHasKey( 'plugin', $banner );
		// Not an entity: nothing behind it to load, and no route that writes it.
		$this->assertArrayNotHasKey( 'type', $banner );
		$this->assertArrayNotHasKey( '_links', $banner );
	}

	public function test_copying_to_the_theme_writes_a_file_that_says_where_it_came_from() {
		$this->register(
			'acme/banner',
			array(
				'title'       => 'Acme Banner',
				'description' => 'A banner.',
				'categories'  => array( 'banner' ),
			)
		);

		$request = $this->request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params( array( 'fromPlugin' => 'acme/banner' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'theme', $data['source'] );
		$this->assertSame( 'plugin-pattern-test-theme/banner', $data['name'] );
		$this->assertSame( 'Acme Banner', $data['title']['raw'] );
		$this->assertSame( array( 'banner' ), $data['categories'] );

		$file = $this->theme_dir . '/patterns/banner.php';
		$this->assertFileExists( $file );

		$contents = file_get_contents( $file );
		$this->assertStringContainsString( 'Slug: plugin-pattern-test-theme/banner', $contents );
		$this->assertStringContainsString( 'From a plugin.', $contents );
		$this->assertStringContainsString( 'Copied from the pattern acme/banner registered by', $contents );

		// The plugin still has its own, untouched.
		$this->assertNotNull( $this->store->find_plugin_pattern( 'acme/banner' ) );
	}

	public function test_copying_twice_numbers_the_second_rather_than_refusing_it() {
		$this->register( 'acme/banner' );

		$first = rest_get_server()->dispatch( $this->copy_request() );
		$this->assertSame( 201, $first->get_status() );

		$second = rest_get_server()->dispatch( $this->copy_request() );
		$this->assertSame( 201, $second->get_status() );

		$this->assertSame( 'plugin-pattern-test-theme/banner', $first->get_data()['name'] );
		$this->assertSame( 'plugin-pattern-test-theme/banner-2', $second->get_data()['name'] );
		$this->assertFileExists( $this->theme_dir . '/patterns/banner-2.php' );
	}

	private function copy_request() {
		$request = $this->request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params( array( 'fromPlugin' => 'acme/banner' ) );

		return $request;
	}

	public function test_copying_to_the_user_patterns_makes_a_wp_block() {
		$this->register(
			'acme/banner',
			array(
				'title'       => 'Acme Banner',
				'description' => 'A banner.',
				'categories'  => array( 'banner' ),
			)
		);

		$request = $this->request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params(
			array(
				'fromPlugin' => 'acme/banner',
				'source'     => 'user',
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'user', $data['source'] );
		$this->assertIsInt( $data['id'] );

		$post = get_post( $data['id'] );
		$this->assertSame( 'wp_block', $post->post_type );
		$this->assertSame( 'Acme Banner', $post->post_title );
		$this->assertSame( 'banner', $post->post_name );
		$this->assertStringContainsString( 'From a plugin.', $post->post_content );
		// A registered pattern is not synced, and an unsynced user pattern is its equivalent.
		$this->assertSame( 'unsynced', get_post_meta( $post->ID, 'wp_pattern_sync_status', true ) );
		$this->assertContains( 'banner', wp_get_object_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'slugs' ) ) );

		// Nothing was written into the theme.
		$this->assertFileDoesNotExist( $this->theme_dir . '/patterns/banner.php' );
	}

	public function test_copying_a_pattern_no_plugin_registers_is_404() {
		$request = $this->request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params( array( 'fromPlugin' => 'acme/nothing' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'pattern_builder_plugin_pattern_not_found', $response->get_data()['code'] );
	}

	/**
	 * What the grid's tiles and the full previews both resolve through. Drawing one is
	 * `Test_Preview`'s business; this is only that a plugin pattern is reachable by name.
	 */
	public function test_the_preview_finds_a_plugin_pattern_by_name() {
		$this->register( 'acme/banner' );

		$preview = new Pattern_Builder_Preview();
		$find    = new ReflectionMethod( $preview, 'find' );
		$find->setAccessible( true );

		$found = $find->invoke( $preview, 'acme/banner' );

		$this->assertNotWPError( $found );
		$this->assertSame( 'plugin', $found->source );
		$this->assertStringContainsString( 'From a plugin.', $found->content );
		$this->assertWPError( $find->invoke( $preview, 'acme/nothing' ) );
	}
}
