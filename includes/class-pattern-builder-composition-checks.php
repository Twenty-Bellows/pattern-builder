<?php

namespace TwentyBellows\PatternBuilder;

/**
 * Whether markup lays out as written: the relationship between blocks that no check of one
 * block can see. A flex or grid container whose children cannot size renders as a vertical
 * stack, and that markup is entirely valid, so nothing else reports it.
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
	 * Check one block's children, then each child's.
	 *
	 * @param array $block    A parsed block.
	 * @param array $warnings Warnings so far.
	 * @param array $roots    Referenced patterns' root blocks, by slug, as resolved so far.
	 */
	private static function visit( $block, &$warnings, &$roots ) {
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
