<?php
/**
 * Uploading a pattern that references others: the tree goes leaves first,
 * its references are rewritten to name the collection they land in, and a
 * dependency this site does not have refuses the whole thing before
 * anything is sent. The service is mocked at the HTTP layer, as every cloud
 * test is.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Controller;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter;

class Test_Cloud_Tree extends WP_UnitTestCase {

	/**
	 * Every service request the mock saw: method, decoded path, body.
	 *
	 * @var array
	 */
	private $seen = array();

	/**
	 * The writable theme directory these tests write pattern files into.
	 *
	 * @var string
	 */
	private $theme_dir;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_test-token' );
		update_user_meta(
			get_current_user_id(),
			Pattern_Builder_Cloud::META_ACCOUNT,
			array(
				'id'     => 7,
				'handle' => 'studio-a',
			)
		);

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-tree-test';
		if ( ! is_dir( $this->theme_dir . '/patterns' ) ) {
			mkdir( $this->theme_dir . '/patterns', 0777, true );
		}
		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'stylesheet', array( $this, 'theme_slug' ) );

		$this->seen = array();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		remove_filter( 'stylesheet', array( $this, 'theme_slug' ) );

		// Patterns land nested now, so sweep the tree rather than one level.
		foreach ( (array) glob( $this->theme_dir . '/patterns/*.php' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		foreach ( (array) glob( $this->theme_dir . '/patterns/*/*/*.php' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_ACCOUNT );
		delete_option( Pattern_Builder_Cloud::OPTION_LINKS );
		parent::tear_down();
	}

	/**
	 * The writable theme directory, as a filter.
	 *
	 * @return string
	 */
	public function theme_dir() {
		return $this->theme_dir;
	}

	/**
	 * The theme's slug, as a filter.
	 *
	 * @return string
	 */
	public function theme_slug() {
		return 'simple-theme';
	}

	/**
	 * Write a theme pattern file.
	 *
	 * @param string $slug    Pattern slug, without the theme namespace.
	 * @param string $title   Pattern title.
	 * @param string $content Block markup.
	 */
	private function make_theme_pattern( $slug, $title, $content ) {
		$header = "<?php\n/**\n * Title: {$title}\n * Slug: simple-theme/{$slug}\n * Description: A test pattern.\n */\n?>\n";
		file_put_contents( $this->theme_dir . '/patterns/' . $slug . '.php', $header . $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * A `core/pattern` reference.
	 *
	 * @param string $name Pattern name.
	 * @return string
	 */
	private function reference( $name ) {
		return sprintf( '<!-- wp:pattern {"slug":"%s"} /-->', $name );
	}

	/**
	 * Mock the service. Every create answers with an incrementing id.
	 *
	 * @param array $collections What GET /library/collections answers.
	 */
	private function mock_service( $collections = null ) {
		if ( null === $collections ) {
			$collections = array(
				array(
					'id'        => 9,
					'title'     => 'Personal',
					'slug'      => 'personal',
					'namespace' => 'studio-a/personal',
					'personal'  => true,
					'count'     => 0,
				),
			);
		}

		$next = 100;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $collections, &$next ) {
				$query = array();
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$path = str_replace( '/pbwp/v1', '', (string) ( $query['rest_route'] ?? '' ) );

				$this->seen[] = array(
					'method' => $args['method'],
					'path'   => $path,
					'body'   => is_string( $args['body'] ) ? $args['body'] : '',
				);

				if ( '/library/collections' === $path ) {
					$body = $collections;
				} elseif ( '/me' === $path ) {
					$body = array( 'entitlements' => array( 'personal_cap' => -1 ) );
				} else {
					$body = array(
						'id'         => $next++,
						'title'      => 'Uploaded',
						'collection' => array(
							'id'       => 9,
							'owner'    => 7,
							'slug'     => 'personal',
							'title'    => 'Personal',
							'personal' => true,
						),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * The bodies of every pattern create or update the mock saw, in order.
	 *
	 * @return string[]
	 */
	private function uploads() {
		$bodies = array();
		foreach ( $this->seen as $request ) {
			if ( 0 === strpos( $request['path'], '/library/patterns' ) ) {
				$bodies[] = $request['body'];
			}
		}
		return $bodies;
	}

	public function test_a_page_pattern_uploads_its_sections_first() {
		$this->make_theme_pattern( 'hero', 'Hero', '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->' );
		$this->make_theme_pattern( 'cta', 'CTA', '<!-- wp:paragraph --><p>Sign up</p><!-- /wp:paragraph -->' );
		$this->make_theme_pattern(
			'page-home',
			'Home Page',
			$this->reference( 'simple-theme/hero' ) . "\n" . $this->reference( 'simple-theme/cta' )
		);

		$this->mock_service();

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/page-home' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array( 'simple-theme/hero', 'simple-theme/cta', 'simple-theme/page-home' ),
			$result['members']
		);

		// Three patterns went up, the page last.
		$uploads = $this->uploads();
		$this->assertCount( 3, $uploads );
		$this->assertStringContainsString( '"slug":"hero"', $uploads[0] );
		$this->assertStringContainsString( '"slug":"cta"', $uploads[1] );
		$this->assertStringContainsString( '"slug":"page-home"', $uploads[2] );

		// And the page's references name the collection they landed in.
		$this->assertStringContainsString( 'studio-a\/personal\/hero', $uploads[2] );
		$this->assertStringContainsString( 'studio-a\/personal\/cta', $uploads[2] );
		$this->assertStringNotContainsString( 'simple-theme\/hero', $uploads[2] );

		// Every member is linked, so a second upload updates rather than duplicates.
		$links = Pattern_Builder_Cloud::links();
		$this->assertArrayHasKey( Pattern_Builder_Cloud_Porter::local_key( 'theme', 'simple-theme/hero' ), $links );
		$this->assertArrayHasKey( Pattern_Builder_Cloud_Porter::local_key( 'theme', 'simple-theme/page-home' ), $links );
	}

	public function test_a_missing_dependency_refuses_before_anything_is_sent() {
		$this->make_theme_pattern( 'page-home', 'Home Page', $this->reference( 'simple-theme/gone' ) );
		$this->mock_service();

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/page-home' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_reference_missing', $result->get_error_code() );
		$this->assertStringContainsString( 'simple-theme/gone', $result->get_error_message() );
		$this->assertSame( array(), $this->uploads() );
	}

	public function test_a_loop_refuses_before_anything_is_sent() {
		$this->make_theme_pattern( 'a', 'A', $this->reference( 'simple-theme/b' ) );
		$this->make_theme_pattern( 'b', 'B', $this->reference( 'simple-theme/a' ) );
		$this->mock_service();

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/a' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_reference_cycle', $result->get_error_code() );
		$this->assertSame( array(), $this->uploads() );
	}

	public function test_a_shared_section_is_uploaded_once() {
		$this->make_theme_pattern( 'shared', 'Shared', '<!-- wp:paragraph --><p>Shared</p><!-- /wp:paragraph -->' );
		$this->make_theme_pattern( 'one', 'One', $this->reference( 'simple-theme/shared' ) );
		$this->make_theme_pattern( 'two', 'Two', $this->reference( 'simple-theme/shared' ) );
		$this->make_theme_pattern(
			'page-home',
			'Home Page',
			$this->reference( 'simple-theme/one' ) . "\n" . $this->reference( 'simple-theme/two' )
		);

		$this->mock_service();

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/page-home' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array( 'simple-theme/shared' ),
			array_values( array_filter( $result['members'], static function ( $name ) {
				return 'simple-theme/shared' === $name;
			} ) )
		);
		$this->assertCount( 4, $result['members'] );
	}

	public function test_a_pattern_referencing_nothing_asks_the_service_nothing_extra() {
		$this->make_theme_pattern( 'hero', 'Hero', '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->' );
		$this->mock_service();

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/hero' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array( 'simple-theme/hero' ), $result['members'] );

		// One request, the upload itself: a lone pattern needs no namespace,
		// so it does not pay for a collection lookup it cannot use.
		$this->assertCount( 1, $this->seen );
		$this->assertSame( '/library/patterns', $this->seen[0]['path'] );
	}

	public function test_a_tree_that_will_not_fit_personal_is_refused_whole() {
		$this->make_theme_pattern( 'hero', 'Hero', '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->' );
		$this->make_theme_pattern( 'cta', 'CTA', '<!-- wp:paragraph --><p>Sign up</p><!-- /wp:paragraph -->' );
		$this->make_theme_pattern(
			'page-home',
			'Home Page',
			$this->reference( 'simple-theme/hero' ) . "\n" . $this->reference( 'simple-theme/cta' )
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$query = array();
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$path = str_replace( '/pbwp/v1', '', (string) ( $query['rest_route'] ?? '' ) );

				$this->seen[] = array(
					'method' => $args['method'],
					'path'   => $path,
					'body'   => is_string( $args['body'] ) ? $args['body'] : '',
				);

				if ( '/library/collections' === $path ) {
					$body = array(
						array(
							'id'        => 9,
							'title'     => 'Personal',
							'slug'      => 'personal',
							'namespace' => 'studio-a/personal',
							'personal'  => true,
							'count'     => 24,
						),
					);
				} else {
					$body = array( 'entitlements' => array( 'personal_cap' => 25 ) );
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/page-home' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_personal_cap', $result->get_error_code() );
		$this->assertStringContainsString( '3 patterns', $result->get_error_message() );
		$this->assertSame( array(), $this->uploads() );
	}

	public function test_installing_a_page_installs_its_sections_first() {
		$downloads = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$downloads ) {
				$query = array();
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$path = str_replace( '/pbwp/v1', '', (string) ( $query['rest_route'] ?? '' ) );

				$this->seen[] = array(
					'method' => $args['method'],
					'path'   => $path,
					'body'   => is_string( $args['body'] ) ? $args['body'] : '',
				);

				// The collection, so a reference can be resolved to an id.
				if ( '/directory/collections/7/heroes' === $path ) {
					$body = array(
						'id'       => 3,
						'owner'    => 7,
						'slug'     => 'heroes',
						'title'    => 'Heroes',
						'patterns' => array(
							array(
								'id'        => 101,
								'namespace' => 'studio-b/heroes/hero',
							),
							array(
								'id'        => 102,
								'namespace' => 'studio-b/heroes/page-home',
							),
						),
					);
				} elseif ( '/directory/patterns/101/download' === $path ) {
					$downloads[] = 101;
					$body        = $this->package( 'studio-b/heroes/hero', 'Hero', '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->' );
				} elseif ( '/directory/patterns/102/download' === $path ) {
					$downloads[] = 102;
					$body        = $this->package(
						'studio-b/heroes/page-home',
						'Home Page',
						$this->reference( 'studio-b/heroes/hero' )
					);
				} else {
					$body = array();
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->install_cloud_pattern(
			102,
			'theme',
			false,
			array(
				'owner' => 7,
				'slug'  => 'heroes',
				'title' => 'Heroes',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array( 'studio-b/heroes/hero' ), $result['dependencies'] );

		// The page is fetched first, because its references cannot be known
		// until its package is in hand — but the section is *written* first,
		// which is what `install_dependencies()` running before the import
		// guarantees and what keeps the page from rendering a placeholder.
		$this->assertSame( array( 102, 101 ), $downloads );

		// Both landed, each under its own name, and the page's reference
		// resolves without anything being rewritten.
		$this->assertFileExists( $this->theme_dir . '/patterns/studio-b/heroes/hero.php' );
		$page = $this->theme_dir . '/patterns/studio-b/heroes/page-home.php';
		$this->assertFileExists( $page );
		$this->assertStringContainsString( 'studio-b/heroes/hero', file_get_contents( $page ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

	}

	public function test_a_section_already_here_is_not_installed_again() {
		// The hero is already on this site under its cloud name.
		if ( ! is_dir( $this->theme_dir . '/patterns/studio-b/heroes' ) ) {
			mkdir( $this->theme_dir . '/patterns/studio-b/heroes', 0777, true );
		}
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->theme_dir . '/patterns/studio-b/heroes/hero.php',
			"<?php\n/**\n * Title: Hero\n * Slug: studio-b/heroes/hero\n * Description: Already here.\n */\n?>\n<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->"
		);

		$downloads = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$downloads ) {
				$query = array();
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$path = str_replace( '/pbwp/v1', '', (string) ( $query['rest_route'] ?? '' ) );

				if ( false !== strpos( $path, '/download' ) ) {
					$downloads[] = $path;
				}

				if ( '/directory/collections/7/heroes' === $path ) {
					$body = array(
						'patterns' => array(
							array(
								'id'        => 101,
								'namespace' => 'studio-b/heroes/hero',
							),
						),
					);
				} elseif ( '/directory/patterns/102/download' === $path ) {
					$body = $this->package(
						'studio-b/heroes/page-home',
						'Home Page',
						$this->reference( 'studio-b/heroes/hero' )
					);
				} else {
					$body = array();
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->install_cloud_pattern(
			102,
			'theme',
			false,
			array(
				'owner' => 7,
				'slug'  => 'heroes',
				'title' => 'Heroes',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array(), $result['dependencies'] );
		$this->assertSame( array( '/directory/patterns/102/download' ), $downloads );

	}

	/**
	 * A downloadable package, as the service hands one over.
	 *
	 * @param string $namespace The pattern's cloud name.
	 * @param string $title     Its title.
	 * @param string $content   Its markup.
	 * @return array
	 */
	private function package( $namespace, $title, $content ) {
		$segments = explode( '/', $namespace );

		return array(
			'format'    => 'pbp/1',
			'title'     => $title,
			'slug'      => end( $segments ),
			'namespace' => $namespace,
			'content'   => $content,
			'assets'    => array(),
			'synced'    => false,
		);
	}
}
