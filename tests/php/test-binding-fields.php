<?php
/**
 * Tests for the bindable fields endpoint.
 *
 * @package Pattern_Builder
 */

/**
 * The fields each binding source offers for a post type.
 *
 * @covers \TwentyBellows\PatternBuilder\Pattern_Builder_API
 */
class Test_Binding_Fields extends WP_UnitTestCase {

	/**
	 * Sets the test up.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Requests the fields for a post type.
	 *
	 * @param string $post_type The post type to ask about.
	 * @return array The response data.
	 */
	private function request( string $post_type = 'post' ): array {
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/binding-fields' );
		$request->set_param( 'post_type', $post_type );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		return (array) $response->get_data();
	}

	/**
	 * Registers a source that publishes nothing, as PHP registration must.
	 *
	 * @param string $name The source name.
	 */
	private function register_php_source( string $name ) {
		register_block_bindings_source(
			$name,
			array(
				'label'              => 'Test Source',
				'get_value_callback' => '__return_empty_string',
			)
		);
	}

	/**
	 * Core's own registration rejects a field list outright.
	 *
	 * This is why the filter exists: a source registered in PHP has no way to
	 * publish one, and trying fails the whole registration.
	 */
	public function test_core_registration_rejects_a_field_list() {
		$this->setExpectedIncorrectUsage( 'WP_Block_Bindings_Registry::register' );

		$source = register_block_bindings_source(
			'test/with-fields',
			array(
				'label'              => 'With Fields',
				'get_value_callback' => '__return_empty_string',
				'get_fields_list'    => array(),
			)
		);

		$this->assertFalse( $source );
	}

	/**
	 * The three fields core/post-data resolves but never publishes.
	 */
	public function test_declares_the_post_data_fields() {
		$fields = $this->request()['core/post-data'] ?? array();

		$this->assertSame(
			array(
				array( 'field' => 'date' ),
				array( 'field' => 'modified' ),
				array( 'field' => 'link' ),
			),
			wp_list_pluck( $fields, 'args' )
		);
	}

	/**
	 * Post data's declared list stops where its callback stops.
	 *
	 * Declaring a field only puts it in the panel; resolving it is core's
	 * own callback, which answers `date`, `modified` and `link` and returns
	 * null for anything else. A block bound to a field it does not answer
	 * renders its fallback content, so declaring a title or a content field
	 * would offer a binding that never shows anything.
	 */
	public function test_declares_only_what_post_data_resolves() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'The Real Title',
				'post_status' => 'publish',
			)
		);

		foreach ( array( 'title', 'content' ) as $field ) {
			$this->assertStringContainsString(
				'FALLBACK',
				$this->render_bound_paragraph( array( 'field' => $field ), $post_id ),
				"core/post-data resolved '$field'; it can now be declared."
			);
		}

		$this->assertStringNotContainsString(
			'FALLBACK',
			$this->render_bound_paragraph( array( 'field' => 'link' ), $post_id )
		);
	}

	/**
	 * Renders a paragraph whose content is bound to core/post-data.
	 *
	 * Rendered rather than resolved directly, because the binding machinery
	 * is what supplies the source's context.
	 *
	 * @param array $args    The binding arguments.
	 * @param int   $post_id The post to render against.
	 * @return string The rendered block.
	 */
	private function render_bound_paragraph( array $args, int $post_id ): string {
		$binding = wp_json_encode(
			array(
				'source' => 'core/post-data',
				'args'   => $args,
			)
		);

		$blocks = parse_blocks(
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":' . $binding . '}}} -->' .
			'<p>FALLBACK</p><!-- /wp:paragraph -->'
		);

		$block = new WP_Block( $blocks[0], array( 'postId' => $post_id ) );

		return $block->render();
	}

	/**
	 * Term data stays out: a pattern in a post has no term context.
	 */
	public function test_leaves_term_data_alone() {
		$this->assertArrayNotHasKey( 'core/term-data', $this->request() );
	}

	/**
	 * A source registered in PHP can be given fields through the filter.
	 */
	public function test_filter_gives_a_php_source_its_fields() {
		$this->register_php_source( 'test/php-only' );

		add_filter(
			'pattern_builder_binding_fields',
			function ( $fields, $source_name ) {
				return 'test/php-only' === $source_name
					? array(
						array(
							'label' => 'Hero link',
							'args'  => array( 'key' => 'hero_link' ),
							'type'  => 'string',
						),
					)
					: $fields;
			},
			10,
			2
		);

		$this->assertSame(
			array(
				array(
					'label' => 'Hero link',
					'args'  => array( 'key' => 'hero_link' ),
					'type'  => 'string',
				),
			),
			$this->request()['test/php-only']
		);
	}

	/**
	 * The filter is told which post type is being asked about.
	 */
	public function test_filter_receives_the_post_type() {
		$this->register_php_source( 'test/per-type' );

		$seen = array();

		add_filter(
			'pattern_builder_binding_fields',
			function ( $fields, $source_name, $post_type ) use ( &$seen ) {
				if ( 'test/per-type' === $source_name ) {
					$seen[] = $post_type;
				}

				return $fields;
			},
			10,
			3
		);

		$this->request( 'page' );

		$this->assertSame( array( 'page' ), $seen );
	}

	/**
	 * A field with nothing to bind to is dropped rather than offered.
	 */
	public function test_drops_a_field_with_no_args() {
		$this->register_php_source( 'test/malformed' );

		add_filter(
			'pattern_builder_binding_fields',
			function ( $fields, $source_name ) {
				return 'test/malformed' === $source_name
					? array( array( 'label' => 'No args' ), 'not an array' )
					: $fields;
			},
			10,
			2
		);

		$this->assertArrayNotHasKey( 'test/malformed', $this->request() );
	}

	/**
	 * Asks which post types have anything to bind to.
	 *
	 * @return array The slugs.
	 */
	private function request_post_types(): array {
		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/pattern-builder/v1/binding-fields' )
		);

		$this->assertSame( 200, $response->get_status() );

		return (array) $response->get_data();
	}

	/**
	 * The post types a pattern actually lands in are offered.
	 */
	public function test_lists_the_viewable_post_types() {
		$listed = $this->request_post_types();

		$this->assertContains( 'post', $listed );
		$this->assertContains( 'page', $listed );
	}

	/**
	 * The machinery a pattern is never placed in is not.
	 *
	 * Every one of these was in the list before it was narrowed. `wp_block` is
	 * the interesting one: core registers a sync flag against it, so a rule
	 * resting on registered meta alone would keep it.
	 */
	public function test_omits_the_internal_post_types() {
		$listed = $this->request_post_types();

		$internal = array(
			'wp_template',
			'wp_template_part',
			'wp_navigation',
			'wp_global_styles',
			'wp_font_family',
			'wp_font_face',
			'nav_menu_item',
			'wp_block',
			'pb_pattern',
		);

		foreach ( $internal as $post_type ) {
			$this->assertNotContains( $post_type, $listed );
		}
	}

	/**
	 * A plugin's own post type is offered for the meta it registers.
	 *
	 * Not being publicly viewable is no reason to hide it: the meta is there
	 * and `core/post-meta` will list it.
	 */
	public function test_lists_a_private_post_type_with_registered_meta() {
		register_post_type( 'book', array( 'show_in_rest' => true, 'public' => false ) );
		register_post_meta( 'book', 'isbn', array( 'show_in_rest' => true, 'type' => 'string' ) );

		$this->assertContains( 'book', $this->request_post_types() );
	}

	/**
	 * A post type with nothing registered against it is not.
	 */
	public function test_omits_a_post_type_with_nothing_to_bind_to() {
		register_post_type( 'crate', array( 'show_in_rest' => true, 'public' => false ) );

		$this->assertNotContains( 'crate', $this->request_post_types() );
	}

	/**
	 * Meta core hides from its own field list does not qualify a post type.
	 */
	public function test_ignores_meta_the_editor_would_not_offer() {
		register_post_type( 'ledger', array( 'show_in_rest' => true, 'public' => false ) );
		register_post_meta( 'ledger', '_secret', array( 'show_in_rest' => true, 'type' => 'string' ) );
		register_post_meta( 'ledger', 'internal', array( 'show_in_rest' => false, 'type' => 'string' ) );

		$this->assertNotContains( 'ledger', $this->request_post_types() );
	}

	/**
	 * The filter alone is enough to put a post type in the list.
	 *
	 * This is the path a plugin that registers its source in PHP takes.
	 */
	public function test_a_declared_field_lists_its_post_type() {
		register_post_type( 'product', array( 'show_in_rest' => true, 'public' => false ) );
		register_post_type( 'pallet', array( 'show_in_rest' => true, 'public' => false ) );
		$this->register_php_source( 'test/acf-like' );

		add_filter(
			'pattern_builder_binding_fields',
			function ( $fields, $source_name, $post_type ) {
				return 'test/acf-like' === $source_name && 'product' === $post_type
					? array( array( 'label' => 'SKU', 'args' => array( 'key' => 'sku' ) ) )
					: $fields;
			},
			10,
			3
		);

		$listed = $this->request_post_types();

		$this->assertContains( 'product', $listed );
		$this->assertNotContains( 'pallet', $listed, 'the filter leaked past the post type it named' );
	}

	/**
	 * A declaration reaches even a post type the other rules would skip.
	 */
	public function test_a_declared_field_overrides_the_internal_rule() {
		$this->register_php_source( 'test/for-templates' );

		add_filter(
			'pattern_builder_binding_fields',
			function ( $fields, $source_name, $post_type ) {
				return 'test/for-templates' === $source_name && 'wp_template' === $post_type
					? array( array( 'label' => 'Slot', 'args' => array( 'key' => 'slot' ) ) )
					: $fields;
			},
			10,
			3
		);

		$this->assertContains( 'wp_template', $this->request_post_types() );
	}

	/**
	 * Post data's three fields are not a reason to offer a post type.
	 *
	 * They are offered for every post type, so they cannot tell one from
	 * another — but they are still listed once a post type is chosen.
	 */
	public function test_universal_fields_do_not_list_a_post_type() {
		register_post_type( 'shelf', array( 'show_in_rest' => true, 'public' => false ) );

		$this->assertNotContains( 'shelf', $this->request_post_types() );
		$this->assertNotEmpty( $this->request( 'shelf' )['core/post-data'] );
	}

	/**
	 * Somebody who cannot edit posts cannot read the list.
	 */
	public function test_denies_a_user_who_cannot_edit_posts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/binding-fields' );
		$request->set_param( 'post_type', 'post' );

		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}
}
