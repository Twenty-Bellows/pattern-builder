<?php
/**
 * What PHP can honestly say about pattern markup before it is stored.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Block_Patterns_Registry;
use WP_Block_Type_Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checks a write runs before it lands markup, and their limits.
 *
 * Whether a block is *valid* — whether re-running its `save()` reproduces the
 * markup — is JavaScript's to answer, and nothing here pretends otherwise: an
 * agent still validates before it calls `create-pattern`, with the tool the
 * site hands it. What PHP can settle is a different set of failures, every
 * one of them silent at render and every one of them cheap to catch here:
 *
 *  1. **Attribute JSON that does not parse.** `WP_Block_Parser` reads it as
 *     *no* attributes and carries on, so a heading that lost a brace stores
 *     as a heading with nothing set — and a Pattern Overrides slot that lost
 *     one is quietly no longer a slot.
 *  2. **Markup contradicting its own attributes**: a heading whose tag
 *     disagrees with its `level`, a list whose element disagrees with
 *     `ordered`. Valid nowhere, and reported by every editor that opens it.
 *  3. **A block this site has not registered.** It parses to `core/missing`
 *     and renders as a grey box; `list-block-types` says what is here.
 *  4. **A `core/pattern` reference that resolves to nothing** — an unresolved
 *     reference renders as nothing at all, with no error anywhere — or that
 *     names the pattern being written, which core drops as a loop.
 *  5. **A slot that cannot be filled**: a `content` key naming no slot in the
 *     referenced pattern (a misspelt key is simply ignored), a Pattern
 *     Overrides binding with no `metadata.name` (the binding source returns
 *     nothing for it), or a binding on a block core cannot bind.
 *
 * The same shape `Pattern_Validator::check_block_markup()` has on
 * patternbuilderwp.com, which runs the first two on every upload. Refusing
 * here means the failure is named while the agent is still holding the
 * markup, rather than discovered on a page a person thinks is finished.
 */
class Pattern_Builder_Markup_Checks {

	/**
	 * The binding source Pattern Overrides slots use.
	 */
	const OVERRIDES_SOURCE = 'core/pattern-overrides';

	/**
	 * Check markup, refusing it with every problem named.
	 *
	 * @param string $markup    Block markup.
	 * @param string $self_name The name this markup is being stored under, so
	 *                          a reference to itself can be refused.
	 * @return true|WP_Error `pb_markup_refused`, with every problem under `problems`.
	 */
	public static function check( $markup, $self_name = '' ) {
		$problems = self::problems( (string) $markup, (string) $self_name );

		if ( ! $problems ) {
			return true;
		}

		return new WP_Error(
			'pb_markup_refused',
			sprintf(
				/* translators: 1: how many problems, 2: the problems, each a sentence. */
				_n(
					'The markup was not stored: %2$s',
					'The markup was not stored, for %1$d reasons: %2$s',
					count( $problems ),
					'pattern-builder'
				),
				count( $problems ),
				implode( ' ', $problems )
			),
			array(
				'status'   => 400,
				'problems' => $problems,
			)
		);
	}

	/**
	 * Every problem in some markup, in the order found.
	 *
	 * @param string $markup    Block markup.
	 * @param string $self_name The name this markup is stored under.
	 * @return string[]
	 */
	public static function problems( $markup, $self_name = '' ) {
		if ( '' === trim( $markup ) ) {
			return array( __( 'The content is empty.', 'pattern-builder' ) );
		}

		$problems = self::malformed_attributes( $markup );
		$blocks   = parse_blocks( $markup );

		$problems = array_merge( $problems, self::walk( $blocks, $self_name ) );

		return array_values( array_unique( $problems ) );
	}

	/**
	 * Block comments whose attribute object is not JSON.
	 *
	 * Matches whatever sits between the block name and the delimiter rather
	 * than a balanced-looking object, because the usual damage is a missing
	 * brace and no `{...}` pattern would match one.
	 *
	 * @param string $markup Block markup.
	 * @return string[]
	 */
	private static function malformed_attributes( $markup ) {
		$problems = array();

		if ( ! preg_match_all( '/<!--\s+wp:([a-z0-9-]+\/?[a-z0-9-]*)\s+([^\s].*?)\s*\/?-->/s', $markup, $matches, PREG_SET_ORDER ) ) {
			return $problems;
		}

		foreach ( $matches as $match ) {
			if ( '{' !== substr( ltrim( $match[2] ), 0, 1 ) ) {
				continue;
			}
			if ( null === json_decode( $match[2], true ) ) {
				$problems[] = sprintf(
					/* translators: %s: block name. */
					__( 'The attributes on a %s block are not valid JSON, so WordPress would read them as no attributes at all — and a Pattern Overrides slot whose metadata is lost that way is silently no longer a slot.', 'pattern-builder' ),
					false === strpos( $match[1], '/' ) ? 'core/' . $match[1] : $match[1]
				);
			}
		}

		return $problems;
	}

	/**
	 * Walk a parsed tree for everything but the JSON.
	 *
	 * @param array  $blocks    Parsed blocks.
	 * @param string $self_name The name this markup is stored under.
	 * @return string[]
	 */
	private static function walk( $blocks, $self_name ) {
		$problems = array();
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			// Freeform whitespace between blocks.
			if ( '' === $name ) {
				continue;
			}

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html  = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

			if ( null === $registry->get_registered( $name ) ) {
				$problems[] = sprintf(
					/* translators: %s: block name. */
					__( 'No block named %s is registered on this site, so it would parse as core/missing and render as a block that cannot be displayed. list-block-types says what is here.', 'pattern-builder' ),
					$name
				);
			}

			$problems = array_merge( $problems, self::contradictions( $name, $attrs, $html ) );
			$problems = array_merge( $problems, self::slot_declaration( $name, $attrs ) );

			if ( 'core/pattern' === $name ) {
				$problems = array_merge( $problems, self::reference( $attrs, $self_name ) );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$problems = array_merge( $problems, self::walk( $block['innerBlocks'], $self_name ) );
			}
		}

		return $problems;
	}

	/**
	 * Markup that says one thing while the attributes say another.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Attributes.
	 * @param string $html  The block's own HTML.
	 * @return string[]
	 */
	private static function contradictions( $name, $attrs, $html ) {
		$problems = array();

		if ( 'core/heading' === $name ) {
			$level = isset( $attrs['level'] ) ? (int) $attrs['level'] : 2;
			if ( preg_match( '/<h([1-6])[\s>]/i', $html, $tag ) && (int) $tag[1] !== $level ) {
				$problems[] = sprintf(
					/* translators: 1: the level attribute, 2: the tag in the markup. */
					__( 'A heading says level %1$d and its markup is an h%2$d; every editor will call it invalid.', 'pattern-builder' ),
					$level,
					(int) $tag[1]
				);
			}
		}

		if ( 'core/list' === $name && preg_match( '/<(ol|ul)[\s>]/i', $html, $tag ) ) {
			$ordered = ! empty( $attrs['ordered'] );
			$is_ol   = 'ol' === strtolower( $tag[1] );
			if ( $ordered !== $is_ol ) {
				$problems[] = $ordered
					? __( 'A list is marked ordered and its markup is a ul; every editor will call it invalid.', 'pattern-builder' )
					: __( 'A list\'s markup is an ol and the block is not marked ordered; every editor will call it invalid.', 'pattern-builder' );
			}
		}

		return $problems;
	}

	/**
	 * A Pattern Overrides slot declared in a way nothing can fill.
	 *
	 * The binding source reads `metadata.name` and returns nothing without it,
	 * and a block core cannot bind never has its value replaced — in both
	 * cases the placeholder ships as though it were the page's copy.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Attributes.
	 * @return string[]
	 */
	private static function slot_declaration( $name, $attrs ) {
		$bindings = isset( $attrs['metadata']['bindings'] ) && is_array( $attrs['metadata']['bindings'] )
			? $attrs['metadata']['bindings']
			: array();

		$bound = false;
		foreach ( $bindings as $binding ) {
			if ( is_array( $binding ) && self::OVERRIDES_SOURCE === ( isset( $binding['source'] ) ? $binding['source'] : null ) ) {
				$bound = true;
				break;
			}
		}

		if ( ! $bound ) {
			return array();
		}

		$problems = array();

		if ( empty( $attrs['metadata']['name'] ) ) {
			$problems[] = sprintf(
				/* translators: %s: block name. */
				__( 'A %s block carries a Pattern Overrides binding and no metadata.name, so nothing can fill it: the slot\'s name is what a page pattern\'s content keys refer to.', 'pattern-builder' ),
				$name
			);
		}

		if ( ! self::bindable_attributes( $name ) ) {
			$problems[] = sprintf(
				/* translators: 1: block name, 2: the blocks Pattern Overrides can bind, comma separated. */
				__( 'A %1$s block cannot take a Pattern Overrides slot on this site; the binding would never fill. Slots land on %2$s.', 'pattern-builder' ),
				$name,
				implode( ', ', self::bindable_blocks() )
			);
		}

		return $problems;
	}

	/**
	 * A `core/pattern` reference, and the slots it fills.
	 *
	 * @param array  $attrs     The reference's attributes.
	 * @param string $self_name The name this markup is stored under.
	 * @return string[]
	 */
	private static function reference( $attrs, $self_name ) {
		$slug = isset( $attrs['slug'] ) ? trim( (string) $attrs['slug'] ) : '';

		if ( '' === $slug ) {
			return array( __( 'A core/pattern block names no pattern: with no slug it renders as nothing at all.', 'pattern-builder' ) );
		}

		if ( '' !== $self_name && $slug === $self_name ) {
			return array(
				sprintf(
					/* translators: %s: pattern name. */
					__( 'The pattern references itself (%s); core drops a pattern that contains itself.', 'pattern-builder' ),
					$slug
				),
			);
		}

		$referenced = self::referenced_markup( $slug );
		if ( null === $referenced ) {
			return array(
				sprintf(
					/* translators: %s: pattern name. */
					__( 'The reference to %s resolves to nothing on this site, and an unresolved core/pattern renders as nothing at all. Store the referenced pattern first (leaves before the patterns that use them); list-patterns says what exists.', 'pattern-builder' ),
					$slug
				),
			);
		}

		$content = isset( $attrs['content'] ) && is_array( $attrs['content'] ) ? $attrs['content'] : array();
		if ( ! $content ) {
			return array();
		}

		$slots   = self::slots_in( parse_blocks( $referenced ) );
		$unknown = array_diff( array_map( 'strval', array_keys( $content ) ), $slots );

		if ( ! $unknown ) {
			return array();
		}

		if ( ! $slots ) {
			return array(
				sprintf(
					/* translators: 1: pattern name, 2: the content keys given, comma separated. */
					__( 'The reference to %1$s gives content for %2$s, but that pattern declares no Pattern Overrides slots, so every value would be ignored and the placeholder copy would ship.', 'pattern-builder' ),
					$slug,
					implode( ', ', array_keys( $content ) )
				),
			);
		}

		return array(
			sprintf(
				/* translators: 1: pattern name, 2: the keys that match no slot, 3: the slots the pattern has. */
				__( 'The reference to %1$s gives content for %2$s, which names no slot there — an unknown key is ignored and the placeholder copy ships in its place. Its slots are: %3$s.', 'pattern-builder' ),
				$slug,
				implode( ', ', $unknown ),
				implode( ', ', $slots )
			),
		);
	}

	/**
	 * The markup a reference resolves to, from wherever it is.
	 *
	 * The registry is what `core/pattern` renders from, but a theme pattern
	 * written moments ago is only registered on the next request — WordPress
	 * reads the theme's files on `init` — so the files are asked as well.
	 * That is also what lets a page be stored in the same session as the
	 * sections it references.
	 *
	 * @param string $slug Pattern name.
	 * @return string|null
	 */
	private static function referenced_markup( $slug ) {
		$registry = WP_Block_Patterns_Registry::get_instance();
		if ( $registry->is_registered( $slug ) ) {
			$pattern = $registry->get_registered( $slug );
			return isset( $pattern['content'] ) ? (string) $pattern['content'] : '';
		}

		$store   = new Pattern_File_Store();
		$pattern = $store->find_theme_pattern( $slug );

		return $pattern ? (string) $pattern->content : null;
	}

	/**
	 * The slot names a pattern declares.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return string[]
	 */
	private static function slots_in( $blocks ) {
		$slots = array();

		foreach ( $blocks as $block ) {
			$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$bindings = isset( $attrs['metadata']['bindings'] ) && is_array( $attrs['metadata']['bindings'] ) ? $attrs['metadata']['bindings'] : array();

			foreach ( $bindings as $binding ) {
				if ( is_array( $binding ) && self::OVERRIDES_SOURCE === ( isset( $binding['source'] ) ? $binding['source'] : null ) && ! empty( $attrs['metadata']['name'] ) ) {
					$slots[] = (string) $attrs['metadata']['name'];
					break;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$slots = array_merge( $slots, self::slots_in( $block['innerBlocks'] ) );
			}
		}

		return array_values( array_unique( $slots ) );
	}

	/**
	 * The attributes Pattern Overrides can bind on a block, as core says.
	 *
	 * @param string $name Block name.
	 * @return string[]
	 */
	private static function bindable_attributes( $name ) {
		if ( function_exists( 'get_block_bindings_supported_attributes' ) ) {
			return (array) get_block_bindings_supported_attributes( $name );
		}

		// WordPress 6.8 and earlier keep the list private to WP_Block.
		$supported = array(
			'core/paragraph' => array( 'content' ),
			'core/heading'   => array( 'content' ),
			'core/image'     => array( 'id', 'url', 'title', 'alt' ),
			'core/button'    => array( 'url', 'text', 'linkTarget', 'rel' ),
		);

		return isset( $supported[ $name ] ) ? $supported[ $name ] : array();
	}

	/**
	 * The blocks a slot can land on here, for the message.
	 *
	 * @return string[]
	 */
	private static function bindable_blocks() {
		$blocks = array();
		foreach ( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $name ) {
			if ( self::bindable_attributes( $name ) ) {
				$blocks[] = $name;
			}
		}

		return $blocks ? $blocks : array( 'core/paragraph', 'core/heading', 'core/image', 'core/button' );
	}
}
