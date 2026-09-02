<?php
/**
 * The cloud abilities: every one refuses when the WordPress user has no
 * connection, the reads relay what the service lists, the writes install
 * and upload through the same paths the browser uses, and none of them
 * changes a visibility or deletes a collection. The service is mocked at
 * the HTTP layer.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Abilities;

class Test_Cloud_Abilities extends WP_UnitTestCase {

	/**
	 * @var Pattern_Builder_Cloud_Abilities
	 */
	private $abilities;

	/**
	 * Every service request the mock saw.
	 *
	 * @var array
	 */
	private $seen = array();

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->abilities = new Pattern_Builder_Cloud_Abilities();
		remove_action( 'wp_abilities_api_init', array( $this->abilities, 'register_abilities' ) );
		$this->seen = array();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_ACCOUNT );
		delete_option( Pattern_Builder_Cloud::OPTION_LINKS );
		delete_option( Pattern_Builder_Cloud::OPTION_COLLECTION_CATEGORIES );
		parent::tear_down();
	}

	private function connect() {
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_test-token' );
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_ACCOUNT, array( 'id' => 7, 'name' => 'Tester' ) );
	}

	private function mock_service( $callback ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $callback ) {
				$query = array();
				wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				if ( empty( $query['rest_route'] ) || 0 !== strpos( $query['rest_route'], '/pbwp/v1' ) ) {
					return $pre;
				}
				$path         = substr( $query['rest_route'], strlen( '/pbwp/v1' ) );
				$method       = isset( $args['method'] ) ? $args['method'] : 'GET';
				$this->seen[] = array(
					'method' => $method,
					'path'   => $path,
					'query'  => $query,
					'body'   => isset( $args['body'] ) ? $args['body'] : '',
				);
				$response = $callback( $path, $method, $query );
				$status   = isset( $response['status'] ) ? (int) $response['status'] : 200;
				unset( $response['status'] );
				return array(
					'headers'  => array(),
					'response' => array( 'code' => $status ),
					'body'     => wp_json_encode( $response ),
				);
			},
			10,
			3
		);
	}

	private function collection() {
		return array(
			'id'         => 31,
			'owner'      => 2,
			'slug'       => 'starter-sections',
			'title'      => 'Starter Sections',
			'visibility' => 'public',
			'personal'   => false,
			'count'      => 2,
		);
	}

	private function package( $id, $title ) {
		return array(
			'format'  => 'pbp/1',
			'title'   => $title,
			'slug'    => sanitize_title( $title ),
			'content' => '<!-- wp:paragraph --><p>' . esc_html( $title ) . '</p><!-- /wp:paragraph -->',
			'assets'  => array(),
			'tokens'  => array(),
		);
	}

	public function test_every_cloud_ability_refuses_without_a_connection() {
		$calls = array(
			'execute_list_collections'      => array(),
			'execute_get_collection'        => array( 'owner' => 2, 'slug' => 'starter-sections' ),
			'execute_search_cloud_patterns' => array( 'search' => 'hero' ),
			'execute_install_collection'    => array( 'owner' => 2, 'slug' => 'starter-sections' ),
			'execute_install_cloud_pattern' => array( 'id' => 101 ),
			'execute_upload_pattern'        => array( 'title' => 'X', 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ),
			'execute_create_collection'     => array( 'name' => 'Secret' ),
		);

		$this->mock_service(
			function () {
				return array();
			}
		);

		foreach ( $calls as $method => $input ) {
			$result = $this->abilities->$method( $input );
			$this->assertWPError( $result, $method );
			$this->assertSame( Pattern_Builder_Cloud_Abilities::NOT_CONNECTED, $result->get_error_code(), $method );
			$this->assertStringContainsString( 'Connect Pattern Builder to your patternbuilderwp.com account on this site first.', $result->get_error_message() );
		}

		// Nothing reached the service, and nothing landed here.
		$this->assertSame( array(), $this->seen );
		$this->assertSame( 0, count( get_posts( array( 'post_type' => 'wp_block', 'post_status' => 'any' ) ) ) );
	}

	public function test_the_seven_register_with_the_methods_intended() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'This WordPress has no Abilities API.' );
		}

		foreach ( Pattern_Builder_Cloud_Abilities::names() as $name ) {
			$this->assertTrue( wp_has_ability( $name ), $name . ' did not register.' );
			$meta = wp_get_ability( $name )->get_meta();
			$this->assertTrue( $meta['show_in_rest'], $name . ' must be reachable over REST.' );
			$this->assertFalse( $meta['annotations']['destructive'], $name . ' must not be destructive.' );
		}

		foreach ( array( 'list-collections', 'get-collection', 'search-cloud-patterns' ) as $read ) {
			$this->assertTrue( wp_get_ability( 'pattern-builder/' . $read )->get_meta()['annotations']['readonly'] );
		}
		foreach ( array( 'install-collection', 'install-cloud-pattern', 'upload-pattern', 'create-collection' ) as $write ) {
			$this->assertFalse( wp_get_ability( 'pattern-builder/' . $write )->get_meta()['annotations']['readonly'] );
		}
	}

	public function test_reads_relay_the_service() {
		$this->connect();
		$this->mock_service(
			function ( $path ) {
				if ( '/directory/collections' === $path ) {
					return array( 'items' => array( $this->collection() ), 'total' => 1, 'pages' => 1 );
				}
				if ( '/library/collections' === $path ) {
					return array( array( 'id' => 9, 'title' => 'Personal', 'personal' => true ) );
				}
				if ( '/directory/collections/2/starter-sections' === $path ) {
					return array_merge( $this->collection(), array( 'patterns' => array( array( 'id' => 101, 'title' => 'Bold Hero' ) ) ) );
				}
				if ( '/directory/patterns' === $path ) {
					return array( 'items' => array( array( 'id' => 101, 'title' => 'Bold Hero', 'collection' => $this->collection() ) ), 'total' => 1, 'pages' => 1 );
				}
				return array();
			}
		);

		$community = $this->abilities->execute_list_collections( array( 'search' => 'starter' ) );
		$this->assertSame( 'Starter Sections', $community['collections'][0]['title'] );
		$this->assertSame( 'starter', $this->seen[0]['query']['search'] );

		$mine = $this->abilities->execute_list_collections( array( 'scope' => 'mine' ) );
		$this->assertTrue( $mine['collections'][0]['personal'] );

		$one = $this->abilities->execute_get_collection( array( 'owner' => 2, 'slug' => 'starter-sections' ) );
		$this->assertSame( 'Starter Sections', $one['collection']['title'] );
		$this->assertSame( 'Bold Hero', $one['patterns'][0]['title'] );
		$this->assertNull( $one['patterns'][0]['installed'] );
		$this->assertArrayNotHasKey( 'patterns', $one['collection'] );

		$found = $this->abilities->execute_search_cloud_patterns( array( 'search' => 'hero', 'collection' => '2/starter-sections' ) );
		$this->assertSame( 'starter-sections', $found['patterns'][0]['collection']['slug'] );
		$this->assertSame( '2/starter-sections', end( $this->seen )['query']['collection'] );
	}

	public function test_install_collection_reports_per_pattern_results() {
		$this->connect();
		$this->mock_service(
			function ( $path ) {
				if ( '/directory/collections/2/starter-sections' === $path ) {
					return array_merge(
						$this->collection(),
						array(
							'patterns' => array(
								array( 'id' => 101, 'title' => 'Bold Hero' ),
								array( 'id' => 102, 'title' => 'Locked One' ),
							),
						)
					);
				}
				if ( '/directory/patterns/101/download' === $path ) {
					return $this->package( 101, 'Bold Hero' );
				}
				if ( '/directory/patterns/102/download' === $path ) {
					return array( 'status' => 403, 'code' => 'pbwp_premium_required', 'message' => 'Upgrade to Pro.', 'data' => array( 'status' => 403 ) );
				}
				return array();
			}
		);

		$result = $this->abilities->execute_install_collection( array( 'owner' => 2, 'slug' => 'starter-sections', 'destination' => 'user', 'tokens' => 'skip' ) );

		$this->assertSame( 1, $result['installed'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'installed', $result['results'][0]['status'] );
		$this->assertSame( 'user', $result['results'][0]['type'] );
		$this->assertSame( 'failed', $result['results'][1]['status'] );
		$this->assertSame( 'Upgrade to Pro.', $result['results'][1]['message'] );

		$terms = wp_get_object_terms( $result['results'][0]['id'], 'wp_pattern_category', array( 'fields' => 'slugs' ) );
		$this->assertContains( 'pbwp-2-starter-sections', $terms );

		// Run again: the one that landed is skipped, the other tried again.
		$again = $this->abilities->execute_install_collection( array( 'owner' => 2, 'slug' => 'starter-sections' ) );
		$this->assertSame( 1, $again['skipped'] );
		$this->assertSame( 0, $again['installed'] );
	}

	public function test_install_cloud_pattern_lands_the_pattern() {
		$this->connect();
		$this->mock_service(
			function ( $path ) {
				if ( '/directory/patterns/101/download' === $path ) {
					return $this->package( 101, 'Bold Hero' );
				}
				if ( '/directory/patterns/101' === $path ) {
					return array( 'id' => 101, 'title' => 'Bold Hero', 'collection' => $this->collection() );
				}
				return array();
			}
		);

		$result = $this->abilities->execute_install_cloud_pattern( array( 'id' => 101, 'destination' => 'user' ) );

		$this->assertSame( 'user', $result['pattern']['type'] );
		$this->assertSame( 'Bold Hero', get_post( $result['pattern']['id'] )->post_title );
		$this->assertSame( 101, Pattern_Builder_Cloud::links()[ 'user:' . $result['pattern']['id'] ]['cloudId'] );
	}

	public function test_upload_pattern_takes_markup_and_defaults_to_personal() {
		$this->connect();
		$this->mock_service(
			function ( $path ) {
				if ( '/library/patterns' === $path ) {
					return array( 'id' => 42, 'title' => 'Fresh', 'collection' => array( 'id' => 9, 'owner' => 7, 'slug' => 'personal', 'title' => 'Personal', 'personal' => true ) );
				}
				return array();
			}
		);

		$result = $this->abilities->execute_upload_pattern(
			array(
				'title'   => 'Fresh',
				'content' => '<!-- wp:paragraph --><p>Fresh</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( 42, $result['pattern']['id'] );
		$this->assertFalse( $result['updated'] );
		$this->assertSame( 'user', $result['local']['type'] );
		$this->assertSame( 'Fresh', get_post( $result['local']['id'] )->post_title );
		$this->assertStringContainsString( 'name="collection"' . "\r\n\r\npersonal", end( $this->seen )['body'] );

		// Into a named collection, by a local pattern's id.
		$again = $this->abilities->execute_upload_pattern( array( 'id' => (string) $result['local']['id'], 'collection' => '31' ) );
		$this->assertTrue( $again['updated'] );
		$this->assertStringContainsString( 'name="collection"' . "\r\n\r\n31", end( $this->seen )['body'] );
	}

	public function test_create_collection_is_always_private_and_relays_the_refusal() {
		$this->connect();
		$this->mock_service(
			function ( $path, $method ) {
				if ( '/library/collections' === $path && 'POST' === $method ) {
					return array(
						'status'  => 403,
						'code'    => 'pbwp_private_requires_pro',
						'message' => 'Private collections are a Pattern Builder Pro feature.',
						'data'    => array( 'status' => 403, 'upgrade_url' => 'https://patternbuilderwp.com/go-pro/' ),
					);
				}
				return array();
			}
		);

		$result = $this->abilities->execute_create_collection( array( 'name' => 'Secret', 'description' => 'Mine.' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pbwp_private_requires_pro', $result->get_error_code() );
		$this->assertSame( 'https://patternbuilderwp.com/go-pro/', $result->get_error_data()['upgrade_url'] );

		$sent = json_decode( end( $this->seen )['body'], true );
		$this->assertSame( 'private', $sent['visibility'] );
		$this->assertSame( 'Secret', $sent['name'] );
	}

	public function test_no_ability_publishes_or_deletes() {
		$this->connect();
		$this->mock_service(
			function () {
				return array( 'id' => 31, 'visibility' => 'private' );
			}
		);

		$this->abilities->execute_create_collection( array( 'name' => 'Quiet' ) );
		$this->abilities->execute_list_collections( array( 'scope' => 'mine' ) );

		foreach ( $this->seen as $request ) {
			$this->assertNotSame( 'DELETE', $request['method'] );
			$this->assertNotSame( 'PUT', $request['method'] );
			$this->assertStringNotContainsString( '"visibility":"public"', (string) $request['body'] );
		}
	}
}
