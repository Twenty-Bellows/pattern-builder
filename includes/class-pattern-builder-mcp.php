<?php

namespace TwentyBellows\PatternBuilder;

/**
 * Serves this plugin's abilities as MCP tools, one tool each, through the WordPress MCP
 * Adapter, and tells the Agents tab what connecting one to this site needs. Without the
 * adapter the hooks never fire and only that report runs.
 */
class Pattern_Builder_Mcp {
	/**
	 * The server's id, which is also the `--server` WP-CLI serves it by.
	 */
	const SERVER_ID = 'pattern-builder';

	/**
	 * The `app_id` every Application Password the Agents tab creates carries, which is how
	 * the tab tells its own connections from the rest of a user's.
	 */
	const APP_ID = 'be57b0b1-372d-4f66-9aae-074a8867319c';

	/**
	 * The MCP Adapter's plugin file, as wordpress.org installs it.
	 */
	const ADAPTER_PLUGIN = 'mcp-adapter/mcp-adapter.php';

	/**
	 * Hook the component into WordPress.
	 */
	public function __construct() {
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'keep_error_data' ), 10, 5 );
	}

	/**
	 * Create the server, at /wp-json/pattern-builder/v1/mcp.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The adapter, as the action passes it.
	 */
	public function register_server( $adapter ) {
		$adapter->create_server(
			self::SERVER_ID,
			'pattern-builder/v1',
			'mcp',
			__( 'Pattern Builder', 'pattern-builder' ),
			__( 'Read this site’s design system, blocks and patterns, and store finished patterns in its theme or database.', 'pattern-builder' ),
			PATTERN_BUILDER_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			null,
			null,
			self::tool_names()
		);
	}

	/**
	 * Every ability in this plugin's category, read from the registry so that a new one is
	 * served without being listed here.
	 *
	 * @return string[]
	 */
	public static function tool_names() {
		$names = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( Pattern_Builder_Abilities::CATEGORY === $ability->get_category() ) {
				$names[] = $ability->get_name();
			}
		}

		return $names;
	}

	/**
	 * What the Agents tab needs to know about connecting one to this site, for the current
	 * user.
	 *
	 * @return array
	 */
	public static function client_state() {
		$user = wp_get_current_user();

		if ( ! wp_is_application_passwords_supported() ) {
			$app_passwords = 'insecure';
		} elseif ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			$app_passwords = 'disabled';
		} else {
			$app_passwords = 'available';
		}

		return array(
			'appId'        => self::APP_ID,
			'endpoint'     => rest_url( 'pattern-builder/v1/mcp' ),
			'serverName'   => self::server_name(),
			'siteName'     => get_bloginfo( 'name' ),
			'login'        => $user->user_login,
			'abilities'    => function_exists( 'wp_register_ability' ),
			'adapter'      => self::adapter_state(),
			'appPasswords' => $app_passwords,
		);
	}

	/**
	 * The name an agent's client knows this site by: its address, so that each of the many
	 * sites one person connects is told apart, e.g. `example-com` or `localhost-7777`.
	 *
	 * @return string
	 */
	public static function server_name() {
		$parts = wp_parse_url( home_url() );
		$name  = ( $parts['host'] ?? 'site' )
			. ( isset( $parts['port'] ) ? '-' . $parts['port'] : '' )
			. ( isset( $parts['path'] ) ? '-' . $parts['path'] : '' );

		return sanitize_title( str_replace( array( '.', '/' ), '-', $name ) );
	}

	/**
	 * Whether the MCP Adapter is running, and if not, whether this user can put it right.
	 *
	 * @return array
	 */
	private static function adapter_state() {
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return array(
				'status'   => 'active',
				'version'  => defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '',
				'provider' => self::adapter_provider(),
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array_key_exists( self::ADAPTER_PLUGIN, get_plugins() );

		return array(
			'status' => $installed ? 'inactive' : 'missing',
			'canFix' => current_user_can( 'activate_plugins' )
				&& ( $installed || ( current_user_can( 'install_plugins' ) && wp_is_file_mod_allowed( 'pattern_builder_install_mcp_adapter' ) ) ),
		);
	}

	/**
	 * The name of the plugin that bundles the running adapter.
	 *
	 * @return string The plugin's name, or '' when the adapter is its own plugin or was not
	 *                loaded from a plugin.
	 */
	private static function adapter_provider() {
		$file = ( new \ReflectionClass( '\WP\MCP\Core\McpAdapter' ) )->getFileName();
		$path = wp_normalize_path( (string) $file );
		$root = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		if ( 0 !== strpos( $path, $root ) ) {
			return '';
		}

		$directory = strtok( substr( $path, strlen( $root ) ), '/' );

		if ( dirname( self::ADAPTER_PLUGIN ) === $directory ) {
			return '';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $plugin_file => $plugin ) {
			if ( 0 === strpos( $plugin_file, $directory . '/' ) ) {
				return $plugin['Name'];
			}
		}

		return (string) $directory;
	}

	/**
	 * Carry an error's code and data to the client, which otherwise receives the message
	 * alone: a refusal's `currentHash`, or the places a removed slot still fills, is what the
	 * caller needs to put the call right.
	 *
	 * @param mixed  $result    The tool's result.
	 * @param array  $args      The tool's arguments.
	 * @param string $tool_name The tool's name.
	 * @param object $tool      The tool.
	 * @param object $server    The server it was called on.
	 * @return mixed
	 */
	public function keep_error_data( $result, $args, $tool_name, $tool, $server ) {
		if ( ! is_wp_error( $result ) || ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || self::SERVER_ID !== $server->get_server_id() ) {
			return $result;
		}

		$details = array( 'code' => $result->get_error_code() );
		$data    = $result->get_error_data();

		if ( is_array( $data ) ) {
			unset( $data['status'] );
		}

		if ( ! empty( $data ) ) {
			$details['data'] = $data;
		}

		return new \WP_Error(
			$result->get_error_code(),
			$result->get_error_message() . "\n\n" . wp_json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			$result->get_error_data()
		);
	}
}
