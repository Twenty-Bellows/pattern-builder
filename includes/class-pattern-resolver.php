<?php
/**
 * Composes patterns and their content into plain blocks.
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Block_Patterns_Registry;

/**
 * Replaces `core/pattern` blocks that carry content with the referenced pattern's blocks,
 * with the content written into them.
 */
class Pattern_Resolver {
	/**
	 * The block bindings source that marks a pattern's content slots.
	 */
	const OVERRIDES_SOURCE = 'core/pattern-overrides';

	/**
	 * How many patterns have been expanded, for detecting whether a subtree needed this
	 * resolver at all.
	 *
	 * @var int
	 */
	private static $expansions = 0;

	/**
	 * Slugs currently being expanded, to stop a pattern containing itself.
	 *
	 * @var array<string, true>
	 */
	private static $expanding = array();

	/**
	 * Whether plain pattern blocks — no content, none inside — are inlined too, the way
	 * core's own resolver inlines them.
	 *
	 * @var bool
	 */
	private static $inline_plain = false;

	/**
	 * Cheap test for markup that might contain a pattern block.
	 *
	 * @param mixed $markup Block markup.
	 * @return bool Whether the markup is worth parsing.
	 */
	public static function contains_pattern_block( $markup ): bool {
		return is_string( $markup ) && false !== strpos( $markup, 'wp:pattern ' );
	}

	/**
	 * Composes every pattern with content in a piece of block markup.
	 *
	 * @param string $markup Block markup.
	 * @return string Block markup with those patterns composed into it, or the markup
	 * untouched if there were none.
	 */
	public static function resolve( string $markup ): string {
		if ( ! self::contains_pattern_block( $markup ) ) {
			return $markup;
		}

		$expansions = self::$expansions;
		$blocks     = self::resolve_blocks( parse_blocks( $markup ) );

		return self::$expansions === $expansions ? $markup : serialize_blocks( $blocks );
	}

	/**
	 * Composes a pattern the way the editor's pattern list needs it.
	 *
	 * @param string $markup Block markup.
	 * @return string Block markup with every pattern block composed into it, except the
	 * synced references, or the markup untouched if there were none.
	 */
	public static function compose( string $markup ): string {
		if ( ! self::contains_pattern_block( $markup ) ) {
			return $markup;
		}

		self::$inline_plain = true;

		try {
			$blocks = self::resolve_blocks( parse_blocks( $markup ) );
		} finally {
			self::$inline_plain = false;
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Composes every pattern with content in a list of parsed blocks.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array[] Parsed blocks, with those patterns composed into them.
	 */
	public static function resolve_blocks( array $blocks ): array {
		$resolved = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			foreach ( self::resolve_block( $block ) as $resolved_block ) {
				$resolved[] = $resolved_block;
			}
		}

		return $resolved;
	}

	/**
	 * Resolves a single parsed block.
	 *
	 * @param array $block A parsed block.
	 * @return array[] The blocks that replace it.
	 */
	private static function resolve_block( array $block ): array {
		if ( 'core/pattern' === ( $block['blockName'] ?? null ) ) {
			$expanded = self::expand_pattern_block( $block );
			return null === $expanded ? array( $block ) : $expanded;
		}

		if ( empty( $block['innerBlocks'] ) || empty( $block['innerContent'] ) ) {
			return array( $block );
		}
		$inner_blocks  = array();
		$inner_content = array();
		$index         = 0;

		foreach ( $block['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) ) {
				$inner_content[] = $chunk;
				continue;
			}

			if ( ! isset( $block['innerBlocks'][ $index ] ) ) {
				continue;
			}

			$resolved = self::resolve_block( $block['innerBlocks'][ $index ] );
			++$index;

			foreach ( $resolved as $resolved_block ) {
				$inner_blocks[]  = $resolved_block;
				$inner_content[] = null;
			}
		}

		$block['innerBlocks']  = $inner_blocks;
		$block['innerContent'] = $inner_content;

		return array( $block );
	}

	/**
	 * Replaces a pattern block with the pattern's blocks, content written in.
	 *
	 * @param array $block A parsed `core/pattern` block.
	 * @return array[]|null The blocks that replace it, an empty array to drop it, or null
	 * to leave it to core's resolver.
	 */
	private static function expand_pattern_block( array $block ): ?array {
		$slug     = $block['attrs']['slug'] ?? null;
		$content  = $block['attrs'][ Pattern_Block::CONTENT_ATTRIBUTE ] ?? null;
		$registry = WP_Block_Patterns_Registry::get_instance();

		if ( ! is_string( $slug ) || ! $registry->is_registered( $slug ) ) {
			return null;
		}
		if ( Synced_Patterns::is_synced( $slug ) ) {
			return array( $block );
		}
		if ( isset( self::$expanding[ $slug ] ) ) {
			return array();
		}

		$pattern     = $registry->get_registered( $slug );
		$has_content = is_array( $content ) && ! empty( $content );

		if ( ! self::$inline_plain && ! $has_content && ! self::contains_pattern_block( $pattern['content'] ?? null ) ) {
			return null;
		}

		$blocks = parse_blocks( $pattern['content'] );

		if ( $has_content ) {
			$blocks = self::apply_content( $blocks, $content );
			++self::$expansions;
		}

		$expansions               = self::$expansions;
		self::$expanding[ $slug ] = true;
		$blocks                   = self::resolve_blocks( $blocks );
		unset( self::$expanding[ $slug ] );
		if ( ! self::$inline_plain && ! $has_content && self::$expansions === $expansions ) {
			return null;
		}

		return self::add_pattern_metadata( $blocks, $pattern );
	}

	/**
	 * Marks a single-block pattern as an instance of that pattern.
	 *
	 * @param array[] $blocks The pattern's blocks.
	 * @param array   $pattern The registered pattern.
	 * @return array[] The blocks.
	 */
	private static function add_pattern_metadata( array $blocks, array $pattern ): array {
		if ( 1 !== count( $blocks ) || empty( $pattern['name'] ) ) {
			return $blocks;
		}

		$metadata                = $blocks[0]['attrs']['metadata'] ?? array();
		$metadata['patternName'] = $pattern['name'];
		$values                  = array(
			'name'        => $metadata['name'] ?? $pattern['title'] ?? null,
			'description' => $pattern['description'] ?? $metadata['description'] ?? null,
			'categories'  => $pattern['categories'] ?? $metadata['categories'] ?? null,
		);

		foreach ( $values as $key => $value ) {
			if ( ! $value ) {
				continue;
			}

			$metadata[ $key ] = is_array( $value )
				? array_map( 'sanitize_text_field', $value )
				: sanitize_text_field( $value );
		}

		$blocks[0]['attrs']['metadata'] = $metadata;

		return $blocks;
	}

	/**
	 * Writes a pattern's content into that pattern's blocks.
	 *
	 * @param array[] $blocks The pattern's parsed blocks.
	 * @param array   $content Content, keyed by slot name and then attribute name.
	 * @return array[] The blocks with the content written into them.
	 */
	public static function apply_content( array $blocks, array $content ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name   = $block['attrs']['metadata']['name'] ?? null;
			$values = ( is_string( $name ) && isset( $content[ $name ] ) && is_array( $content[ $name ] ) )
				? $content[ $name ]
				: array();

			$block = self::fill_slots( $block, $values );

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::apply_content( $block['innerBlocks'], $content );
			}

			$blocks[ $index ] = $block;
		}

		return $blocks;
	}

	/**
	 * Writes values into one block's content slots and removes its bindings.
	 *
	 * @param array $block A parsed block.
	 * @param array $values Values for this block, keyed by attribute name.
	 * @return array The updated block.
	 */
	private static function fill_slots( array $block, array $values ): array {
		$bindings = $block['attrs']['metadata']['bindings'] ?? null;

		if ( ! is_array( $bindings ) || empty( $bindings ) ) {
			return $block;
		}

		$binds_everything = self::OVERRIDES_SOURCE === ( $bindings['__default']['source'] ?? null );
		$slots            = $binds_everything
			? self::get_supported_attributes( $block['blockName'] ?? '' )
			: array();

		foreach ( $bindings as $attribute => $binding ) {
			if ( '__default' !== $attribute && self::OVERRIDES_SOURCE === ( $binding['source'] ?? null ) ) {
				$slots[] = $attribute;
			}
		}

		foreach ( array_unique( $slots ) as $attribute ) {
			if ( array_key_exists( $attribute, $values ) ) {
				$block = Block_Markup::set_attribute( $block, (string) $attribute, $values[ $attribute ] );
			}

			unset( $bindings[ $attribute ] );
		}

		if ( $binds_everything ) {
			unset( $bindings['__default'] );
		}

		if ( ! empty( $bindings ) ) {
			$block['attrs']['metadata']['bindings'] = $bindings;

			return $block;
		}

		unset( $block['attrs']['metadata']['bindings'] );

		if ( empty( $block['attrs']['metadata'] ) ) {
			unset( $block['attrs']['metadata'] );
		}

		return $block;
	}

	/**
	 * Lists the attributes a block type can bind, for `__default` bindings.
	 *
	 * @param string $block_name Block type name, including namespace.
	 * @return string[] Attribute names.
	 */
	private static function get_supported_attributes( string $block_name ): array {
		if ( function_exists( 'get_block_bindings_supported_attributes' ) ) {
			return get_block_bindings_supported_attributes( $block_name );
		}
		$supported = array(
			'core/paragraph' => array( 'content' ),
			'core/heading'   => array( 'content' ),
			'core/image'     => array( 'id', 'url', 'title', 'alt' ),
			'core/button'    => array( 'url', 'text', 'linkTarget', 'rel' ),
		);

		return $supported[ $block_name ] ?? array();
	}
}
