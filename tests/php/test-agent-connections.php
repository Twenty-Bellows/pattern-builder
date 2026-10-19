<?php
/**
 * What the Agents tab is told about connecting an agent to this site.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Mcp;

class Test_Agent_Connections extends WP_UnitTestCase {
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_it_names_the_endpoint_the_app_id_and_the_user() {
		$state = Pattern_Builder_Mcp::client_state();

		$this->assertSame( rest_url( 'pattern-builder/v1/mcp' ), $state['endpoint'] );
		$this->assertSame( Pattern_Builder_Mcp::APP_ID, $state['appId'] );
		$this->assertSame( wp_get_current_user()->user_login, $state['login'] );
		$this->assertTrue( $state['abilities'] );
	}

	/**
	 * One person connects many sites, so the name each is known by comes from its address,
	 * port and path included.
	 */
	public function test_the_server_is_named_after_the_sites_address() {
		$this->assertSame( 'www-example-com', $this->server_name_for( 'https://www.example.com' ) );
		$this->assertSame( 'localhost-7777', $this->server_name_for( 'http://localhost:7777' ) );
		$this->assertSame( 'example-com-blog', $this->server_name_for( 'https://example.com/blog/' ) );
	}

	/**
	 * The server name for a home URL. wp-env fixes the home option with a constant, so the
	 * address arrives through the filter instead.
	 *
	 * @param string $home The home URL.
	 * @return string
	 */
	private function server_name_for( $home ) {
		$filter = static function () use ( $home ) {
			return $home;
		};

		add_filter( 'home_url', $filter );
		$name = Pattern_Builder_Mcp::server_name();
		remove_filter( 'home_url', $filter );

		return $name;
	}

	public function test_application_passwords_turned_off_are_reported_as_disabled() {
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$this->assertSame( 'disabled', Pattern_Builder_Mcp::client_state()['appPasswords'] );
	}

	/**
	 * The provider is named only when another plugin bundles the adapter; the adapter's own
	 * plugin goes without saying.
	 */
	public function test_a_running_adapter_is_reported_with_its_version() {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			$this->markTestSkipped( 'The MCP Adapter plugin is not installed here.' );
		}

		$adapter = Pattern_Builder_Mcp::client_state()['adapter'];

		$this->assertSame( 'active', $adapter['status'] );
		$this->assertSame( WP_MCP_VERSION, $adapter['version'] );
		$this->assertSame( '', $adapter['provider'] );
	}
}
