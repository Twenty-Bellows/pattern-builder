<?php
/**
 * What PHP can say about template markup before it is stored.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checks a template or template part runs before it lands in the theme, on top of the
 * ones every pattern runs.
 *
 * A template is a theme file, read wherever the theme goes, so what it may carry is
 * narrower than a pattern: no PHP, since it is never run, and nothing that means something
 * on this site alone.
 */
class Pattern_Builder_Template_Checks {
	/**
	 * Blocks that carry an image, and the attributes the image is in.
	 */
	const MEDIA_ATTRIBUTES = array(
		'core/image'      => array( 'url', 'id' ),
		'core/cover'      => array( 'url', 'id' ),
		'core/media-text' => array( 'mediaUrl', 'mediaId' ),
	);

	/**
	 * Check template markup, refusing it with every problem named.
	 *
	 * @param string $type "template" or "template-part".
	 * @param string $slug The slug it is being stored under.
	 * @param string $markup Block markup.
	 * @return true|WP_Error `pb_markup_refused`, with every problem under `problems`.
	 */
	public static function check( $type, $slug, $markup ) {
		$problems = self::problems( $type, $slug, (string) $markup );

		if ( ! $problems ) {
			return true;
		}

		return new WP_Error(
			'pb_markup_refused',
			sprintf(
				/* translators: %s: the problems, each a sentence. */
				__( 'The markup was not stored: %s', 'pattern-builder' ),
				implode( ' ', $problems )
			),
			array(
				'status'   => 400,
				'problems' => $problems,
			)
		);
	}

	/**
	 * Every problem in some template markup, the pattern checks' first.
	 *
	 * @param string $type "template" or "template-part".
	 * @param string $slug The slug it is being stored under.
	 * @param string $markup Block markup.
	 * @return string[]
	 */
	public static function problems( $type, $slug, $markup ) {
		$problems = Pattern_Builder_Markup_Checks::problems( $markup );

		if ( false !== strpos( $markup, '<?' ) ) {
			$problems[] = __( 'The markup contains "<?". A template file is read as text and never run, so PHP in one is sent to the browser as it stands. Put dynamic output in a block, or in a pattern the template references.', 'pattern-builder' );
		}

		$problems = array_merge( $problems, self::part_references( parse_blocks( $markup ), $type, (string) $slug ) );

		return array_values( array_unique( $problems ) );
	}

	/**
	 * What is worth fixing in template markup that does not stop it being stored.
	 *
	 * @param string $markup Block markup.
	 * @return array[] Warnings, each with a `code` and a `message`.
	 */
	public static function warnings( $markup ) {
		$blocks   = parse_blocks( (string) $markup );
		$warnings = array_merge( self::database_ids( $blocks ), self::media( $blocks ) );

		return array_merge( $warnings, Pattern_Builder_Composition_Checks::warnings( $markup ) );
	}

	/**
	 * Every `core/template-part` reference that will not render a part of this theme.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $type "template" or "template-part".
	 * @param string $slug The slug the markup is stored under.
	 * @return string[]
	 */
	private static function part_references( $blocks, $type, $slug ) {
		$problems = array();
		$store    = new Pattern_Builder_Template_Store();

		foreach ( self::every_block( $blocks ) as $block ) {
			if ( 'core/template-part' !== $block['blockName'] ) {
				continue;
			}

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$part  = isset( $attrs['slug'] ) ? trim( (string) $attrs['slug'] ) : '';

			if ( '' === $part ) {
				$problems[] = __( 'A core/template-part block names no part: with no slug it renders as nothing at all.', 'pattern-builder' );
				continue;
			}

			if ( isset( $attrs['theme'] ) && get_stylesheet() !== $attrs['theme'] ) {
				$problems[] = sprintf(
					/* translators: 1: part slug, 2: the theme the reference names. */
					__( 'The reference to the %1$s part names another theme, %2$s, so it renders that theme\'s part only while %2$s is active and nothing otherwise. Leave theme off, and the reference reaches this theme\'s own part.', 'pattern-builder' ),
					$part,
					(string) $attrs['theme']
				);
				continue;
			}

			if ( 'template-part' === $type && $part === $slug ) {
				$problems[] = sprintf(
					/* translators: %s: part slug. */
					__( 'The %s part places itself, and core renders a part inside itself as an error message.', 'pattern-builder' ),
					$part
				);
				continue;
			}

			if ( null === $store->find( 'template-part', $part ) ) {
				$problems[] = sprintf(
					/* translators: %s: part slug. */
					__( 'The reference to the %s part resolves to nothing in this theme, and an unresolved part renders as nothing at all. Store the part first; list-templates with type "template-part" says what exists.', 'pattern-builder' ),
					$part
				);
			}
		}

		return $problems;
	}

	/**
	 * References that point at a row of this site's database by its ID, which means nothing in
	 * the theme anywhere else.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array[] Warnings.
	 */
	private static function database_ids( $blocks ) {
		$warnings = array();

		foreach ( self::every_block( $blocks ) as $block ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( empty( $attrs['ref'] ) ) {
				continue;
			}

			if ( 'core/navigation' === $block['blockName'] ) {
				$warnings[] = array(
					'code'    => 'template_database_id',
					'message' => sprintf(
						/* translators: %d: the navigation menu's post ID. */
						__( 'A core/navigation block points at menu %d by its database ID. That ID is this site\'s menu, and on any other site the theme goes to it is nothing or somebody else\'s menu. Leave ref off and the block shows the menu the site has, or write the links into the block.', 'pattern-builder' ),
						(int) $attrs['ref']
					),
				);
			} elseif ( 'core/block' === $block['blockName'] ) {
				$warnings[] = array(
					'code'    => 'template_database_id',
					'message' => sprintf(
						/* translators: %d: the synced pattern's post ID. */
						__( 'A core/block references synced pattern %d by its database ID, which no other site has. Store it as a theme pattern and reference it with core/pattern and its slug instead.', 'pattern-builder' ),
						(int) $attrs['ref']
					),
				);
			}
		}

		return $warnings;
	}

	/**
	 * Images placed directly in a template, which can only point at this site's media library
	 * or at an absolute URL, since a template file has no PHP to reach the theme's own assets.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array[] Warnings: one, naming the blocks, or none.
	 */
	private static function media( $blocks ) {
		$found = array();

		foreach ( self::every_block( $blocks ) as $block ) {
			$name = $block['blockName'];
			if ( ! isset( self::MEDIA_ATTRIBUTES[ $name ] ) ) {
				continue;
			}

			foreach ( self::MEDIA_ATTRIBUTES[ $name ] as $attribute ) {
				if ( ! empty( $block['attrs'][ $attribute ] ) ) {
					$found[ $name ] = true;
					break;
				}
			}
		}

		if ( ! $found ) {
			return array();
		}

		return array(
			array(
				'code'    => 'template_media',
				'message' => sprintf(
					/* translators: %s: block names, comma separated. */
					__( 'The template places images directly (%s). A template file is plain HTML, so an image in it is a URL or media library ID on this site, and it will not travel with the theme. Put the imagery in a pattern, which can point at the theme\'s own assets, and reference the pattern here.', 'pattern-builder' ),
					implode( ', ', array_keys( $found ) )
				),
			),
		);
	}

	/**
	 * Every named block in a tree, depth first.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array[]
	 */
	private static function every_block( $blocks ) {
		$found = array();

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$found[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::every_block( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}
}
