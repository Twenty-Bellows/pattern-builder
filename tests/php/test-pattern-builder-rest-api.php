<?php
/**
 * Integration tests for the Pattern Builder REST API.
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

	public function test_create_records_the_contexts_a_starter_pattern_is_offered_in() {
		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params(
			array(
				'title'      => 'Landing Starter',
				'synced'     => false,
				'blockTypes' => array( 'core/post-content' ),
				'postTypes'  => array( 'page', 'post' ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( array( 'core/post-content' ), $data['blockTypes'] );
		$this->assertEquals( array( 'page', 'post' ), $data['postTypes'] );
		$this->assertFalse( $data['synced'] );

		$file_contents = file_get_contents( $this->test_dir . '/patterns/landing-starter.php' );
		$this->assertStringContainsString( 'Block Types: core/post-content', $file_contents );
		$this->assertStringContainsString( 'Post Types: page, post', $file_contents );
		$this->assertStringNotContainsString( 'Synced:', $file_contents );
	}

	public function test_create_writes_a_template_pattern_the_way_themes_ship_them() {
		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params(
			array(
				'title'         => 'Archive Layout',
				'synced'        => false,
				'templateTypes' => array( 'archive', 'category' ),
				'viewportWidth' => 1400,
				'inserter'      => false,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( array( 'archive', 'category' ), $data['templateTypes'] );
		$this->assertFalse( $data['inserter'] );

		$file_contents = file_get_contents( $this->test_dir . '/patterns/archive-layout.php' );
		$this->assertStringContainsString( 'Template Types: archive, category', $file_contents );
		$this->assertStringContainsString( 'Viewport Width: 1400', $file_contents );
		$this->assertStringContainsString( 'Inserter: no', $file_contents );
	}

	public function test_create_writes_a_template_part_pattern_with_its_area() {
		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params(
			array(
				'title'      => 'Centered Header',
				'synced'     => false,
				'blockTypes' => array( 'core/template-part/header' ),
				'categories' => array( 'header' ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 201, $response->get_status() );

		$file_contents = file_get_contents( $this->test_dir . '/patterns/centered-header.php' );
		$this->assertStringContainsString( 'Categories: header', $file_contents );
		$this->assertStringContainsString( 'Block Types: core/template-part/header', $file_contents );
		$this->assertStringNotContainsString( 'Inserter: no', $file_contents );
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
		$post = get_post( $data['id'] );
		$this->assertEquals( 'wp_block', $post->post_type );
		$this->assertEquals( 'Theme Synced Pattern', $post->post_title );
		$this->assertFileDoesNotExist( $this->test_dir . '/patterns/theme_synced_pattern.php' );
	}

	/**
	 * Converting is still the same pattern: its attribution and the name of its copy on the
	 * cloud go with it, from header to post meta.
	 */
	public function test_converting_to_a_user_pattern_keeps_its_origin_and_cloud_reference() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );
		$file = $this->test_dir . '/patterns/theme_synced_pattern.php';
		file_put_contents( $file, preg_replace( '/\n \*\//', "\n * Origin: studio-b/heroes/hero\n * Cloud: studio-a/personal/theme-synced-pattern\n */", file_get_contents( $file ), 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params( array( 'source' => 'user' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 'studio-b/heroes/hero', get_post_meta( $data['id'], \TwentyBellows\PatternBuilder\Pattern_File_Store::META_ORIGIN, true ) );
		$this->assertSame( 'studio-a/personal/theme-synced-pattern', get_post_meta( $data['id'], \TwentyBellows\PatternBuilder\Pattern_File_Store::META_CLOUD, true ) );
		$this->assertSame( 'studio-a/personal/theme-synced-pattern', $data['cloud'] );
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
		$this->assertFileExists( $this->test_dir . '/patterns/test-user-pattern.php' );
		$this->assertNull( get_post( $post_id ) );

		$file_contents = file_get_contents( $this->test_dir . '/patterns/test-user-pattern.php' );
		$this->assertStringContainsString( 'User pattern content', $file_contents );
		$this->assertStringContainsString( 'Slug: simple-theme/test-user-pattern', $file_contents );
	}

	public function test_convert_theme_pattern_to_user_pattern_and_back() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );
		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params( array( 'source' => 'user' ) );
		$converted = rest_get_server()->dispatch( $request )->get_data();
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

	/**
	 * A page, a user pattern and a Site Editor customization that place a theme pattern all
	 * follow it into the database, keeping the slot the page fills.
	 */
	public function test_converting_to_a_user_pattern_repoints_everything_that_placed_it() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );
		$filled = '<!-- wp:pattern {"slug":"simple-theme/theme-synced-pattern","content":{"headline":{"content":"Filled here"}}} /-->';
		$plain  = '<!-- wp:pattern {"slug":"simple-theme/theme-synced-pattern"} /-->';

		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => "<!-- wp:paragraph -->\n<p>Before</p>\n<!-- /wp:paragraph -->\n" . $filled,
			)
		);
		$user   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_content' => $plain,
			)
		);
		$custom = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'index',
				'post_content' => $plain,
			)
		);

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params( array( 'source' => 'user' ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEqualsCanonicalizing( array( $page, $user, $custom ), wp_list_pluck( $data['repointed']['posts'], 'id' ) );
		$this->assertSame( array(), $data['repointed']['files'] );

		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>Before</p>\n<!-- /wp:paragraph -->\n" . '<!-- wp:block {"ref":' . $data['id'] . ',"content":{"headline":{"content":"Filled here"}}} /-->',
			get_post( $page )->post_content
		);
		$this->assertSame( '<!-- wp:block {"ref":' . $data['id'] . '} /-->', get_post( $user )->post_content );
		$this->assertSame( '<!-- wp:block {"ref":' . $data['id'] . '} /-->', get_post( $custom )->post_content );
		$this->assertNotSame( '', trim( do_blocks( get_post( $user )->post_content ) ), 'the reference still renders the pattern' );
	}

	/**
	 * A theme file would be left pointing at a database ID no other site has, so the
	 * conversion stops before anything changes and says which files are in the way.
	 */
	public function test_converting_to_a_user_pattern_is_refused_while_the_theme_places_it() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );
		$reference = '<!-- wp:pattern {"slug":"simple-theme/theme-synced-pattern"} /-->';
		mkdir( $this->test_dir . '/templates' );
		file_put_contents( $this->test_dir . '/templates/front-page.html', $reference );
		file_put_contents( $this->test_dir . '/patterns/landing.php', "<?php\n/**\n * Title: Landing\n * Slug: simple-theme/landing\n */\n?>\n" . $reference );
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $reference,
			)
		);

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params( array( 'source' => 'user' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 409, $response->get_status() );
		$this->assertSame( 'pattern_builder_theme_places_pattern', $response->get_data()['code'] );
		$this->assertSame(
			array(
				array(
					'kind' => 'pattern',
					'file' => 'patterns/landing.php',
				),
				array(
					'kind' => 'template',
					'file' => 'templates/front-page.html',
				),
			),
			$response->get_data()['data']['blockers']
		);
		$this->assertStringContainsString( 'patterns/landing.php, templates/front-page.html', $response->get_data()['message'] );

		$this->assertFileExists( $this->test_dir . '/patterns/theme_synced_pattern.php' );
		$this->assertSame( $reference, get_post( $page )->post_content );
		$this->assertSame( $reference, file_get_contents( $this->test_dir . '/templates/front-page.html' ) );
		$this->assertCount(
			0,
			get_posts(
				array(
					'post_type'   => 'wp_block',
					'numberposts' => -1,
					'post_status' => 'any',
				)
			)
		);
	}

	/**
	 * Everything that placed a user pattern by its ID follows it into the theme, the theme's
	 * own files included, and a pattern file's PHP is still there afterwards.
	 */
	public function test_converting_to_a_theme_pattern_repoints_everything_that_placed_it() {
		$post_id   = wp_insert_post(
			array(
				'post_title'   => 'Test User Pattern',
				'post_name'    => 'test-user-pattern',
				'post_content' => '<!-- wp:paragraph --><p>User pattern content</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
		$reference = '<!-- wp:block {"ref":' . $post_id . ',"content":{"headline":{"content":"Filled here"}}} /-->';
		$moved     = '<!-- wp:pattern {"slug":"simple-theme/test-user-pattern","content":{"headline":{"content":"Filled here"}}} /-->';
		$header    = "<?php\n/**\n * Title: Landing\n * Slug: simple-theme/landing\n */\n?>\n<p><?php echo esc_html( gmdate( 'Y' ) ); ?></p>\n";

		$page  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $reference,
			)
		);
		$other = self::factory()->post->create( array( 'post_content' => '<!-- wp:block {"ref":' . ( $post_id + 1000 ) . '} /-->' ) );
		mkdir( $this->test_dir . '/parts' );
		file_put_contents( $this->test_dir . '/parts/footer.html', $reference );
		file_put_contents( $this->test_dir . '/patterns/landing.php', $header . $reference );

		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params( array( 'fromWpBlock' => $post_id ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertNull( get_post( $post_id ) );
		$this->assertSame( array( $page ), wp_list_pluck( $data['repointed']['posts'], 'id' ) );
		$this->assertSame(
			array(
				array(
					'kind' => 'template-part',
					'file' => 'parts/footer.html',
				),
				array(
					'kind' => 'pattern',
					'file' => 'patterns/landing.php',
				),
			),
			$data['repointed']['files']
		);

		$this->assertSame( $moved, get_post( $page )->post_content );
		$this->assertSame( '<!-- wp:block {"ref":' . ( $post_id + 1000 ) . '} /-->', get_post( $other )->post_content );
		$this->assertSame( $moved, file_get_contents( $this->test_dir . '/parts/footer.html' ) );
		$this->assertSame( $header . $moved, file_get_contents( $this->test_dir . '/patterns/landing.php' ) );
	}

	/**
	 * There and back: a page that fills a slot ends where it began.
	 */
	public function test_a_reference_survives_the_round_trip() {
		$this->copy_test_pattern( 'theme_synced_pattern.php' );
		$reference = '<!-- wp:pattern {"slug":"simple-theme/theme-synced-pattern","content":{"headline":{"content":"Filled here"}}} /-->';
		$page      = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $reference,
			)
		);

		$request = $this->create_rest_request( 'PUT', '/pattern-builder/v1/patterns/simple-theme/theme-synced-pattern' );
		$request->set_body_params( array( 'source' => 'user' ) );
		$converted = rest_get_server()->dispatch( $request )->get_data();
		$request   = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_body_params( array( 'fromWpBlock' => $converted['id'] ) );
		rest_get_server()->dispatch( $request );

		$this->assertSame( $reference, get_post( $page )->post_content );
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
		$this->assertStringNotContainsString( 'get_theme_file_uri', $post->post_content );
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

	/**
	 * A theme reached through a symlink — the usual shape of a local dev setup — resolves
	 * to a real path outside the directory the theme functions report, which used to read
	 * as a traversal attempt and made deleting (or writing) a pattern impossible.
	 */
	public function test_delete_theme_pattern_in_a_symlinked_theme() {
		$link = sys_get_temp_dir() . '/pattern-builder-test-link';

		if ( file_exists( $link ) ) {
			unlink( $link );
		}

		if ( ! @symlink( $this->test_dir, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Filesystems without symlink support skip this test.
			$this->markTestSkipped( 'This filesystem does not support symlinks.' );
		}

		$this->copy_test_pattern( 'theme_unsynced_pattern.php' );

		$through_link = static function () use ( $link ) {
			return $link;
		};
		add_filter( 'stylesheet_directory', $through_link, 20 );
		add_filter( 'template_directory', $through_link, 20 );

		$request  = $this->create_rest_request( 'DELETE', '/pattern-builder/v1/patterns/simple-theme/theme-unsynced-pattern' );
		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'stylesheet_directory', $through_link, 20 );
		remove_filter( 'template_directory', $through_link, 20 );
		unlink( $link );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertFileDoesNotExist( $this->test_dir . '/patterns/theme_unsynced_pattern.php' );
	}

	/**
	 * The same symlinked theme, writing a pattern that isn't there yet: a file being
	 * created can't be resolved, and comparing it unresolved against resolved theme
	 * directories read as a traversal attempt.
	 */
	public function test_create_theme_pattern_in_a_symlinked_theme() {
		$link = sys_get_temp_dir() . '/pattern-builder-test-link';

		if ( file_exists( $link ) ) {
			unlink( $link );
		}

		if ( ! @symlink( $this->test_dir, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Filesystems without symlink support skip this test.
			$this->markTestSkipped( 'This filesystem does not support symlinks.' );
		}

		$through_link = static function () use ( $link ) {
			return $link;
		};
		add_filter( 'stylesheet_directory', $through_link, 20 );
		add_filter( 'template_directory', $through_link, 20 );

		$request = $this->create_rest_request( 'POST', '/pattern-builder/v1/patterns' );
		$request->set_param( 'title', 'Brand New Pattern' );
		$request->set_param( 'content', '<!-- wp:paragraph --><p>New.</p><!-- /wp:paragraph -->' );
		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'stylesheet_directory', $through_link, 20 );
		remove_filter( 'template_directory', $through_link, 20 );
		unlink( $link );

		$this->assertEquals( 201, $response->get_status() );
		$this->assertFileExists( $this->test_dir . '/patterns/brand-new-pattern.php' );
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

		$request  = $this->create_rest_request( 'GET', '/wp/v2/blocks' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 0, $response->get_data() );
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
