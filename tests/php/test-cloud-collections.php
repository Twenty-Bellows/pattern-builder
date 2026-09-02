<?php
/**
 * Collections through the proxy: the routes relay the service's answers —
 * refusals verbatim, upgrade link included — and a whole collection installs
 * in one action, skipping what is already here, carrying on past a failure,
 * and filing every pattern under the collection's local category. The
 * service is mocked at the HTTP layer, as every cloud test is.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter;

class Test_Cloud_Collections extends WP_UnitTestCase {

	/**
	 * Every service request the mock saw: method, decoded path, body.
	 *
	 * @var array
	 */
	private $seen = array();

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_test-token' );
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_ACCOUNT, array( 'id' => 7, 'name' => 'Tester' ) );
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

	private function request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * Mock the service: the callback gets the decoded pbwp/v1 path, the
	 * method and the request, and returns the response body (an array) or
	 * an error as { code, message, data, status }.
	 *
	 * @param callable $callback function ( $path, $method, $args ) : array
	 */
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

				$response = $callback( $path, $method, $args, $query );
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

	/**
	 * A collection as the service summarizes it.
	 */
	private function collection_summary( $overrides = array() ) {
		return array_merge(
			array(
				'id'         => 31,
				'owner'      => 2,
				'slug'       => 'starter-sections',
				'title'      => 'Starter Sections',
				'visibility' => 'public',
				'personal'   => false,
				'count'      => 2,
				'previews'   => array(),
			),
			$overrides
		);
	}

	/**
	 * A directory pattern summary in that collection.
	 */
	private function pattern_summary( $id, $title ) {
		return array(
			'id'         => $id,
			'title'      => $title,
			'collection' => $this->collection_summary(),
			'tokens'     => array(),
			'mine'       => false,
		);
	}

	/**
	 * A package the download route hands back.
	 */
	private function package( $id, $title ) {
		return array(
			'format'             => 'pbp/1',
			'title'              => $title,
			'slug'               => sanitize_title( $title ),
			'description'        => '',
			'collection'         => 'starter-sections',
			'inserterCategories' => array( 'banner' ),
			'content'            => '<!-- wp:paragraph --><p>' . esc_html( $title ) . '</p><!-- /wp:paragraph -->',
			'assets'             => array(),
			'tokens'             => array(),
			'origin'             => array( 'cloudId' => $id ),
		);
	}

	public function test_every_collection_route_refuses_disconnected() {
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );

		$this->assertSame( 401, $this->request( 'GET', '/pattern-builder/v1/cloud/collections' )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', '/pattern-builder/v1/cloud/collections/2/starter-sections' )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/pattern-builder/v1/cloud/library/collections' )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/pattern-builder/v1/cloud/library/collections', array( 'name' => 'X' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', '/pattern-builder/v1/cloud/library/collections/31', array( 'name' => 'X' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'DELETE', '/pattern-builder/v1/cloud/library/collections/31', array( 'patterns' => 'delete' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', '/pattern-builder/v1/cloud/library/42', array( 'collection' => 'personal' ) )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', '/pattern-builder/v1/cloud/download', array( 'cloudId' => 42 ) )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/pattern-builder/v1/cloud/categories' )->get_status() );
	}

	public function test_status_relays_entitlements_personal_and_over_policy() {
		$this->mock_service(
			function ( $path ) {
				return array(
					'account'      => array( 'id' => 7, 'name' => 'Tester' ),
					'tier'         => 'free',
					'usage'        => array( 'stored' => 3 ),
					'entitlements' => array( 'personal_cap' => 25, 'can_create_private' => false, 'fair_use' => array( 'patterns' => 2000, 'collections' => 200 ) ),
					'personal'     => array( 'id' => 9, 'count' => 3, 'cap' => 25 ),
					'over_policy'  => true,
				);
			}
		);

		$status = $this->request( 'GET', '/pattern-builder/v1/cloud/status' )->get_data();

		$this->assertSame( 25, $status['entitlements']['personal_cap'] );
		$this->assertFalse( $status['entitlements']['can_create_private'] );
		$this->assertSame( array( 'id' => 9, 'count' => 3, 'cap' => 25 ), $status['personal'] );
		$this->assertTrue( $status['overPolicy'] );
	}

	public function test_service_refusals_are_relayed_verbatim() {
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
				if ( 0 === strpos( $path, '/library/collections/31' ) && 'DELETE' === $method ) {
					return array(
						'status'  => 403,
						'code'    => 'pbwp_personal_cap',
						'message' => 'Personal holds up to 25 patterns on a free account.',
						'data'    => array( 'status' => 403, 'upgrade_url' => 'https://patternbuilderwp.com/go-pro/' ),
					);
				}
				return array();
			}
		);

		$created = $this->request( 'POST', '/pattern-builder/v1/cloud/library/collections', array( 'name' => 'Secret', 'visibility' => 'private' ) );
		$this->assertSame( 403, $created->get_status() );
		$this->assertSame( 'pbwp_private_requires_pro', $created->get_data()['code'] );
		$this->assertSame( 'Private collections are a Pattern Builder Pro feature.', $created->get_data()['message'] );
		$this->assertSame( 'https://patternbuilderwp.com/go-pro/', $created->get_data()['data']['upgrade_url'] );

		$moved = $this->request( 'DELETE', '/pattern-builder/v1/cloud/library/collections/31', array( 'patterns' => 'move' ) );
		$this->assertSame( 403, $moved->get_status() );
		$this->assertSame( 'pbwp_personal_cap', $moved->get_data()['code'] );
		$this->assertSame( 'https://patternbuilderwp.com/go-pro/', $moved->get_data()['data']['upgrade_url'] );

		// The move was asked for as such.
		$last = end( $this->seen );
		$this->assertSame( 'move', $last['query']['patterns'] );
	}

	public function test_collection_routes_relay_what_the_service_lists() {
		$this->mock_service(
			function ( $path, $method, $args, $query ) {
				if ( '/directory/collections' === $path ) {
					return array( 'items' => array( $this->collection_summary() ), 'total' => 1, 'pages' => 1 );
				}
				if ( '/directory/collections/2/starter-sections' === $path ) {
					return array_merge( $this->collection_summary(), array( 'patterns' => array( $this->pattern_summary( 101, 'Bold Hero' ) ) ) );
				}
				if ( '/library/collections' === $path ) {
					return array( array( 'id' => 9, 'title' => 'Personal', 'personal' => true, 'count' => 3 ) );
				}
				return array();
			}
		);

		$listed = $this->request( 'GET', '/pattern-builder/v1/cloud/collections', array( 'search' => 'starter', 'page' => 2 ) )->get_data();
		$this->assertSame( 'Starter Sections', $listed['items'][0]['title'] );
		$first = $this->seen[0];
		$this->assertSame( 'starter', $first['query']['search'] );
		$this->assertSame( '2', $first['query']['page'] );

		$one = $this->request( 'GET', '/pattern-builder/v1/cloud/collections/2/starter-sections' )->get_data();
		$this->assertSame( 'Bold Hero', $one['patterns'][0]['title'] );
		$this->assertNull( $one['patterns'][0]['installed'] );

		$mine = $this->request( 'GET', '/pattern-builder/v1/cloud/library/collections' )->get_data();
		$this->assertTrue( $mine[0]['personal'] );

		// The directory and library listings pass the collection filter on.
		$this->request( 'GET', '/pattern-builder/v1/cloud/directory', array( 'collection' => '2/starter-sections' ) );
		$this->assertSame( '2/starter-sections', end( $this->seen )['query']['collection'] );
		$this->request( 'GET', '/pattern-builder/v1/cloud/library', array( 'collection' => '9' ) );
		$this->assertSame( '9', end( $this->seen )['query']['collection'] );
	}

	public function test_upload_names_its_collection_and_the_link_map_records_it() {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Local One',
				'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);

		$this->mock_service(
			function ( $path, $method ) {
				if ( '/library/patterns' === $path ) {
					return array( 'id' => 42, 'title' => 'Local One', 'collection' => $this->collection_summary( array( 'id' => 31 ) ) );
				}
				return array();
			}
		);

		$response = $this->request( 'POST', '/pattern-builder/v1/cloud/upload', array( 'patternType' => 'user', 'patternId' => $post_id, 'collection' => 31 ) );
		$this->assertSame( 200, $response->get_status() );

		// The multipart body carries the collection beside the package.
		$sent = end( $this->seen );
		$this->assertStringContainsString( 'name="collection"' . "\r\n\r\n31", $sent['body'] );
		$this->assertStringContainsString( '"inserterCategories"', $sent['body'] );
		$this->assertStringNotContainsString( '"categories"', $sent['body'] );

		$links = Pattern_Builder_Cloud::links();
		$this->assertSame( 'starter-sections', $links[ 'user:' . $post_id ]['collection']['slug'] );
		$this->assertSame( 'Starter Sections', $links[ 'user:' . $post_id ]['collection']['title'] );

		$state = $this->request( 'GET', '/pattern-builder/v1/cloud/pattern-state', array( 'patternType' => 'user', 'patternId' => $post_id ) )->get_data();
		$this->assertSame( 'starter-sections', $state['collection']['slug'] );
	}

	public function test_upload_defaults_to_personal_when_nothing_is_asked() {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Local Two',
				'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
		$this->mock_service(
			function () {
				return array( 'id' => 43, 'title' => 'Local Two' );
			}
		);

		$this->request( 'POST', '/pattern-builder/v1/cloud/upload', array( 'patternType' => 'user', 'patternId' => $post_id ) );
		$this->assertStringContainsString( 'name="collection"' . "\r\n\r\npersonal", end( $this->seen )['body'] );
	}

	public function test_move_relays_a_collection_alone() {
		$this->mock_service(
			function ( $path, $method ) {
				if ( '/library/patterns/42' === $path && 'PUT' === $method ) {
					return array( 'id' => 42, 'collection' => array( 'id' => 9, 'personal' => true ) );
				}
				return array();
			}
		);

		$moved = $this->request( 'PUT', '/pattern-builder/v1/cloud/library/42', array( 'collection' => 'personal' ) );
		$this->assertSame( 200, $moved->get_status() );
		$this->assertSame( array( 'collection' => 'personal' ), json_decode( end( $this->seen )['body'], true ) );

		$this->assertSame( 400, $this->request( 'PUT', '/pattern-builder/v1/cloud/library/42' )->get_status() );
	}

	public function test_download_files_the_pattern_under_the_collection_category() {
		$this->mock_service(
			function ( $path ) {
				if ( '/directory/patterns/101/download' === $path ) {
					return $this->package( 101, 'Bold Hero' );
				}
				return array();
			}
		);

		$result = $this->request(
			'POST',
			'/pattern-builder/v1/cloud/download',
			array(
				'source'      => 'directory',
				'cloudId'     => 101,
				'destination' => 'user',
				'collection'  => $this->collection_summary(),
			)
		)->get_data();

		$this->assertSame( 'user', $result['type'] );
		$terms = wp_get_object_terms( $result['id'], 'wp_pattern_category', array( 'fields' => 'slugs' ) );
		$this->assertContains( 'pbwp-2-starter-sections', $terms );
		$this->assertContains( 'banner', $terms );

		// The inserter learns the collection's title for that slug.
		$this->assertSame( array( 'pbwp-2-starter-sections' => 'Starter Sections' ), Pattern_Builder_Cloud::collection_categories() );
		Pattern_Builder_Cloud::register_collection_categories();
		$registered = WP_Block_Pattern_Categories_Registry::get_instance()->get_registered( 'pbwp-2-starter-sections' );
		$this->assertSame( 'Starter Sections', $registered['label'] );
		$term = get_term_by( 'slug', 'pbwp-2-starter-sections', 'wp_pattern_category' );
		$this->assertSame( 'Starter Sections', $term->name );

		$links = Pattern_Builder_Cloud::links();
		$this->assertSame( 'starter-sections', $links[ 'user:' . $result['id'] ]['collection']['slug'] );
	}

	public function test_download_asks_the_directory_which_collection_when_not_told() {
		$this->mock_service(
			function ( $path ) {
				if ( '/directory/patterns/101/download' === $path ) {
					return $this->package( 101, 'Bold Hero' );
				}
				if ( '/directory/patterns/101' === $path ) {
					return $this->pattern_summary( 101, 'Bold Hero' );
				}
				return array();
			}
		);

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->install_cloud_pattern( 101, 'user', false );

		$terms = wp_get_object_terms( $result['id'], 'wp_pattern_category', array( 'fields' => 'slugs' ) );
		$this->assertContains( 'pbwp-2-starter-sections', $terms );
	}

	public function test_install_collection_skips_installed_continues_past_failure_and_files_each() {
		// One pattern of the collection is already installed from it.
		$already = wp_insert_post(
			array(
				'post_title'   => 'Already Here',
				'post_content' => '<!-- wp:paragraph --><p>Here</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
		Pattern_Builder_Cloud::set_link( 'user:' . $already, 100, '', false, $this->collection_summary() );

		$this->mock_service(
			function ( $path ) {
				if ( '/directory/collections/2/starter-sections' === $path ) {
					return array_merge(
						$this->collection_summary( array( 'count' => 3 ) ),
						array(
							'patterns' => array(
								$this->pattern_summary( 100, 'Already Here' ),
								$this->pattern_summary( 101, 'Bold Hero' ),
								$this->pattern_summary( 102, 'Broken One' ),
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

		$porter = new Pattern_Builder_Cloud_Porter();
		$result = $porter->install_collection( 2, 'starter-sections', 'user', 'skip' );

		$this->assertSame( 1, $result['installed'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'Starter Sections', $result['collection']['title'] );
		$this->assertArrayNotHasKey( 'patterns', $result['collection'] );

		$by_id = wp_list_pluck( $result['results'], 'status', 'cloudId' );
		$this->assertSame( 'skipped', $by_id[100] );
		$this->assertSame( 'installed', $by_id[101] );
		$this->assertSame( 'failed', $by_id[102] );
		$this->assertSame( 'Upgrade to Pro.', $result['results'][2]['message'] );

		// The download of 102 was attempted after 101 landed: no stop on failure.
		$paths = wp_list_pluck( $this->seen, 'path' );
		$this->assertContains( '/directory/patterns/102/download', $paths );
		$this->assertNotContains( '/directory/patterns/100/download', $paths );

		$installed = $result['results'][1];
		$terms     = wp_get_object_terms( $installed['id'], 'wp_pattern_category', array( 'fields' => 'slugs' ) );
		$this->assertContains( 'pbwp-2-starter-sections', $terms );
		$this->assertSame( 'starter-sections', Pattern_Builder_Cloud::links()[ 'user:' . $installed['id'] ]['collection']['slug'] );
	}

	public function test_collection_view_marks_installed_patterns() {
		$already = wp_insert_post(
			array(
				'post_title'   => 'Already Here',
				'post_content' => '<!-- wp:paragraph --><p>Here</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
		Pattern_Builder_Cloud::set_link( 'user:' . $already, 100, '', false, $this->collection_summary() );

		$this->mock_service(
			function ( $path ) {
				if ( '/directory/collections/2/starter-sections' === $path ) {
					return array_merge( $this->collection_summary(), array( 'patterns' => array( $this->pattern_summary( 100, 'Already Here' ), $this->pattern_summary( 101, 'Bold Hero' ) ) ) );
				}
				return array();
			}
		);

		$one = $this->request( 'GET', '/pattern-builder/v1/cloud/collections/2/starter-sections' )->get_data();
		$this->assertSame( 'user', $one['patterns'][0]['installed']['type'] );
		$this->assertSame( 'starter-sections', $one['patterns'][0]['installed']['collection']['slug'] );
		$this->assertNull( $one['patterns'][1]['installed'] );
	}
}
