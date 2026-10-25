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

	/**
	 * The served tools, by name, once a test has asked for them.
	 *
	 * @var stdClass[]|null
	 */
	private $tools;

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

	public function tear_down() {
		wp_clean_theme_json_cache();
		parent::tear_down();
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

		$this->assertCount( 33, $expected );
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
	 * Calls a tool as a client would, and checks that it succeeded and that its answer has
	 * the shape the tool's `outputSchema` declares: a client may check `structuredContent`
	 * against it, and an empty PHP array encodes as a list.
	 *
	 * @param string $name      The tool.
	 * @param array  $arguments Its arguments.
	 * @return stdClass The answer's structured content.
	 */
	private function call( $name, $arguments = array() ) {
		if ( null === $this->tools ) {
			$this->tools = $this->tools();
		}

		$result = $this->result(
			'tools/call',
			array(
				'name'      => $name,
				'arguments' => (object) $arguments,
			)
		);

		$this->assertFalse( $result->isError, $name . ': ' . ( $result->content[0]->text ?? '' ) );
		$this->assertMatchesShape( $this->tools[ $name ]->outputSchema, $result->structuredContent, $name );

		return $result->structuredContent;
	}

	public function test_each_read_that_needs_no_input_answers_in_its_declared_shape() {
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

		foreach ( $reads as $name ) {
			$this->call( $name );
		}
	}

	/**
	 * Everything an agent does to one pattern, on a user pattern so that nothing is written
	 * into a theme: create it, read it, render it, ask where it is used, find it, and update
	 * it both whole and by edits.
	 */
	public function test_each_answer_about_a_pattern_has_its_declared_shape() {
		$created = $this->call(
			'pattern-builder-create-pattern',
			array(
				'title'       => 'Schema fixture',
				'source'      => 'user',
				'description' => 'A single paragraph used to check that every answer about a pattern arrives in the shape its tool declares.',
				'content'     => "<!-- wp:paragraph -->\n<p>First.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$id = (string) $created->pattern->id;

		$read = $this->call( 'pattern-builder-get-pattern', array( 'id' => $id ) );
		$this->call( 'pattern-builder-render-pattern', array( 'id' => $id ) );
		$this->call( 'pattern-builder-list-pattern-usage', array( 'id' => $id ) );
		$this->call(
			'pattern-builder-list-patterns',
			array(
				'source' => 'user',
				'search' => 'Schema fixture',
			)
		);

		$whole = $this->call(
			'pattern-builder-update-pattern',
			array(
				'id'           => $id,
				'expectedHash' => $read->pattern->hash,
				'content'      => "<!-- wp:paragraph -->\n<p>Second.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->assertTrue( $whole->changed );

		$edited = $this->call(
			'pattern-builder-update-pattern',
			array(
				'id'           => $id,
				'expectedHash' => $whole->pattern->hash,
				'edits'        => array(
					array(
						'old' => 'Second.',
						'new' => 'Third.',
					),
				),
			)
		);
		$this->assertTrue( $edited->changed );
	}

	/**
	 * The design-system writers, to Global Styles so that nothing is written into a theme.
	 * The same token twice is skipped the second time, which is the answer with nothing in
	 * it, and the one most likely to arrive as a list.
	 */
	public function test_each_design_system_write_answers_in_its_declared_shape() {
		$token = array(
			'type'  => 'color',
			'slug'  => 'highlight',
			'name'  => 'Highlight',
			'value' => '#123456',
		);

		$this->call(
			'pattern-builder-add-design-tokens',
			array(
				'destination' => 'user',
				'tokens'      => array( $token ),
			)
		);
		$this->call(
			'pattern-builder-add-design-tokens',
			array(
				'destination' => 'user',
				'tokens'      => array( $token ),
			)
		);
		$this->call(
			'pattern-builder-set-global-styles',
			array(
				'destination' => 'user',
				'styles'      => array( 'elements' => array( 'link' => array( 'color' => array( 'text' => '#123456' ) ) ) ),
			)
		);
		$this->call(
			'pattern-builder-set-layout',
			array(
				'destination' => 'user',
				'contentSize' => '640px',
				'wideSize'    => '1200px',
			)
		);
	}

	/**
	 * Asserts that every value the schema types as an object, or as a list, arrived as one.
	 *
	 * @param stdClass $schema The schema, as the client received it.
	 * @param mixed    $value  The value, as the client received it.
	 * @param string   $path   Where in the answer this is, for the failure message.
	 */
	private function assertMatchesShape( $schema, $value, $path ) {
		$types = isset( $schema->type ) ? (array) $schema->type : array();

		if ( null === $value && in_array( 'null', $types, true ) ) {
			return;
		}

		if ( in_array( 'object', $types, true ) && ! in_array( 'array', $types, true ) ) {
			$this->assertIsObject( $value, $path . ' should be an object' );

			$properties = (array) ( $schema->properties ?? array() );
			foreach ( get_object_vars( $value ) as $key => $item ) {
				if ( isset( $properties[ $key ] ) ) {
					$this->assertMatchesShape( $properties[ $key ], $item, $path . '.' . $key );
				} elseif ( isset( $schema->additionalProperties ) && is_object( $schema->additionalProperties ) ) {
					$this->assertMatchesShape( $schema->additionalProperties, $item, $path . '.' . $key );
				}
			}
		} elseif ( in_array( 'array', $types, true ) && ! in_array( 'object', $types, true ) ) {
			$this->assertIsArray( $value, $path . ' should be a list' );

			if ( isset( $schema->items ) && is_object( $schema->items ) ) {
				foreach ( $value as $index => $item ) {
					$this->assertMatchesShape( $schema->items, $item, $path . '[' . $index . ']' );
				}
			}
		}
	}
}
