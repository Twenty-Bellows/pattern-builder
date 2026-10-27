<?php

namespace TwentyBellows\PatternBuilder;

/**
 * Whether markup lays out as written: the relationship between blocks that no check of one
 * block can see. A flex or grid container whose children cannot size renders as a vertical
 * stack, and that markup is entirely valid, so nothing else reports it. So does a group that
 * pads its own sides to place its content, which renders but does the theme's job: content
 * takes its width from Regular or Wide and its gutter from the root padding.
 *
 * Made here, on the site, because everything it needs is here: the parser, and the patterns
 * a reference points at. These are warnings rather than refusals — the markup works, it
 * just does not look like what was meant.
 */
class Pattern_Builder_Composition_Checks {
	/**
	 * The layouts that put their children in tracks.
	 */
	const TRACK_LAYOUTS = array( 'flex', 'grid' );

	/**
	 * Every container in some markup whose children will not lay out as written.
	 *
	 * @param string $markup Block markup.
	 * @return array[] Warnings, each with a `code` and a `message`.
	 */
	public static function warnings( $markup ) {
		$warnings = array();
		$roots    = array();

		foreach ( self::named( parse_blocks( (string) $markup ) ) as $block ) {
			self::visit( $block, $warnings, $roots );
		}

		return $warnings;
	}

	/**
	 * Check one block's own padding and its children, then each child.
	 *
	 * @param array $block    A parsed block.
	 * @param array $warnings Warnings so far.
	 * @param array $roots    Referenced patterns' root blocks, by slug, as resolved so far.
	 */
	private static function visit( $block, &$warnings, &$roots ) {
		$padding = self::side_padding( $block );
		if ( $padding ) {
			$name       = $block['attrs']['metadata']['name'] ?? '';
			$warnings[] = array(
				'code'    => 'composition_side_padding',
				'message' => sprintf(
					/* translators: 1: the group's name in quotes, or nothing, 2: why its side padding is placing its content. */
					__( 'The group%1$s is %2$s to place its content. Content gets its width from Regular (no align: the content size) or Wide ("align":"wide"), and its distance from the window\'s edges from the theme\'s root padding: drop the left and right padding and use those. Keep full width for a band whose background has to reach the edges.', 'pattern-builder' ),
					'' === $name ? '' : sprintf( ' "%s"', $name ),
					$padding
				),
			);
		}

		$children = self::named( $block['innerBlocks'] ?? array() );
		$layout   = $block['attrs']['layout'] ?? array();
		$type     = is_array( $layout ) ? ( $layout['type'] ?? null ) : null;

		$grid_sized = 'grid' === $type && ( ! empty( $layout['columnCount'] ) || ! empty( $layout['minimumColumnWidth'] ) );

		if ( $children && in_array( $type, self::TRACK_LAYOUTS, true ) && ! $grid_sized ) {
			$reports = array();
			foreach ( $children as $child ) {
				$reports[] = self::sizing( $child, $roots );
			}

			$fills   = array_values( array_filter( $reports, static fn( $report ) => ! empty( $report['constrained'] ) ) );
			$unsized = array_filter( $reports, static fn( $report ) => empty( $report['sized'] ) );

			if ( $fills ) {
				$warnings[] = array(
					'code'    => 'composition_children_fill_track',
					'message' => sprintf(
						/* translators: 1: "flex" or "grid", 2: how many children fill the track, 3: how many children there are, 4: the first referenced pattern, if any. */
						__( 'A %1$s container has %2$d of %3$d children whose root is a constrained group, which fills the whole track, so this renders as a vertical stack rather than a row%4$s. Give the child root a flow layout instead, or a width.', 'pattern-builder' ),
						$type,
						count( $fills ),
						count( $children ),
						empty( $fills[0]['via'] ) ? '' : sprintf( ' (first: %s)', $fills[0]['via'] )
					),
				);
			} elseif ( 'flex' === $type && count( $unsized ) >= 3 ) {
				$warnings[] = array(
					'code'    => 'composition_flex_unsized',
					'message' => sprintf(
						/* translators: %d: how many children have no width. */
						__( 'A flex container has %d children without a width, so flex sizes them by their content and this will not hold a grid. Use core/columns for a fixed count, or a grid layout to wrap.', 'pattern-builder' ),
						count( $unsized )
					),
				);
			}

			$unknown = array_unique( array_filter( array_column( $reports, 'unknown' ) ) );
			foreach ( $unknown as $slug ) {
				$warnings[] = array(
					'code'    => 'composition_reference_unknown',
					'message' => sprintf(
						/* translators: %s: the referenced pattern. */
						__( 'The reference to %s inside a flex or grid container does not resolve to a single root block, so whether it sizes in its track is unknown.', 'pattern-builder' ),
						$slug
					),
				);
			}
		}

		foreach ( $children as $child ) {
			self::visit( $child, $warnings, $roots );
		}
	}

	/**
	 * Whether a child can size itself inside a flex or grid track: by a width, a stretch or
	 * a span of its own, or else by what its root lays out as, following a reference to the
	 * pattern it places.
	 *
	 * @param array $child A parsed child block.
	 * @param array $roots Referenced patterns' root blocks, by slug, as resolved so far.
	 * @return array `sized`, and when it is not: `constrained`, `via` or `unknown`.
	 */
	private static function sizing( $child, &$roots ) {
		$attrs = $child['attrs'] ?? array();

		if ( ! empty( $attrs['width'] ) || ! empty( $attrs['style']['layout']['selfStretch'] ) || ! empty( $attrs['style']['layout']['columnSpan'] ) ) {
			return array( 'sized' => true );
		}

		$inspect = $child;
		$via     = null;

		if ( 'core/pattern' === $child['blockName'] && ! empty( $attrs['slug'] ) ) {
			$slug = (string) $attrs['slug'];
			if ( ! array_key_exists( $slug, $roots ) ) {
				$roots[ $slug ] = self::root_of( $slug );
			}
			if ( null === $roots[ $slug ] ) {
				return array(
					'sized'   => false,
					'unknown' => $slug,
				);
			}
			$inspect = $roots[ $slug ];
			$via     = $slug;
		}

		$type = $inspect['attrs']['layout']['type'] ?? null;

		return array(
			'sized'       => false,
			'constrained' => 'constrained' === $type,
			'via'         => $via,
		);
	}

	/**
	 * Why a group's own left or right padding is placing its content, or an empty string
	 * when it is not: on a full-width group, or on one with nothing painted behind it.
	 * Padding inside a painted plate or card keeps text off its own edges and is left
	 * alone, and so is a block style variation, which may paint one. Keep in step with
	 * sidePadding() in guides/pattern-author/scripts/validate-pattern.mjs.
	 *
	 * @param array $block A parsed block.
	 * @return string
	 */
	private static function side_padding( $block ) {
		$attrs   = $block['attrs'] ?? array();
		$padding = $attrs['style']['spacing']['padding'] ?? null;

		if ( 'core/group' !== $block['blockName'] || ! is_array( $padding ) ) {
			return '';
		}

		$sides = array_values(
			array_filter(
				array( 'left', 'right' ),
				static fn( $side ) => isset( $padding[ $side ] ) && ! preg_match( '/^0(px|r?em|%)?$/', trim( (string) $padding[ $side ] ) )
			)
		);
		if ( ! $sides ) {
			return '';
		}

		$set = 2 === count( $sides )
			? __( 'setting left and right padding', 'pattern-builder' )
			/* translators: %s: "left" or "right". */
			: sprintf( __( 'setting %s padding', 'pattern-builder' ), $sides[0] );

		if ( 'full' === ( $attrs['align'] ?? '' ) ) {
			/* translators: %s: which padding it sets, as in "setting left and right padding". */
			return sprintf( __( 'full width and %s', 'pattern-builder' ), $set );
		}

		$painted = ! empty( $attrs['backgroundColor'] )
			|| ! empty( $attrs['gradient'] )
			|| ! empty( $attrs['borderColor'] )
			|| ! empty( $attrs['style']['color']['background'] )
			|| ! empty( $attrs['style']['color']['gradient'] )
			|| ! empty( $attrs['style']['background']['backgroundImage'] )
			|| ! empty( $attrs['style']['border'] )
			|| preg_match( '/(^|\s)is-style-/', (string) ( $attrs['className'] ?? '' ) );

		/* translators: %s: which padding it sets, as in "setting left and right padding". */
		return $painted ? '' : sprintf( __( 'painting nothing behind it and %s', 'pattern-builder' ), $set );
	}

	/**
	 * The one block a referenced pattern is made of, or null when it is not found or has
	 * more than one block at its root.
	 *
	 * @param string $slug Pattern name.
	 * @return array|null
	 */
	private static function root_of( $slug ) {
		$markup = Pattern_Builder_Markup_Checks::referenced_markup( $slug );

		if ( null === $markup ) {
			return null;
		}

		$blocks = self::named( parse_blocks( $markup ) );

		return 1 === count( $blocks ) ? $blocks[0] : null;
	}

	/**
	 * The blocks among some parsed ones, without the whitespace between them.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	private static function named( $blocks ) {
		return array_values( array_filter( (array) $blocks, static fn( $block ) => ! empty( $block['blockName'] ) ) );
	}
}
