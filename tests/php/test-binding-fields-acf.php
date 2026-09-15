<?php
/**
 * Tests for declaring ACF's fields to the bindings panel.
 *
 * ACF is not installed here, so its three enumeration functions are stubbed.
 * What is under test is the gating: ACF publishes no field list of its own, so
 * this is the only thing deciding what the panel offers for `acf/field`, and
 * offering a field ACF would refuse to resolve is the failure that matters.
 *
 * @package Pattern_Builder
 */

/**
 * @covers \TwentyBellows\PatternBuilder\Pattern_Builder_ACF
 */
class Test_Binding_Fields_ACF extends WP_UnitTestCase {

	/**
	 * Field groups the stubbed `acf_get_fields()` answers with.
	 *
	 * @var array
	 */
	public static $groups = array();

	/**
	 * Post types the stubbed `acf_get_field_groups()` answers for.
	 *
	 * @var array
	 */
	public static $for_post_type = array();

	public function set_up() {
		parent::set_up();

		self::$groups        = array();
		self::$for_post_type = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The registry outlives a test, so stand the source up only once.
		if ( ! get_block_bindings_source( 'acf/field' ) ) {
			register_block_bindings_source(
				'acf/field',
				array(
					'label'              => 'Custom Fields',
					'get_value_callback' => '__return_empty_string',
				)
			);
		}
	}

	/**
	 * Declares a field group against a post type, as ACF would hold one.
	 *
	 * @param string $post_type The post type its location rules match.
	 * @param array  $fields    The fields in the group.
	 */
	private function given_acf_fields( string $post_type, array $fields ) {
		self::$for_post_type[ $post_type ] = 'group_1';
		self::$groups['group_1']           = $fields;
	}

	/**
	 * An ACF field as `acf_get_fields()` returns one.
	 *
	 * @param array $overrides Properties to change.
	 * @return array The field.
	 */
	private function acf_field( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'              => 'brand',
				'label'             => 'Brand',
				'type'              => 'text',
				'allow_in_bindings' => true,
			),
			$overrides
		);
	}

	/**
	 * The fields offered for `acf/field`.
	 *
	 * @param string $post_type The post type to ask about.
	 * @return array The fields.
	 */
	private function offered( string $post_type = 'pickle' ): array {
		$request = new WP_REST_Request( 'GET', '/pattern-builder/v1/binding-fields' );
		$request->set_param( 'post_type', $post_type );

		$data = (array) rest_get_server()->dispatch( $request )->get_data();

		return $data['acf/field'] ?? array();
	}

	/**
	 * A field group on a post type becomes something to bind to.
	 */
	public function test_offers_a_field_from_a_matching_group() {
		$this->given_acf_fields( 'pickle', array( $this->acf_field() ) );

		$this->assertSame(
			array(
				array(
					'label' => 'Brand',
					'args'  => array( 'key' => 'brand' ),
					'type'  => 'string',
				),
			),
			$this->offered()
		);
	}

	/**
	 * A field ACF will not resolve is not offered.
	 *
	 * ACF defaults this off for fields created since 6.3.6, and its own
	 * callback returns an empty string for one — so offering it would put a
	 * choice in the panel that renders nothing.
	 */
	public function test_skips_a_field_not_allowed_in_bindings() {
		$this->given_acf_fields( 'pickle', array( $this->acf_field( array( 'allow_in_bindings' => false ) ) ) );

		$this->assertSame( array(), $this->offered() );
	}

	/**
	 * A field whose type ACF excludes from bindings is not offered.
	 */
	public function test_skips_a_field_type_that_does_not_support_bindings() {
		$this->given_acf_fields( 'pickle', array( $this->acf_field( array( 'type' => 'tab' ) ) ) );

		$this->assertSame( array(), $this->offered() );
	}

	/**
	 * A post type with no matching group is offered nothing.
	 */
	public function test_offers_nothing_for_an_unrelated_post_type() {
		$this->given_acf_fields( 'pickle', array( $this->acf_field() ) );

		$this->assertSame( array(), $this->offered( 'post' ) );
	}

	/**
	 * A field with no label falls back to its name.
	 */
	public function test_falls_back_to_the_field_name() {
		$this->given_acf_fields( 'pickle', array( $this->acf_field( array( 'label' => '' ) ) ) );

		$this->assertSame( 'brand', $this->offered()[0]['label'] );
	}

	/**
	 * A post type with ACF fields is listed even when it registers no meta.
	 */
	public function test_an_acf_post_type_reaches_the_lens() {
		register_post_type( 'pickle', array( 'show_in_rest' => true, 'public' => false ) );
		$this->given_acf_fields( 'pickle', array( $this->acf_field() ) );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/pattern-builder/v1/binding-fields' )
		);

		$this->assertContains( 'pickle', (array) $response->get_data() );
	}
}

if ( ! function_exists( 'acf_get_field_groups' ) ) {
	/**
	 * Stub: the field groups whose location rules match a post type.
	 *
	 * @param array $args Query arguments.
	 * @return array Field groups.
	 */
	function acf_get_field_groups( $args = array() ) {
		$key = Test_Binding_Fields_ACF::$for_post_type[ $args['post_type'] ?? '' ] ?? null;

		return $key ? array( array( 'key' => $key ) ) : array();
	}

	/**
	 * Stub: the fields in a group.
	 *
	 * @param array $group A field group.
	 * @return array Fields.
	 */
	function acf_get_fields( $group ) {
		return Test_Binding_Fields_ACF::$groups[ $group['key'] ] ?? array();
	}

	/**
	 * Stub: whether a field type supports a feature.
	 *
	 * Mirrors ACF, which turns `bindings` off for its layout field types.
	 *
	 * @param string $name    The field type.
	 * @param string $prop    The feature.
	 * @param mixed  $default Value when the type says nothing.
	 * @return mixed Whether it is supported.
	 */
	function acf_field_type_supports( $name = '', $prop = '', $default = false ) {
		$unsupported = array( 'tab', 'message', 'accordion', 'group' );

		if ( 'bindings' === $prop && in_array( $name, $unsupported, true ) ) {
			return false;
		}

		return $default;
	}
}
