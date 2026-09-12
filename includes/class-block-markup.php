<?php
/**
 * Writes attribute values into a parsed block's markup.
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Block_Type_Registry;
use WP_HTML_Tag_Processor;

/**
 * Sets an attribute on a parsed block.
 */
class Block_Markup {
	/**
	 * Elements a block's `save()` leaves out rather than writing empty.
	 */
	const OPTIONAL_ELEMENTS = array( 'figcaption', 'cite' );

	/**
	 * Sets an attribute's value on a parsed block.
	 *
	 * @param array  $block A parsed block.
	 * @param string $attribute Attribute name.
	 * @param mixed  $value Attribute value.
	 * @return array The updated block.
	 */
	public static function set_attribute( array $block, string $attribute, $value ): array {
		$attributes = self::get_block_type_attributes( $block['blockName'] ?? '' );
		if ( null !== $attributes && ! isset( $attributes[ $attribute ] ) ) {
			return $block;
		}

		$definition = $attributes[ $attribute ] ?? null;
		if ( ! is_array( $definition ) || ! isset( $definition['source'] ) ) {
			$block['attrs'][ $attribute ] = $value;

			return $block;
		}

		if ( ! is_scalar( $value ) ) {
			return $block;
		}

		$markup = self::get_markup( $block );

		if ( null === $markup ) {
			return $block;
		}

		$selectors = isset( $definition['selector'] ) && is_string( $definition['selector'] )
			? self::parse_selectors( $definition['selector'] )
			: array();

		switch ( $definition['source'] ) {
			case 'html':
			case 'rich-text':
				$updated = '' === (string) $value && self::elements_are_optional( $selectors )
					? Inner_HTML_Processor::remove_element( $markup, $selectors )
					: Inner_HTML_Processor::replace_inner_html(
						$markup,
						$selectors,
						wp_kses_post( (string) $value )
					);
				break;

			case 'attribute':
				$updated = isset( $definition['attribute'] ) && is_string( $definition['attribute'] )
					? self::set_html_attribute( $markup, $selectors, $definition['attribute'], (string) $value )
					: null;
				break;

			default:
				$updated = null;
		}

		if ( null === $updated ) {
			return $block;
		}

		$block['innerHTML']    = $updated;
		$block['innerContent'] = array( $updated );

		return $block;
	}

	/**
	 * Looks up a block type's attribute schemas.
	 *
	 * @param string $block_name Block type name, including namespace.
	 * @return array|null The schemas, or null when the block type is not registered.
	 */
	private static function get_block_type_attributes( string $block_name ): ?array {
		if ( '' === $block_name ) {
			return null;
		}

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );

		if ( null === $block_type || ! is_array( $block_type->attributes ) ) {
			return null;
		}

		return $block_type->attributes;
	}

	/**
	 * Returns the markup an attribute can be written into.
	 *
	 * @param array $block A parsed block.
	 * @return string|null The block's markup, or null if it cannot be written to.
	 */
	private static function get_markup( array $block ): ?string {
		if ( ! empty( $block['innerBlocks'] ) ) {
			return null;
		}

		return isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : null;
	}

	/**
	 * Splits an attribute schema's selector into usable tag names.
	 *
	 * @param string $selector An attribute schema's selector.
	 * @return string[] Tag names.
	 */
	private static function parse_selectors( string $selector ): array {
		$tags = array();

		foreach ( explode( ',', $selector ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( 1 === preg_match( '/^[a-zA-Z][a-zA-Z0-9-]*$/', $candidate ) ) {
				$tags[] = $candidate;
			}
		}

		return $tags;
	}

	/**
	 * Whether an empty value means removing these elements rather than emptying them.
	 *
	 * @param string[] $selectors Tag names from the attribute's schema.
	 * @return bool Whether all of them are elements `save()` omits when empty.
	 */
	private static function elements_are_optional( array $selectors ): bool {
		if ( empty( $selectors ) ) {
			return false;
		}

		foreach ( $selectors as $tag ) {
			if ( ! in_array( strtolower( $tag ), self::OPTIONAL_ELEMENTS, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sets an HTML attribute on the first tag matching one of the selectors.
	 *
	 * @param string   $markup The block's markup.
	 * @param string[] $selectors Tag names to look for, in order.
	 * @param string   $name HTML attribute name.
	 * @param string   $value HTML attribute value.
	 * @return string|null The updated markup, or null if nothing matched.
	 */
	private static function set_html_attribute( string $markup, array $selectors, string $name, string $value ): ?string {
		foreach ( $selectors as $tag ) {
			$processor = new WP_HTML_Tag_Processor( $markup );

			if ( $processor->next_tag( array( 'tag_name' => $tag ) ) ) {
				$processor->set_attribute( $name, $value );

				return $processor->get_updated_html();
			}
		}

		return null;
	}
}
