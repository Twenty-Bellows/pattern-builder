<?php

namespace TwentyBellows\PatternBuilder;

/**
 * Serves this plugin's abilities as MCP tools, one tool each, through the WordPress MCP
 * Adapter. Without that plugin the hooks never fire and nothing here runs.
 */
class Pattern_Builder_Mcp {
	/**
	 * The server's id, which is also the `--server` WP-CLI serves it by.
	 */
	const SERVER_ID = 'pattern-builder';

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
