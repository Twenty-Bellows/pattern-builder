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

	public function test_the_community_is_browsed_as_an_account() {
		$this->mock_service(
			function () {
				$this->fail( 'A disconnected site must not reach the service for the directory.' );
			}
		);

		$this->assertSame( 401, $this->request( 'GET', '/pattern-builder/v1/cloud/directory' )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', '/pattern-builder/v1/cloud/collections' )->get_status() );
		$download = $this->request(
			'POST',
			'/pattern-builder/v1/cloud/download',
			array(
				'source'  => 'directory',
				'cloudId' => 5,
			)
		);
		$this->assertSame( 401, $download->get_status() );
		$this->assertSame( 'pb_cloud_disconnected', $download->get_data()['code'] );
	}

	public function test_signup_relays_the_marketing_answer_as_yes_or_no() {
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
					'body'     => wp_json_encode( array( 'account' => array( 'id' => 12 ), 'tier' => 'free', 'usage' => array() ) ),
				);
			}
		);

		$this->request(
			'POST',
			'/pattern-builder/v1/cloud/signup',
			array(
				'email'     => 'new@example.test',
				'password'  => 'Correct-horse-1',
				'marketing' => true,
			)
		);
		$this->assertSame( 'yes', $seen[0]['marketing'] );

		delete_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN );
		$this->request(
			'POST',
			'/pattern-builder/v1/cloud/signup',
			array(
				'email'    => 'quiet@example.test',
				'password' => 'Correct-horse-1',
			)
		);
		$this->assertSame( 'no', $seen[1]['marketing'], 'No answer relays as no.' );
	}

	public function test_forgot_password_relays_the_address_and_needs_no_connection() {
		$seen = array();
		$this->mock_service(
			function ( $args, $url ) use ( &$seen ) {
				$seen[] = array(
					'url'  => $url,
					'body' => $args['body'],
				);
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'sent'    => true,
							'message' => 'If that address has an account, a reset link is on its way.',
						)
					),
				);
			}
		);

		$this->assertSame( 400, $this->request( 'POST', '/pattern-builder/v1/cloud/password/forgot', array( 'email' => 'nope' ) )->get_status() );

		$response = $this->request( 'POST', '/pattern-builder/v1/cloud/password/forgot', array( 'email' => 'who@example.test' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'reset link', $response->get_data()['message'] );
		$this->assertStringContainsString( rawurlencode( '/auth/password/forgot' ), $seen[0]['url'] );
		$this->assertSame( 'who@example.test', $seen[0]['body']['email'] );
		$this->assertArrayNotHasKey( 'Authorization', $seen[0]['body'] );
	}

	public function test_billing_sync_relays_the_licence_and_returns_the_new_status() {
		update_user_meta( get_current_user_id(), Pattern_Builder_Cloud::META_TOKEN, 'pbwp_' . str_repeat( 'a', 64 ) );
		$seen = array();
		$this->mock_service(
			function ( $args, $url ) use ( &$seen ) {
				if ( false !== strpos( $url, rawurlencode( '/billing/sync' ) ) ) {
					$seen[] = json_decode( $args['body'], true );
					return array(
						'headers'  => array(),
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'tier' => 'pro' ) ),
					);
				}
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'account'  => array(
								'id'       => 12,
								'name'     => 'Demo',
								'verified' => true,
							),
							'tier'     => 'pro',
							'usage'    => array(),
							'checkout' => null,
						)
					),
				);
			}
		);

		$response = $this->request( 'POST', '/pattern-builder/v1/cloud/billing/sync', array( 'licenseId' => 5001 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'license_id' => 5001 ), $seen[0] );
		$this->assertSame( 'pro', $response->get_data()['tier'] );
		$this->assertNull( $response->get_data()['checkout'] );
		$this->assertArrayHasKey( 'telemetry', $response->get_data() );
	}
}
