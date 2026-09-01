<?php
/**
 * Opt-in usage telemetry: nothing without consent, and only what the
 * class says it sends with it.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Telemetry;

class Test_Telemetry extends WP_UnitTestCase {

	/**
	 * Batches that would have been sent.
	 *
	 * @var array
	 */
	private $sent = array();

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Pattern_Builder_Telemetry::OPTION );
		Pattern_Builder_Telemetry::reset();
		$this->sent = array();
		add_filter( 'pattern_builder_telemetry_send', array( $this, 'intercept' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pattern_builder_telemetry_send', array( $this, 'intercept' ), 10 );
		remove_all_filters( 'pre_http_request' );
		delete_option( Pattern_Builder_Telemetry::OPTION );
		Pattern_Builder_Telemetry::reset();
		parent::tear_down();
	}

	public function intercept( $send, $events ) {
		$this->sent[] = $events;
		return false;
	}

	private function request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, '/pattern-builder/v1/' . ltrim( $route, '/' ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	public function test_nothing_is_recorded_until_somebody_says_yes() {
		$this->assertFalse( Pattern_Builder_Telemetry::is_decided() );
		$this->assertFalse( Pattern_Builder_Telemetry::is_enabled() );

		Pattern_Builder_Telemetry::record( 'browser_opened' );
		Pattern_Builder_Telemetry::flush();

		$this->assertSame( array(), Pattern_Builder_Telemetry::pending() );
		$this->assertSame( array(), $this->sent );
	}

	public function test_declining_records_the_answer_and_sends_nothing() {
		$state = Pattern_Builder_Telemetry::set_consent( false );

		$this->assertSame( 'declined', $state['consent'] );
		$this->assertSame( '', $state['install_id'], 'No id is minted for a site that said no.' );
		$this->assertSame( get_current_user_id(), $state['decided_by'] );
		$this->assertTrue( Pattern_Builder_Telemetry::is_decided() );
		$this->assertFalse( Pattern_Builder_Telemetry::is_enabled() );

		Pattern_Builder_Telemetry::record( 'browser_opened' );
		Pattern_Builder_Telemetry::flush();
		$this->assertSame( array(), $this->sent );
	}

	public function test_allowing_mints_an_install_id_and_sends_the_environment_with_each_event() {
		$state = Pattern_Builder_Telemetry::set_consent( true );

		$this->assertSame( 'allowed', $state['consent'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $state['install_id'] );
		$this->assertTrue( Pattern_Builder_Telemetry::is_enabled() );

		Pattern_Builder_Telemetry::record( 'pattern_created', array( 'kind' => 'design' ) );
		Pattern_Builder_Telemetry::flush();

		$this->assertCount( 1, $this->sent );
		$batch = $this->sent[0];
		$this->assertSame( 'telemetry_enabled', $batch[0]['event'], 'Saying yes is itself the first event.' );
		$this->assertSame( 'pattern_created', $batch[1]['event'] );
		$this->assertSame( $state['install_id'], $batch[1]['install_id'] );
		$this->assertSame( 'design', $batch[1]['properties']['kind'] );
		$this->assertSame( PATTERN_BUILDER_VERSION, $batch[1]['properties']['plugin_version'] );
		$this->assertSame( get_bloginfo( 'version' ), $batch[1]['properties']['wp_version'] );
		$this->assertSame( get_stylesheet(), $batch[1]['properties']['theme'] );
		$this->assertArrayNotHasKey( 'site_url', $batch[1]['properties'] );
		$this->assertStringNotContainsString( home_url(), wp_json_encode( $batch ) );

		$this->assertSame( array(), Pattern_Builder_Telemetry::pending(), 'Flushed once.' );
	}

	public function test_turning_it_off_is_the_last_thing_sent_and_keeps_the_id() {
		$allowed = Pattern_Builder_Telemetry::set_consent( true );
		Pattern_Builder_Telemetry::flush();
		$this->sent = array();

		$declined = Pattern_Builder_Telemetry::set_consent( false );
		Pattern_Builder_Telemetry::flush();

		$this->assertSame( 'telemetry_disabled', $this->sent[0][0]['event'] );
		$this->assertSame( $allowed['install_id'], $declined['install_id'] );

		Pattern_Builder_Telemetry::record( 'browser_opened' );
		Pattern_Builder_Telemetry::flush();
		$this->assertCount( 1, $this->sent, 'Nothing after the goodbye.' );
	}

	public function test_the_batch_goes_to_the_service_when_not_intercepted() {
		remove_filter( 'pattern_builder_telemetry_send', array( $this, 'intercept' ), 10 );
		$seen = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$seen ) {
				$seen[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'headers'  => array(),
					'response' => array( 'code' => 202 ),
					'body'     => '{"accepted":1}',
				);
			},
			10,
			3
		);

		Pattern_Builder_Telemetry::set_consent( true );
		Pattern_Builder_Telemetry::flush();

		$this->assertCount( 1, $seen );
		$this->assertStringContainsString( rawurlencode( '/pbwp/v1/telemetry' ), $seen[0]['url'] );
		$this->assertFalse( $seen[0]['args']['blocking'] );
		$body = json_decode( $seen[0]['args']['body'], true );
		$this->assertSame( 'telemetry_enabled', $body['events'][0]['event'] );
	}

	public function test_the_routes_answer_the_prompt_and_take_events() {
		$this->assertSame( '', $this->request( 'GET', 'telemetry' )->get_data()['consent'] );

		$response = $this->request( 'POST', 'telemetry/consent', array( 'allow' => true ) );
		$this->assertSame( 'allowed', $response->get_data()['consent'] );
		$this->assertTrue( $response->get_data()['enabled'] );

		$this->request(
			'POST',
			'telemetry/event',
			array(
				'event'      => 'community_browsed',
				'properties' => array(
					'collection' => 'heroes',
					'nested'     => array( 'dropped' => true ),
				),
			)
		);
		Pattern_Builder_Telemetry::flush();

		$events = array_column( $this->sent[0], 'event' );
		$this->assertContains( 'community_browsed', $events );
		$last = end( $this->sent[0] );
		$this->assertSame( 'heroes', $last['properties']['collection'] );
		$this->assertArrayNotHasKey( 'nested', $last['properties'] );
	}

	public function test_the_routes_need_a_site_manager() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->request( 'GET', 'telemetry' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', 'telemetry/consent', array( 'allow' => true ) )->get_status() );
		$this->assertFalse( Pattern_Builder_Telemetry::is_decided() );
	}
}
