<?php
/**
 * Teaches the `core/pattern` block to accept content.
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Block;
use WP_Block_Patterns_Registry;
use WP_Embed;

/**
 * Extends `core/pattern` so a `content` attribute fills the pattern's override slots,
 * exactly the way `core/block` already works.
 */
class Pattern_Block {
	/**
	 * Name of the attribute that carries a pattern's content.
	 */
	const CONTENT_ATTRIBUTE = 'content';

	/**
	 * Name of the block context the content attribute provides.
	 */
	const OVERRIDES_CONTEXT = 'pattern/overrides';

	/**
	 * Registers the block type filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'register_block_type_args', array( $this, 'add_content_support' ), 10, 2 );
	}

	/**
	 * Adds the `content` attribute, the context it provides, and the render callback that
	 * passes that context on to the pattern's blocks.
	 *
	 * @param array  $args Arguments the block type is being registered with.
	 * @param string $block_type Block type name, including namespace.
	 * @return array Filtered arguments.
	 */
	public function add_content_support( $args, $block_type ): array {
		if ( 'core/pattern' !== $block_type || ! is_array( $args ) ) {
			return $args;
		}

		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}

		$args['attributes'][ self::CONTENT_ATTRIBUTE ] = array( 'type' => 'object' );

		if ( ! isset( $args['provides_context'] ) || ! is_array( $args['provides_context'] ) ) {
			$args['provides_context'] = array();
		}

		$args['provides_context'][ self::OVERRIDES_CONTEXT ] = self::CONTENT_ATTRIBUTE;

		$args['render_callback'] = array( $this, 'render' );

		return $args;
	}

	/**
	 * Renders a `core/pattern` block.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content Block save content.
	 * @param WP_Block $block The block instance.
	 * @return string Rendered pattern.
	 */
	public function render( $attributes, $content, $block ): string {
		static $seen_slugs = array();

		if ( ! $block instanceof WP_Block || empty( $attributes['slug'] ) || ! is_string( $attributes['slug'] ) ) {
			return '';
		}

		$slug     = $attributes['slug'];
		$registry = WP_Block_Patterns_Registry::get_instance();

		if ( ! $registry->is_registered( $slug ) ) {
			return '';
		}

		if ( isset( $seen_slugs[ $slug ] ) ) {
			if ( ! WP_DEBUG || ! WP_DEBUG_DISPLAY ) {
				return '';
			}

			return sprintf(
				/* translators: %s: A pattern's slug. */
				__( '[block rendering halted for pattern "%s"]', 'pattern-builder' ),
				$slug
			);
		}

		$pattern      = $registry->get_registered( $slug );
		$inner_blocks = parse_blocks( $pattern['content'] );

		if ( empty( $inner_blocks ) ) {
			return '';
		}

		$seen_slugs[ $slug ] = true;

		$block->parsed_block['innerBlocks']  = $inner_blocks;
		$block->parsed_block['innerContent'] = array_fill( 0, count( $inner_blocks ), null );
		$block->refresh_context_dependents();
		$rendered = $block->render( array( 'dynamic' => false ) );

		unset( $seen_slugs[ $slug ] );

		global $wp_embed;
		if ( $wp_embed instanceof WP_Embed ) {
			$rendered = $wp_embed->autoembed( $rendered );
		}

		return $rendered;
	}
}
