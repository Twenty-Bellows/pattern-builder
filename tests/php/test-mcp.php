<?php
/**
 * The MCP server: every ability served as a tool, what a refusal carries back, and
 * whether each answer has the shape its declared schema promises a client.
 *
 * These talk to the server over its REST route, as a client does, so they run against
 * the MCP Adapter plugin wherever the environment installed it (wp-env does) and are
 * skipped anywhere else.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Abilities;
use TwentyBellows\PatternBuilder\Pattern_Builder_Mcp;

class Test_Mcp extends WP_UnitTestCase {
	const ROUTE = '/pattern-builder/v1/mcp';

	/**
	 * The session the running test initialized.
	 *
	 * @var string
	 */
	private $session;

	/**
	 * The next JSON-RPC request id.
	 *
	 * @var int
	 */
	private $id = 1;

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			$this->markTestSkipped( 'The MCP Adapter plugin is not installed here.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->post(
			array(
				'method' => 'initialize',
				'params' => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => new stdClass(),
					'clientInfo'      => array(
						'name'    => 'pattern-builder-tests',
						'version' => '1',
					),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->session = $response->get_headers()['Mcp-Session-Id'];
	}

	/**
	 * Sends one JSON-RPC request to the server, in the session when there is one.
	 *
	 * @param array $message The request, without `jsonrpc` and `id`.
	 * @return WP_REST_Response
	 */
	private function post( $message ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );

		if ( $this->session ) {
			$request->set_header( 'Mcp-Session-Id', $this->session );
			$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		}

		$request->set_body(
			(string) wp_json_encode(
				array_merge(
					array(
						'jsonrpc' => '2.0',
						'id'      => $this->id++,
					),
					$message
				)
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * A method's result, decoded from the JSON a client would receive, so an empty PHP
	 * array arrives as the `[]` it is on the wire.
	 *
	 * @param string $method The method.
	 * @param array  $params Its params.
	 * @return stdClass
	 */
	private function result( $method, $params = array() ) {
		$response = $this->post(
			array(
				'method' => $method,
				'params' => (object) $params,
			)
		);
		$this->assertSame( 200, $response->get_status() );

		$decoded = json_decode( (string) wp_json_encode( $response->get_data() ) );
		$this->assertObjectHasProperty( 'result', $decoded, (string) wp_json_encode( $response->get_data() ) );

		return $decoded->result;
	}

	/**
	 * The served tools, by name.
	 *
	 * @return stdClass[]
	 */
	private function tools() {
		$tools = array();

		foreach ( $this->result( 'tools/list' )->tools as $tool ) {
			$tools[ $tool->name ] = $tool;
		}

		return $tools;
	}

	public function test_every_ability_in_the_category_is_a_tool() {
		$expected = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( Pattern_Builder_Abilities::CATEGORY === $ability->get_category() ) {
				$expected[] = str_replace( '/', '-', $ability->get_name() );
			}
		}

		$served = array_keys( $this->tools() );
		sort( $expected );
		sort( $served );

		$this->assertCount( 28, $expected );
		$this->assertSame( $expected, $served );
		$this->assertSame( count( $expected ), count( Pattern_Builder_Mcp::tool_names() ) );
	}

	/**
	 * The annotations are what let a client allow the reads and still ask before a write.
	 */
	public function test_the_annotations_reach_the_client() {
		$tools = $this->tools();

		$this->assertTrue( $tools['pattern-builder-list-patterns']->annotations->readOnlyHint );
		$this->assertFalse( $tools['pattern-builder-list-patterns']->annotations->openWorldHint );
		$this->assertFalse( $tools['pattern-builder-create-pattern']->annotations->readOnlyHint );
		$this->assertFalse( $tools['pattern-builder-update-pattern']->annotations->destructiveHint );
		$this->assertTrue( $tools['pattern-builder-add-font']->annotations->openWorldHint );
		$this->assertTrue( $tools['pattern-builder-add-asset']->annotations->openWorldHint );
		$this->assertTrue( $tools['pattern-builder-upload-pattern']->annotations->openWorldHint );
		$this->assertTrue( $tools['pattern-builder-list-collections']->annotations->readOnlyHint );
	}

	/**
	 * The adapter hands a client an error's message alone; the code and the data are what
	 * the caller needs to put the call right, so they travel too.
	 */
	public function test_a_refusal_keeps_its_code_and_data() {
		$result = $this->result(
			'tools/call',
			array(
				'name'      => 'pattern-builder-create-pattern',
				'arguments' => array(
					'title'   => 'Refused',
					'content' => '<!-- wp:not-a/block /-->',
					'source'  => 'user',
				),
			)
		);

		$this->assertTrue( $result->isError );

		$text = $result->content[0]->text;
		$this->assertStringContainsString( 'The markup was not stored', $text );

		$details = json_decode( substr( $text, strrpos( $text, "\n\n" ) + 2 ) );
		$this->assertSame( 'pb_markup_refused', $details->code );
		$this->assertNotEmpty( $details->data->problems );
		$this->assertObjectNotHasProperty( 'status', $details->data );
	}

	public function test_another_servers_errors_are_left_alone() {
		$error  = new WP_Error( 'some_code', 'Some message.', array( 'detail' => 1 ) );
		$server = new class() {
			public function get_server_id() {
				return 'someone-else';
			}
		};

		$this->assertSame( $error, ( new Pattern_Builder_Mcp() )->keep_error_data( $error, array(), 'tool', null, $server ) );
	}

	/**
	 * A client may check `structuredContent` against `outputSchema`, and an empty PHP array
	 * encodes as a list. Every read that needs no input is called as a client would call it
	 * and its answer checked against the schema the tool declared.
	 */
	public function test_each_read_answers_in_the_shape_its_schema_declares() {
		$reads = array(
			'pattern-builder-get-design-system',
			'pattern-builder-list-block-types',
			'pattern-builder-list-patterns',
			'pattern-builder-get-authoring-guide',
			'pattern-builder-get-validator',
			'pattern-builder-get-editor-scripts',
			'pattern-builder-find-media',
			'pattern-builder-list-fonts',
		);
		$tools = $this->tools();

		foreach ( $reads as $name ) {
			$result = $this->result(
				'tools/call',
				array(
					'name'      => $name,
					'arguments' => new stdClass(),
				)
			);

			$this->assertFalse( $result->isError, $name . ': ' . ( $result->content[0]->text ?? '' ) );
			$this->assertMatchesShape( $tools[ $name ]->outputSchema, $result->structuredContent, $name );
		}
	}

	/**
	 * Asserts that every value the schema types as an object, or as a list, arrived as one.
	 *
	 * @param stdClass $schema The schema, as the client received it.
	 * @param mixed    $value  The value, as the client received it.
	 * @param string   $path   Where in the answer this is, for the failure message.
	 */
	private function assertMatchesShape( $schema, $value, $path ) {
		$type = isset( $schema->type ) ? $schema->type : null;

		if ( 'object' === $type ) {
			$this->assertIsObject( $value, $path . ' should be an object' );

			foreach ( (array) ( $schema->properties ?? array() ) as $key => $property ) {
				if ( property_exists( $value, $key ) ) {
					$this->assertMatchesShape( $property, $value->$key, $path . '.' . $key );
				}
			}
		} elseif ( 'array' === $type ) {
			$this->assertIsArray( $value, $path . ' should be a list' );

			if ( isset( $schema->items ) && is_object( $schema->items ) ) {
				foreach ( $value as $index => $item ) {
					$this->assertMatchesShape( $schema->items, $item, $path . '[' . $index . ']' );
				}
			}
		}
	}
}
