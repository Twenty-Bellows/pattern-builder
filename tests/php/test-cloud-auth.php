<?php
/**
 * The in-admin connect flow: /cloud/login and /cloud/signup relay
 * credentials to the service server-side and store the returned grant.
 * The service itself is mocked at the HTTP layer.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Cloud;

class Test_Cloud_Auth extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_ACCOUNT );
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

	private function grant_response() {
		return array(
			'headers'  => array(),
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'token'   => 'pbwp_' . str_repeat( 'a', 64 ),
					'account' => array(
						'id'   => 12,
						'name' => 'Demo Person',
						'tier' => 'free',
					),
				)
			),
		);
	}

	public function test_login_relays_credentials_and_stores_grant() {
		$seen = array();
		$this->mock_service(
			function ( $args, $url ) use ( &$seen ) {
				if ( false !== strpos( $url, rawurlencode( '/auth/login' ) ) ) {
					$seen[] = $args['body'];
					return $this->grant_response();
				}
				// The follow-up /me from the status payload.
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'account' => array(
								'id'   => 12,
								'name' => 'Demo Person',
							),
							'tier'    => 'free',
							'usage'   => array( 'stored' => 0 ),
						)
					),
				);
			}
		);

		$response = $this->request(
			'POST',
			'/pattern-builder/v1/cloud/login',
			array(
				'email'    => 'demo@example.test',
				'password' => 'correct-horse-battery',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['connected'] );

		// Credentials were relayed with this site's identity attached.
		$this->assertCount( 1, $seen );
		$this->assertSame( 'demo@example.test', $seen[0]['email'] );
		$this->assertSame( 'correct-horse-battery', $seen[0]['password'] );
		$this->assertSame( home_url(), $seen[0]['site'] );
		$this->assertSame( wp_get_current_user()->user_login, $seen[0]['site_user'] );

		// The grant landed in user meta.
		$this->assertTrue( Pattern_Builder_Cloud::is_connected() );
		$account = Pattern_Builder_Cloud::account();
		$this->assertSame( 'Demo Person', $account['name'] );
	}

	public function test_signup_relays_name_too() {
		$seen = array();
		$this->mock_service(
			function ( $args, $url ) use ( &$seen ) {
				if ( false !== strpos( $url, rawurlencode( '/auth/signup' ) ) ) {
					$seen[] = $args['body'];
					return $this->grant_response();
				}
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'account' => array( 'id' => 12 ),
							'tier'    => 'free',
							'usage'   => array(),
						)
					),
				);
			}
		);

		$response = $this->request(
			'POST',
			'/pattern-builder/v1/cloud/signup',
			array(
				'email'    => 'fresh@example.test',
				'password' => 'correct-horse-battery',
				'name'     => 'Fresh Person',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $seen );
		$this->assertSame( 'Fresh Person', $seen[0]['name'] );
		$this->assertTrue( Pattern_Builder_Cloud::is_connected() );
	}

	public function test_login_passes_service_errors_through_untouched() {
		$this->mock_service(
			function () {
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode(
						array(
							'code'    => 'pbwp_bad_credentials',
							'message' => 'That email and password combination is not right.',
						)
					),
				);
			}
		);

		$response = $this->request(
			'POST',
			'/pattern-builder/v1/cloud/login',
			array(
				'email'    => 'demo@example.test',
				'password' => 'wrong',
			)
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'pbwp_bad_credentials', $response->get_data()['code'] );
		$this->assertFalse( Pattern_Builder_Cloud::is_connected() );
	}

	public function test_login_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->request(
			'POST',
			'/pattern-builder/v1/cloud/login',
			array(
				'email'    => 'demo@example.test',
				'password' => 'whatever',
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}
}
