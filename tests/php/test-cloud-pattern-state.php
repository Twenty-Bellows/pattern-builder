<?php
/**
 * The sidebar Cloud panel's state endpoint: linkage and changed-since-upload
 * tracking via the content hash stored in the link map at upload time.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;
use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud_Porter;

class Test_Cloud_Pattern_State extends WP_UnitTestCase {

	private $post_id;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_test-token' );

		$this->post_id = wp_insert_post(
			array(
				'post_title'   => 'Sidebar Pattern',
				'post_name'    => 'sidebar-pattern',
				'post_content' => '<!-- wp:paragraph --><p>Original copy</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		delete_option( Pattern_Builder_Cloud::OPTION_LINKS );
		parent::tear_down();
	}

	private function state() {
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', $this->post_id );
		return rest_do_request( $request );
	}

	private function upload() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false === strpos( $url, rawurlencode( '/library/patterns' ) ) ) {
					return $pre;
				}
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'    => 42,
							'title' => 'Sidebar Pattern',
						)
					),
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/cloud/upload' );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', $this->post_id );
		return rest_do_request( $request );
	}

	public function test_disconnected_reports_only_that() {
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );

		$response = $this->state();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'connected' => false ), $response->get_data() );
	}

	public function test_unlinked_pattern_is_not_linked() {
		$data = $this->state()->get_data();

		$this->assertTrue( $data['connected'] );
		$this->assertFalse( $data['linked'] );
	}

	public function test_upload_links_and_reads_up_to_date() {
		$this->assertSame( 200, $this->upload()->get_status() );

		$data = $this->state()->get_data();

		$this->assertTrue( $data['linked'] );
		$this->assertFalse( $data['changed'] );
		$this->assertSame( 42, $data['cloudId'] );
		$this->assertGreaterThan( 0, $data['uploadedAt'] );
	}

	public function test_local_edit_flips_changed_and_reupload_clears_it() {
		$this->upload();

		wp_update_post(
			array(
				'ID'           => $this->post_id,
				'post_content' => '<!-- wp:paragraph --><p>Rewritten copy</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertTrue( $this->state()->get_data()['changed'] );

		// The update path (POST /library/patterns/42) refreshes the hash.
		$this->upload();
		$this->assertFalse( $this->state()->get_data()['changed'] );
	}

	public function test_legacy_link_without_hash_reads_as_changed() {
		$key = Pattern_Builder_Cloud_Porter::local_key( 'user', $this->post_id );
		Pattern_Builder_Cloud::set_link( $key, 42 );

		// Simulate a pre-hash link entry.
		$links = get_option( Pattern_Builder_Cloud::OPTION_LINKS );
		unset( $links[ $key ]['hash'], $links[ $key ]['uploadedAt'] );
		update_option( Pattern_Builder_Cloud::OPTION_LINKS, $links, false );

		$data = $this->state()->get_data();

		$this->assertTrue( $data['linked'] );
		$this->assertTrue( $data['changed'] );
		$this->assertSame( 0, $data['uploadedAt'] );
	}

	/**
	 * Downloading somebody else's pattern links it — that is what recognizes
	 * it as already installed — but the cloud copy is not this account's to
	 * update, and the panel reads `owned` to know not to offer one.
	 */
	private function download( $mine, $source = 'directory' ) {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false === strpos( $url, rawurlencode( '/download' ) ) ) {
					return $pre;
				}
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'pbp'     => '1',
							'title'   => 'Another Account Pattern',
							'slug'    => 'somebody-elses-pattern',
							'content' => '<!-- wp:paragraph --><p>Theirs</p><!-- /wp:paragraph -->',
						)
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/cloud/download' );
		$request->set_param( 'source', $source );
		$request->set_param( 'cloudId', 77 );
		$request->set_param( 'destination', 'user' );
		$request->set_param( 'mine', $mine );
		return rest_do_request( $request );
	}

	private function state_of( $post_id ) {
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', $post_id );
		return rest_do_request( $request )->get_data();
	}

	public function test_a_pattern_we_uploaded_is_ours_to_update() {
		$this->upload();

		$this->assertTrue( $this->state()->get_data()['owned'] );
	}

	public function test_a_download_of_somebody_elses_pattern_is_not_ours_to_update() {
		$response = $this->download( false );
		$this->assertSame( 200, $response->get_status() );

		$state = $this->state_of( $response->get_data()['id'] );

		$this->assertTrue( $state['linked'] );
		$this->assertFalse( $state['owned'] );
	}

	public function test_a_download_from_our_own_library_stays_ours() {
		// Nothing in the request says it is ours; the source does.
		$response = $this->download( false, 'library' );

		$this->assertTrue( $this->state_of( $response->get_data()['id'] )['owned'] );
	}

	public function test_a_refused_update_disowns_the_link() {
		$key = Pattern_Builder_Cloud_Porter::local_key( 'user', $this->post_id );
		Pattern_Builder_Cloud::set_link( $key, 42, 'stale-hash' );

		$this->assertTrue( $this->state()->get_data()['owned'] );

		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( false === strpos( $url, rawurlencode( '/library/patterns' ) ) ) {
					return $pre;
				}
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode(
						array(
							'code'    => 'pbwp_forbidden',
							'message' => 'That pattern belongs to another account.',
						)
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/pattern-builder/v1/cloud/upload' );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', $this->post_id );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		// A link made before ownership was recorded corrects itself the
		// first time an update is refused.
		$this->assertFalse( $this->state()->get_data()['owned'] );
	}

	public function test_a_link_from_before_ownership_was_recorded_reads_as_ours() {
		$key = Pattern_Builder_Cloud_Porter::local_key( 'user', $this->post_id );
		Pattern_Builder_Cloud::set_link( $key, 42 );

		$links = get_option( Pattern_Builder_Cloud::OPTION_LINKS );
		unset( $links[ $key ]['owned'] );
		update_option( Pattern_Builder_Cloud::OPTION_LINKS, $links, false );

		$this->assertTrue( $this->state()->get_data()['owned'] );
	}

	public function test_cloud_id_lookup_reports_installed_local_copy() {
		$this->upload();

		// The link map is site truth — the lookup works even disconnected.
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );

		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'cloudId', 42 );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 'user', $data['installed']['type'] );
		$this->assertSame( $this->post_id, $data['installed']['id'] );
		$this->assertSame( 'Sidebar Pattern', $data['installed']['title'] );
	}

	public function test_cloud_id_lookup_ignores_unknown_ids_and_dangling_links() {
		$this->upload();

		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'cloudId', 777 );
		$this->assertNull( rest_do_request( $request )->get_data()['installed'] );

		// A deleted local copy reads as not installed.
		wp_delete_post( $this->post_id, true );
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'cloudId', 42 );
		$this->assertNull( rest_do_request( $request )->get_data()['installed'] );
	}

	public function test_missing_pattern_is_a_404() {
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/cloud/pattern-state' );
		$request->set_param( 'patternType', 'user' );
		$request->set_param( 'patternId', 999999 );

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}
}
