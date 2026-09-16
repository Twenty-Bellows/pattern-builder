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
 */
class Pattern_Builder_Block_Style_Variations {
	/**
	 * The theme subdirectory core reads partials from.
	 */
	const DIRECTORY = 'styles';

	/**
	 * Write a variation into the active theme.
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

		$css = self::check_css( $styles );
		if ( is_wp_error( $css ) ) {
			return $css;
		}
		$unknown = array();
		foreach ( $block_types as $name ) {
			if ( null === \WP_Block_Type_Registry::get_instance()->get_registered( $name ) ) {
				$unknown[] = $name;
			}
		}
		if ( $unknown ) {
			return new WP_Error(
				'pb_variation_unknown_block',
				sprintf(
					/* translators: %s: block names, comma separated. */
					__( 'No block named %s is registered on this site, so a style variation for it would style nothing. list-block-types says what is here.', 'pattern-builder' ),
					implode( ', ', $unknown )
				),
				array( 'status' => 400 )
			);
		}
		$clean   = Pattern_Builder_Theme_Styles::sanitize( $styles );
		$skipped = Pattern_Builder_Theme_Styles::missing_paths( $styles, $clean );
		$states  = self::states_in( $styles );
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

		$answer = array(
			'slug'       => $slug,
			'title'      => $title,
			'class'      => 'is-style-' . $slug,
			'blockTypes' => $block_types,
			'path'       => self::DIRECTORY . '/' . $slug . '.json',
			'written'    => Pattern_Builder_Theme_Styles::paths( $clean ),
			'skipped'    => $skipped,
		);

		if ( $states ) {
			$answer['note'] = sprintf(
				/* translators: 1: the state keys given, comma separated, 2: the variation slug, 3: the block names, comma separated. */
				__( 'A block state (%1$s) cannot live in a styles partial: WordPress reads the partial as a whole-theme styles tree, which has no states, so it was dropped. The state goes in theme.json under styles.blocks.{block}.variations.%2$s — call set-global-styles with { "blocks": { "%3$s": { "variations": { "%2$s": { ":hover": { … } } } } } } now that the variation exists. Only core/button and core/navigation-link take states, and a state set that way stays with this theme rather than travelling with a pattern.', 'pattern-builder' ),
				implode( ', ', $states ),
				$slug,
				implode( '", "', $block_types )
			);
		}

		return $answer;
	}

	/**
	 * The block-state keys at the top of a styles tree.
	 *
	 * @param array $styles A variation's `styles` subtree.
	 * @return string[]
	 */
	private static function states_in( $styles ) {
		$states = array();
		foreach ( array_keys( (array) $styles ) as $key ) {
			$key = (string) $key;
			if ( '' !== $key && ( ':' === $key[0] || '-' === $key[0] ) ) {
				$states[] = $key;
			}
		}
		return $states;
	}

	/**
	 * Install a variation that arrived with a pattern.
	 *
	 * @param array $variation A variation from a package.
	 * @return string|WP_Error 'written', 'skipped', or `pb_variation_css_refused` for a
	 * variation to leave out.
	 */
	public static function install( $variation ) {
		$slug = isset( $variation['slug'] ) ? sanitize_title( (string) $variation['slug'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'pb_variation_no_slug', __( 'A block style variation arrived without a slug.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$css = self::check_css( isset( $variation['styles'] ) && is_array( $variation['styles'] ) ? $variation['styles'] : array() );
		if ( is_wp_error( $css ) ) {
			return $css;
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
			$css = self::check_css( $definition['styles'] );
			if ( is_wp_error( $css ) ) {
				return new WP_Error(
					'pb_variation_css_cannot_travel',
					sprintf(
						/* translators: 1: variation slug, 2: what is wrong with the CSS. */
						__( 'The block style variation "%1$s" carries CSS a pattern cannot take with it: %2$s', 'pattern-builder' ),
						$slug,
						$css->get_error_message()
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
	 * Whether a variation's styles carry CSS this site will write.
	 *
	 * @param array $styles A variation's `styles` subtree.
	 * @return true|WP_Error
	 */
	private static function check_css( $styles ) {
		$deeper = array();
		foreach ( Pattern_Builder_Theme_Styles::find_css( $styles ) as $path ) {
			if ( 'css' !== $path ) {
				$deeper[] = $path;
			}
		}

		if ( $deeper ) {
			return new WP_Error(
				'pb_variation_nested_css',
				sprintf(
					/* translators: %s: dotted paths within the styles tree, comma separated. */
					__( 'A block style variation may carry CSS only at the top of its styles tree, and this one carries it at %s. Move the rules into the one "css" string, where a nested selector can say the same thing.', 'pattern-builder' ),
					implode( ', ', $deeper )
				),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $styles['css'] ) ) {
			return true;
		}

		$safe = Safe_Css::check( $styles['css'] );
		if ( is_wp_error( $safe ) ) {
			return new WP_Error(
				'pb_variation_css_refused',
				sprintf(
					/* translators: %s: the rule that was broken and the CSS that broke it. */
					__( 'This CSS is outside the subset a block style variation may carry — %s', 'pattern-builder' ),
					$safe->get_error_message()
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * A variation's definition, by slug.
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
	 * @param string $slug Variation slug.
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
