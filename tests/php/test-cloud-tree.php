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
use TwentyBellows\PatternBuilder\Pattern_File_Store;

class Test_Cloud_Tree extends WP_UnitTestCase {

	/**
	 * Every service request the mock saw: method, decoded path, body.
	 *
	 * @var array
	 */
	private $seen = array();

	/**
	 * What the mocked account's library holds: cloud name => id.
	 *
	 * @var array
	 */
	private $library = array();

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

		$this->seen    = array();
		$this->library = array();
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
	private function make_theme_pattern( $slug, $title, $content, $cloud = '' ) {
		$cloud  = $cloud ? "\n * Cloud: {$cloud}" : '';
		$header = "<?php\n/**\n * Title: {$title}\n * Slug: simple-theme/{$slug}\n * Description: A test pattern.{$cloud}\n */\n?>\n";
		file_put_contents( $this->theme_dir . '/patterns/' . $slug . '.php', $header . $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * The `Cloud:` reference a theme pattern carries now.
	 *
	 * @param string $name Local pattern name.
	 * @return string
	 */
	private function cloud_of( $name ) {
		return ( new Pattern_File_Store() )->find_theme_pattern( $name )->cloud;
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
	 * Mock the service: Personal, and a library that answers a lookup by name
	 * with 404 until something is uploaded under it.
	 *
	 * @param int $personal_count How many patterns Personal holds already.
	 * @param int $personal_cap   Its cap, or -1 for none.
	 */
	private function mock_service( $personal_count = 0, $personal_cap = -1 ) {
		$next = 100;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $personal_count, $personal_cap, &$next ) {
				$query = array();
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$path = str_replace( '/pbwp/v1', '', (string) ( $query['rest_route'] ?? '' ) );
				$code = 200;

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
							'count'     => $personal_count,
						),
					);
				} elseif ( '/me' === $path ) {
					$body = array( 'entitlements' => array( 'personal_cap' => $personal_cap ) );
				} elseif ( 0 === strpos( $path, '/library/patterns/by-name/' ) ) {
					$name = 'studio-a/' . substr( $path, strlen( '/library/patterns/by-name/' ) );
					if ( isset( $this->library[ $name ] ) ) {
						$body = $this->summary( $name );
					} else {
						$code = 404;
						$body = array( 'code' => 'pbwp_not_found' );
					}
				} else {
					preg_match( '/"slug":"([^"]+)"/', (string) $args['body'], $slug );
					$name = 'studio-a/personal/' . $slug[1];
					if ( ! isset( $this->library[ $name ] ) ) {
						$this->library[ $name ] = $next++;
					}
					$body = $this->summary( $name );
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $code,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * A library pattern as the mocked service summarizes it.
	 *
	 * @param string $name Its cloud name.
	 * @return array
	 */
	private function summary( $name ) {
		return array(
			'id'         => $this->library[ $name ],
			'title'      => 'Uploaded',
			'slug'       => substr( $name, strrpos( $name, '/' ) + 1 ),
			'namespace'  => $name,
			'collection' => array(
				'id'        => 9,
				'owner'     => 7,
				'slug'      => 'personal',
				'title'     => 'Personal',
				'namespace' => 'studio-a/personal',
				'personal'  => true,
			),
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
			if ( 'POST' === $request['method'] && 0 === strpos( $request['path'], '/library/patterns' ) ) {
				$bodies[] = $request['body'];
			}
		}
		return $bodies;
	}

	/**
	 * The paths every pattern create or update was sent to, in order.
	 *
	 * @return string[]
	 */
	private function upload_paths() {
		$paths = array();
		foreach ( $this->seen as $request ) {
			if ( 'POST' === $request['method'] && 0 === strpos( $request['path'], '/library/patterns' ) ) {
				$paths[] = $request['path'];
			}
		}
		return $paths;
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

		// Every member now carries the name of its copy…
		$this->assertSame( 'studio-a/personal/hero', $this->cloud_of( 'simple-theme/hero' ) );
		$this->assertSame( 'studio-a/personal/cta', $this->cloud_of( 'simple-theme/cta' ) );
		$this->assertSame( 'studio-a/personal/page-home', $this->cloud_of( 'simple-theme/page-home' ) );
		$this->assertSame( 'studio-a/personal/page-home', $result['cloud'] );

		// …so a second upload updates all three rather than duplicating any.
		$this->seen = array();
		$again      = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/page-home' );

		$this->assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		$this->assertTrue( $again['updated'] );
		$this->assertSame(
			array(
				'/library/patterns/' . $this->library['studio-a/personal/hero'],
				'/library/patterns/' . $this->library['studio-a/personal/cta'],
				'/library/patterns/' . $this->library['studio-a/personal/page-home'],
			),
			$this->upload_paths()
		);
		$this->assertCount( 3, $this->library );
	}

	public function test_a_section_whose_name_is_another_patterns_copy_is_refused() {
		// Another pattern here was uploaded as studio-a/personal/hero…
		$this->make_theme_pattern( 'hero-copy', 'Older Hero', '<!-- wp:paragraph --><p>Older</p><!-- /wp:paragraph -->', 'studio-a/personal/hero' );
		// …and this page's hero would go up under that same name.
		$this->make_theme_pattern( 'hero', 'Hero', '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->' );
		$this->make_theme_pattern( 'page-home', 'Home Page', $this->reference( 'simple-theme/hero' ) );

		$this->mock_service();
		$this->library['studio-a/personal/hero'] = 50;

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/page-home' );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_name_taken', $result->get_error_code() );
		$this->assertStringContainsString( 'Older Hero', $result->get_error_message() );
		$this->assertSame( array(), $this->uploads() );
	}

	public function test_a_section_keeps_the_copy_it_already_has() {
		// The hero went up on its own, into another collection, first.
		$this->make_theme_pattern( 'hero', 'Hero', '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->', 'studio-a/elsewhere/hero' );
		$this->make_theme_pattern( 'page-home', 'Home Page', $this->reference( 'simple-theme/hero' ) );

		$this->mock_service();

		$result = Pattern_Builder_Cloud_Controller::upload_pattern( 'theme', 'simple-theme/page-home' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		// The page's collection gets a copy of it, since a collection is a
		// closed world; the hero's own reference is left where it was.
		$this->assertArrayHasKey( 'studio-a/personal/hero', $this->library );
		$this->assertSame( 'studio-a/elsewhere/hero', $this->cloud_of( 'simple-theme/hero' ) );
		$this->assertSame( 'studio-a/personal/page-home', $this->cloud_of( 'simple-theme/page-home' ) );
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

		$this->mock_service( 24, 25 );

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

		// Each keeps the name of the cloud pattern it is a copy of.
		$this->assertStringContainsString( 'Cloud: studio-b/heroes/page-home', file_get_contents( $page ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
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
