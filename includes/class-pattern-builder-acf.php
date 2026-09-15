<?php
/**
 * Advanced Custom Fields support for the bindings panel.
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

/**
 * Declares ACF's fields to the bindings panel.
 *
 * ACF registers `acf/field` in PHP with a `get_value_callback` and nothing
 * else, which is all core's registry accepts — and it ships no `getFieldsList`
 * either, so no bindings UI can discover an ACF field by asking. Without this
 * the source appears with nothing under it.
 *
 * Enumeration is the only part supplied here. Resolving a binding stays ACF's
 * own callback, so the two gates it applies before returning a value are
 * applied before offering one: a field type that supports bindings, and a field
 * whose *Allow Access to Value in Editor UI* setting is on. Offering a field
 * that fails either would put a choice in the panel that renders nothing.
 */
class Pattern_Builder_ACF {

	/**
	 * The binding source ACF registers.
	 *
	 * @var string
	 */
	const SOURCE = 'acf/field';

	/**
	 * Hooks the field list up.
	 */
	public function __construct() {
		add_filter( 'pattern_builder_binding_fields', array( $this, 'add_fields' ), 10, 3 );
	}

	/**
	 * The ACF fields a post type's field groups offer.
	 *
	 * @param array  $fields      The fields so far.
	 * @param string $source_name The binding source's name.
	 * @param string $post_type   The post type being listed.
	 * @return array The fields.
	 */
	public function add_fields( $fields, $source_name, $post_type ) {
		if ( self::SOURCE !== $source_name || ! function_exists( 'acf_get_field_groups' ) ) {
			return $fields;
		}

		foreach ( acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( ! $this->is_bindable( $field ) ) {
					continue;
				}

				$fields[] = array(
					'label' => $field['label'] ? $field['label'] : $field['name'],
					'args'  => array( 'key' => $field['name'] ),
					'type'  => 'string',
				);
			}
		}

		return $fields;
	}

	/**
	 * Whether ACF would return a value for this field.
	 *
	 * @param array $field An ACF field.
	 * @return bool Whether to offer it.
	 */
	private function is_bindable( $field ): bool {
		if ( empty( $field['name'] ) || empty( $field['type'] ) ) {
			return false;
		}

		if ( ! function_exists( 'acf_field_type_supports' ) || ! acf_field_type_supports( $field['type'], 'bindings', true ) ) {
			return false;
		}

		/*
		 * ACF defaults this off for a field created since 6.3.6, so a field
		 * added today is not bindable until somebody turns it on.
		 */
		return ! empty( $field['allow_in_bindings'] );
	}
}
