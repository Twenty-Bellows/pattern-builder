<?php
/**
 * Block style variations — a named look a pattern applies with a class.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Block_Styles_Registry;
use WP_Error;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writing and reading the theme's `styles/*.json` partials.
 *
 * A design usually wants more than one kind of button, and the three ways to
 * get one are not equal. Setting the attributes on each button fossilises the
 * design into the markup. `styles.elements.button` is one rule for every
 * button on the site, so it cannot describe a second kind at all. A **block
 * style variation** is the third: a named look, applied by putting
 * `is-style-{slug}` in the markup, scoped to exactly the blocks that carry the
 * class.
 *
 * That scoping is also what makes it the only styling a pattern can honestly
 * bring with it — the selector needs a class the pattern's own markup carries,
 * so installing one changes nothing the pattern did not put there.
 *
 * Registration is a **file**, not a theme.json key. `styles.blocks.variations`
 * in theme.json only *styles* a variation something else registered — core
 * builds its valid list from the block style registry and `sanitize()` drops
 * any variation node that is not in it. What registers one without PHP is a
 * partial in the theme's `styles/` directory carrying a `blockTypes` key,
 * which `WP_Theme_JSON_Resolver::get_style_variations( 'block' )` reads and
 * `wp_register_block_style_variations_from_theme_json_partials()` registers.
 * One file both registers and styles, which is why this writes a file.
 */
class Pattern_Builder_Block_Style_Variations {

	/**
	 * The theme subdirectory core reads partials from.
	 */
	const DIRECTORY = 'styles';

	/**
	 * Write a variation into the active theme.
	 *
	 * Required: `slug` (the name the is-style- class is built from),
	 * `blockTypes` (the blocks it applies to) and `styles` (a theme.json
	 * `styles` subtree). Optional: `title`, the label shown in the editor, and
	 * `description`.
	 *
	 * @param array $args The variation to write.
	 * @return array|WP_Error slug, title, class, blockTypes, path, written and skipped.
	 */
	public static function add( $args ) {
		$slug = isset( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'pb_variation_no_slug', __( 'A block style variation needs a slug — it is what the is-style- class is built from.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$block_types = array();
		foreach ( (array) ( isset( $args['blockTypes'] ) ? $args['blockTypes'] : array() ) as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' !== $name ) {
				$block_types[] = $name;
			}
		}
		if ( ! $block_types ) {
			return new WP_Error( 'pb_variation_no_block_types', __( 'A block style variation needs at least one block type — WordPress skips a partial that names none.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$styles = isset( $args['styles'] ) && is_array( $args['styles'] ) ? $args['styles'] : array();
		if ( ! $styles ) {
			return new WP_Error( 'pb_variation_no_styles', __( 'A block style variation needs styles — WordPress skips a partial that carries none.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$css = Pattern_Builder_Theme_Styles::check_css( $styles );
		if ( is_wp_error( $css ) ) {
			return $css;
		}

		$clean   = Pattern_Builder_Theme_Styles::sanitize( $styles );
		$skipped = Pattern_Builder_Theme_Styles::missing_paths( $styles, $clean );
		if ( ! $clean ) {
			return new WP_Error(
				'pb_variation_no_valid_styles',
				sprintf(
					/* translators: %s: dotted paths within the styles tree, comma separated. */
					__( 'None of these are styles WordPress recognises (%s), so the variation would register with nothing to show.', 'pattern-builder' ),
					implode( ', ', $skipped )
				),
				array( 'status' => 400 )
			);
		}

		$path = self::path_for( $slug );

		/*
		 * A partial whose name is already taken registers nothing:
		 * `wp_register_block_style_variations_from_theme_json_partials()`
		 * checks the registry first and skips a name that is there. So a
		 * collision with somebody else's registration has to be refused —
		 * writing the file would look like success and change nothing. Our
		 * own partial is a different matter: that is the file this ability
		 * wrote, and rewriting it is how a design gets revised.
		 */
		if ( ! file_exists( $path ) ) {
			$taken = self::registered_for( $slug, $block_types );
			if ( $taken ) {
				return new WP_Error(
					'pb_variation_name_taken',
					sprintf(
						/* translators: 1: variation slug, 2: block names, comma separated. */
						__( 'A block style variation named "%1$s" is already registered for %2$s by something other than this theme\'s own styles directory. WordPress keeps the first registration, so a partial written now would be ignored. Choose another slug.', 'pattern-builder' ),
						$slug,
						implode( ', ', $taken )
					),
					array( 'status' => 409 )
				);
			}
		}

		$title = isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '';
		if ( '' === $title ) {
			$title = ucwords( str_replace( '-', ' ', $slug ) );
		}

		$partial = array(
			'$schema'    => 'https://schemas.wp.org/trunk/theme.json',
			'version'    => WP_Theme_JSON::LATEST_SCHEMA,
			'title'      => $title,
			'slug'       => $slug,
			'blockTypes' => $block_types,
			'styles'     => $clean,
		);
		if ( ! empty( $args['description'] ) ) {
			$partial['description'] = sanitize_text_field( (string) $args['description'] );
		}

		$written = Pattern_Builder_Security::safe_file_write(
			$path,
			wp_json_encode( $partial, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n",
			array( self::directory() )
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		wp_clean_theme_json_cache();

		return array(
			'slug'       => $slug,
			'title'      => $title,
			'class'      => 'is-style-' . $slug,
			'blockTypes' => $block_types,
			'path'       => self::DIRECTORY . '/' . $slug . '.json',
			'written'    => Pattern_Builder_Theme_Styles::paths( $clean ),
			'skipped'    => $skipped,
		);
	}

	/**
	 * Install a variation that arrived with a pattern.
	 *
	 * Never overwrites, which is the opposite of what `add()` does and right
	 * for the same reason a downloaded pattern's tokens never overwrite: a
	 * pattern arriving from somewhere else must not repaint what is already
	 * here. A name that is taken is left alone and reported, so installing the
	 * same collection twice is idempotent.
	 *
	 * @param array $variation A variation from a package.
	 * @return string|WP_Error 'written' or 'skipped'.
	 */
	public static function install( $variation ) {
		$slug = isset( $variation['slug'] ) ? sanitize_title( (string) $variation['slug'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'pb_variation_no_slug', __( 'A block style variation arrived without a slug.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		if ( file_exists( self::path_for( $slug ) ) || null !== self::definition( $slug ) ) {
			return 'skipped';
		}

		$block_types = isset( $variation['blockTypes'] ) ? (array) $variation['blockTypes'] : array();
		if ( self::registered_for( $slug, $block_types ) ) {
			return 'skipped';
		}

		$written = self::add( $variation );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return 'written';
	}

	/**
	 * The variation slugs a pattern's markup applies.
	 *
	 * Reads the class rather than any attribute, because that is the only
	 * place a variation appears: `is-style-{slug}` in the block comment's
	 * `className` and in the saved HTML, and nothing else in the file says
	 * which look was chosen.
	 *
	 * @param string $content Block markup.
	 * @return string[] Slugs, deduplicated.
	 */
	public static function used_in( $content ) {
		if ( ! preg_match_all( '/\bis-style-([a-z0-9-]+)/', (string) $content, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * The definitions a pattern's markup needs carrying with it.
	 *
	 * Only the ones this site defines. A variation declared in a block's own
	 * `block.json` — `is-style-outline` and the rest — ships with WordPress
	 * and resolves at the far end without help, so carrying it would be
	 * redundant at best and would collide with core's at worst.
	 *
	 * @param string $content Block markup.
	 * @return array|WP_Error Package-shaped variation list.
	 */
	public static function carried_by( $content ) {
		$carried = array();

		foreach ( self::used_in( $content ) as $slug ) {
			$definition = self::definition( $slug );
			if ( null === $definition || empty( $definition['styles'] ) ) {
				continue;
			}

			$css = Pattern_Builder_Theme_Styles::check_css( $definition['styles'] );
			if ( is_wp_error( $css ) ) {
				return new WP_Error(
					'pb_variation_css_cannot_travel',
					sprintf(
						/* translators: %s: variation slug. */
						__( 'The block style variation "%s" carries raw CSS, which cannot travel with a pattern — the service will not store unsanitised CSS. Express it with the styles properties instead.', 'pattern-builder' ),
						$slug
					),
					array( 'status' => 400 )
				);
			}

			$carried[] = array(
				'slug'       => $slug,
				'title'      => isset( $definition['title'] ) ? (string) $definition['title'] : $slug,
				'blockTypes' => array_values( (array) $definition['blockTypes'] ),
				'styles'     => $definition['styles'],
			);
		}

		return $carried;
	}

	/**
	 * A variation's definition, by slug.
	 *
	 * Reads the same partials core registers from, so what comes back is what
	 * is actually in effect — including the parent theme's, which a child
	 * inherits. Used when collecting what a pattern depends on: the markup
	 * carries the class and the definition lives here.
	 *
	 * @param string $slug Variation slug.
	 * @return array|null The partial, or null.
	 */
	public static function definition( $slug ) {
		foreach ( WP_Theme_JSON_Resolver::get_style_variations( 'block' ) as $variation ) {
			if ( self::slug_of( $variation ) === $slug ) {
				return $variation;
			}
		}

		return null;
	}

	/**
	 * Every block style variation this theme defines as a partial.
	 *
	 * @return array Slug => partial.
	 */
	public static function all() {
		$all = array();

		foreach ( WP_Theme_JSON_Resolver::get_style_variations( 'block' ) as $variation ) {
			$slug = self::slug_of( $variation );
			if ( '' !== $slug ) {
				$all[ $slug ] = $variation;
			}
		}

		return $all;
	}

	/**
	 * The slug a partial registers under.
	 *
	 * Core falls back to a kebab-cased title when a partial names no slug, so
	 * reading one has to fall back the same way or the name would not match
	 * the class in the markup.
	 *
	 * @param array $variation A partial.
	 * @return string
	 */
	private static function slug_of( $variation ) {
		if ( ! empty( $variation['slug'] ) ) {
			return (string) $variation['slug'];
		}

		return ! empty( $variation['title'] ) ? _wp_to_kebab_case( (string) $variation['title'] ) : '';
	}

	/**
	 * Which of these blocks already have a variation under this name.
	 *
	 * @param string $slug        Variation slug.
	 * @param array  $block_types Block names.
	 * @return string[] The block names that are taken.
	 */
	private static function registered_for( $slug, $block_types ) {
		$registry = WP_Block_Styles_Registry::get_instance();
		$taken    = array();

		foreach ( $block_types as $block_type ) {
			if ( $registry->is_registered( $block_type, $slug ) ) {
				$taken[] = $block_type;
			}
		}

		return $taken;
	}

	/**
	 * Where a variation's partial lives.
	 *
	 * @param string $slug Variation slug.
	 * @return string
	 */
	private static function path_for( $slug ) {
		return self::directory() . '/' . $slug . '.json';
	}

	/**
	 * The active theme's partials directory.
	 *
	 * @return string
	 */
	public static function directory() {
		return get_stylesheet_directory() . '/' . self::DIRECTORY;
	}
}
