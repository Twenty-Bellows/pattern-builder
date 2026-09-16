<?php
/**
 * The sidebar Cloud panel's state endpoint and the `Cloud:` reference it reads: a pattern
 * carries the name of its copy on the cloud, and whether that copy exists — and is the
 * connected account's — is asked of the service each time.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;
use TwentyBellows\PatternBuilder\Pattern_File_Store;

class Test_Cloud_Pattern_State extends WP_UnitTestCase {
	private $post_id;

	/**
	 * The fake service's library: cloud name => cloud id.
	 *
	 * @var array
	 */
	private $library = array();

	/**
	 * Every route the fake was asked, as "METHOD /route".
	 *
	 * @var string[]
	 */
	private $asked = array();

	private $next_id = 40;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_test-token' );
		$this->connect_as( 'me' );

		$this->post_id = wp_insert_post(
			array(
				'post_title'   => 'Sidebar Pattern',
				'post_name'    => 'sidebar-pattern',
				'post_content' => '<!-- wp:paragraph --><p>Original copy</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);

		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_ACCOUNT );
		parent::tear_down();
	}

	private function connect_as( $handle ) {
		update_user_meta(
			get_current_user_id(),
			Pattern_Builder_Cloud::META_ACCOUNT,
			array(
				'id'     => 7,
				'handle' => $handle,
			)
		);
	}

	/**
	 * The fake service.
	 *
	 * @param mixed  $pre Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url Request URL.
	 * @return mixed
	 */
	public function answer( $pre, $args, $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$route  = isset( $query['rest_route'] ) ? (string) $query['rest_route'] : '';
		$method = isset( $args['method'] ) ? $args['method'] : 'GET';

		$this->asked[] = $method . ' ' . $route;

		if ( preg_match( '#^/pbwp/v1/library/patterns/by-name/([^/]+)/([^/]+)$#', $route, $found ) ) {
			$name = 'me/' . $found[1] . '/' . $found[2];
			return isset( $this->library[ $name ] )
				? $this->reply( 200, $this->summary( $name ) )
				: $this->reply( 404, array( 'code' => 'pbwp_not_found' ) );
		}

		if ( '/pbwp/v1/library/patterns' === $route && 'POST' === $method ) {
			preg_match( '/"slug":"([^"]+)"/', (string) $args['body'], $slug );
			$name                   = 'me/personal/' . $slug[1];
			$this->library[ $name ] = ++$this->next_id;
			return $this->reply( 200, $this->summary( $name ) );
		}

		if ( preg_match( '#^/pbwp/v1/library/patterns/(\d+)$#', $route, $found ) ) {
			$name = array_search( (int) $found[1], $this->library, true );
			if ( false === $name ) {
				return $this->reply( 404, array( 'code' => 'pbwp_not_found' ) );
			}
			if ( 'DELETE' === $method ) {
				unset( $this->library[ $name ] );
				return $this->reply( 200, array( 'deleted' => true ) );
			}
			return $this->reply( 200, $this->summary( $name ) );
		}

		if ( preg_match( '#^/pbwp/v1/(library|directory)/patterns/(\d+)/download$#', $route, $found ) ) {
			$name = 'library' === $found[1] ? array_search( (int) $found[2], $this->library, true ) : 'studio/heroes/hero';
			return $this->reply(
				200,
				array(
					'format'    => 'pbp/1',
					'title'     => 'Downloaded Hero',
					'slug'      => 'hero',
					'namespace' => $name,
					'content'   => '<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->',
				)
			);
		}

		if ( preg_match( '#^/pbwp/v1/directory/patterns/\d+$#', $route ) ) {
			return $this->reply( 404, array( 'code' => 'pbwp_not_found' ) );
		}

		return $pre;
	}

	private function summary( $name ) {
		$slug = substr( $name, strrpos( $name, '/' ) + 1 );
		return array(
			'id'         => $this->library[ $name ],
			'title'      => 'Sidebar Pattern',
			'slug'       => $slug,
			'namespace'  => $name,
			'collection' => array(
				'id'        => 9,
				'slug'      => 'personal',
				'title'     => 'Personal',
				'namespace' => 'me/personal',
				'personal'  => true,
			),
		);
	}

	private function reply( $code, $body ) {
		return array(
			'headers'  => array(),
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $body ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function state( $post_id = null ) {
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', $post_id ? $post_id : $this->post_id );
		return rest_do_request( $request );
	}

	private function upload() {
		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/cloud/upload' );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', $this->post_id );
		return rest_do_request( $request );
	}

	private function reference( $post_id = null ) {
		return (string) get_post_meta( $post_id ? $post_id : $this->post_id, Pattern_File_Store::META_CLOUD, true );
	}

	public function test_disconnected_reports_only_that() {
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );

		$response = $this->state();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'connected' => false ), $response->get_data() );
	}

	public function test_a_pattern_never_uploaded_is_not_on_the_cloud() {
		$data = $this->state()->get_data();

		$this->assertTrue( $data['connected'] );
		$this->assertFalse( $data['linked'] );
		$this->assertSame( array(), $this->asked );
	}

	public function test_an_upload_leaves_the_pattern_carrying_its_cloud_name() {
		$response = $this->upload();
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['updated'] );
		$this->assertSame( 'me/personal/sidebar-pattern', $response->get_data()['cloud'] );

		$this->assertSame( 'me/personal/sidebar-pattern', $this->reference() );

		$data = $this->state()->get_data();
		$this->assertTrue( $data['linked'] );
		$this->assertSame( $this->library['me/personal/sidebar-pattern'], $data['cloudId'] );
		$this->assertSame( 'Personal', $data['collection']['title'] );
		$record = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/blocks/' . $this->post_id ) )->get_data();
		$this->assertSame( 'me/personal/sidebar-pattern', $record['cloud'] );
	}

	public function test_uploading_again_updates_the_same_copy() {
		$this->upload();
		$first = $this->library['me/personal/sidebar-pattern'];

		$response = $this->upload();

		$this->assertTrue( $response->get_data()['updated'] );
		$this->assertCount( 1, $this->library );
		$this->assertContains( 'POST /pbwp/v1/library/patterns/' . $first, $this->asked );
	}

	public function test_a_copy_deleted_on_the_cloud_reads_as_not_on_the_cloud() {
		$this->upload();
		$this->library = array();

		$this->assertFalse( $this->state()->get_data()['linked'] );
		$response = $this->upload();
		$this->assertFalse( $response->get_data()['updated'] );
		$this->assertArrayHasKey( 'me/personal/sidebar-pattern', $this->library );
	}

	/**
	 * The case that retired the site-wide link map: an upload made by one account read as
	 * "in your cloud library" to whoever connected next.
	 */
	public function test_another_account_connected_here_sees_no_copy() {
		$this->upload();

		$this->connect_as( 'someone-else' );

		$this->assertFalse( $this->state()->get_data()['linked'] );
	}

	public function test_a_reference_to_somebody_elses_pattern_is_not_updated() {
		update_post_meta( $this->post_id, Pattern_File_Store::META_CLOUD, 'studio/heroes/sidebar-pattern' );

		$this->assertFalse( $this->state()->get_data()['linked'] );

		$response = $this->upload();
		$this->assertFalse( $response->get_data()['updated'] );
		$this->assertSame( 'me/personal/sidebar-pattern', $this->reference() );
		foreach ( $this->asked as $asked ) {
			$this->assertStringNotContainsString( 'studio', $asked );
		}
	}

	public function test_deleting_from_the_cloud_forgets_the_reference() {
		$this->upload();
		$cloud_id = $this->library['me/personal/sidebar-pattern'];

		$request = new WP_REST_Request( 'DELETE', '/pattern-builder/v1/cloud/library/' . $cloud_id );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', $this->post_id );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$this->assertSame( array(), $this->library );
		$this->assertSame( '', $this->reference() );
		$this->assertFalse( $this->state()->get_data()['linked'] );
	}

	public function test_name_lookup_reports_the_local_copy() {
		$this->upload();
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );

		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'name', 'me/personal/sidebar-pattern' );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 'user', $data['installed']['type'] );
		$this->assertSame( $this->post_id, $data['installed']['id'] );
		$this->assertSame( 'Sidebar Pattern', $data['installed']['title'] );

		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'name', 'me/personal/nothing-here' );
		$this->assertNull( rest_do_request( $request )->get_data()['installed'] );
		wp_delete_post( $this->post_id, true );
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'name', 'me/personal/sidebar-pattern' );
		$this->assertNull( rest_do_request( $request )->get_data()['installed'] );
	}

	private function download( $source, $cloud_id ) {
		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/cloud/download' );
		$request->set_param( 'source', $source );
		$request->set_param( 'cloudId', $cloud_id );
		$request->set_param( 'destination', 'user' );
		return rest_do_request( $request );
	}

	public function test_a_download_of_somebody_elses_pattern_carries_its_name_and_offers_no_update() {
		$response = $this->download( 'directory', 77 );
		$this->assertSame( 200, $response->get_status() );

		$post_id = $response->get_data()['id'];
		$this->assertSame( 'studio/heroes/hero', $this->reference( $post_id ) );
		$this->assertFalse( $this->state( $post_id )->get_data()['linked'] );
	}

	public function test_a_download_from_our_own_library_can_be_updated_from_here() {
		$this->library['me/personal/hero'] = 55;

		$response = $this->download( 'library', 55 );
		$post_id  = $response->get_data()['id'];

		$this->assertSame( 'me/personal/hero', $this->reference( $post_id ) );

		$state = $this->state( $post_id )->get_data();
		$this->assertTrue( $state['linked'] );
		$this->assertSame( 55, $state['cloudId'] );
	}

	public function test_missing_pattern_is_a_404() {
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', 999999 );

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}
}
