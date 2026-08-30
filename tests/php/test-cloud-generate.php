<?php
/**
 * The cloud generate proxy: forwarding, connection gating, and error
 * passthrough (upgrade URLs included). The service itself is mocked at the
 * HTTP layer.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;

class Test_Cloud_Generate extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_test-token' );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		parent::tear_down();
	}

	private function request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	private function mock_service( $callback ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $callback ) {
				// The client targets ?rest_route= URLs, so the path is encoded.
				if ( false === strpos( $url, rawurlencode( '/pbwp/v1' ) ) ) {
					return $pre;
				}
				return $callback( $args, $url );
			},
			10,
			3
		);
	}

	public function test_generate_requires_connection() {
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );

		$response = $this->request( 'POST', '/pattern-builder/v1/cloud/generate', array( 'prompt' => 'a hero' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pb_cloud_disconnected', $response->get_data()['code'] );
	}

	public function test_generate_forwards_prompt_and_returns_job() {
		$seen = array();
		$this->mock_service(
			function ( $args, $url ) use ( &$seen ) {
				$seen[] = array(
					'url'  => $url,
					'body' => $args['body'],
					'auth' => isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '',
				);
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'       => 7,
							'status'   => 'queued',
							'provider' => 'mock',
						)
					),
				);
			}
		);

		$response = $this->request( 'POST', '/pattern-builder/v1/cloud/generate', array( 'prompt' => 'a hero section' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'queued', $response->get_data()['status'] );

		$this->assertStringContainsString( rawurlencode( '/ai/generations' ), $seen[0]['url'] );
		$this->assertStringContainsString( 'a hero section', $seen[0]['body'] );
		$this->assertSame( 'Bearer pbwp_test-token', $seen[0]['auth'] );
	}

	public function test_generate_poll_forwards_and_passes_through() {
		$this->mock_service(
			function ( $args, $url ) {
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'     => 7,
							'status' => 'succeeded',
							'pattern' => array( 'id' => 99, 'title' => 'Generated' ),
						)
					),
				);
			}
		);

		$response = $this->request( 'GET', '/pattern-builder/v1/cloud/generate/7' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Generated', $response->get_data()['pattern']['title'] );
	}

	public function test_service_errors_pass_through_with_upgrade_url() {
		$this->mock_service(
			function () {
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode(
						array(
							'code'    => 'pbwp_pro_required',
							'message' => 'AI pattern generation is part of Pattern Builder Pro.',
							'data'    => array(
								'status'      => 403,
								'upgrade_url' => 'http://service.example/pricing/',
							),
						)
					),
				);
			}
		);

		$response = $this->request( 'POST', '/pattern-builder/v1/cloud/generate', array( 'prompt' => 'a hero' ) );

		$this->assertSame( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'pbwp_pro_required', $data['code'] );
		$this->assertSame( 'http://service.example/pricing/', $data['data']['upgrade_url'] );
	}
}
