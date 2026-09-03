<?php

namespace TwentyBellows\PatternBuilder;

use WP_Error;

/**
 * Design tokens: the preset references a pattern carries between sites.
 *
 * On upload, collect() scans the pattern's markup for every theme.json
 * preset it references (colors, gradients, spacing sizes, font sizes, font
 * families) and resolves each slug against this site's merged global
 * settings — the pattern travels with the values it actually had here.
 *
 * On download, missing() reports which referenced tokens the destination
 * site doesn't define (the destination's own definitions always win), and
 * apply() writes those into the chosen home: the theme's user Global
 * Styles post, or the active theme's theme.json file.
 */
class Pattern_Builder_Cloud_Tokens {

	/**
	 * Token types, with the settings path their presets live under and the
	 * key holding the value in a preset entry.
	 *
	 * @return array type => { path: string[], valueKey: string }
	 */
	public static function types() {
		return array(
			'color'      => array(
				'path'      => array( 'color', 'palette' ),
				'value_key' => 'color',
			),
			'gradient'   => array(
				'path'      => array( 'color', 'gradients' ),
				'value_key' => 'gradient',
			),
			'spacing'    => array(
				'path'      => array( 'spacing', 'spacingSizes' ),
				'value_key' => 'size',
			),
			'fontSize'   => array(
				'path'      => array( 'typography', 'fontSizes' ),
				'value_key' => 'size',
			),
			'fontFamily' => array(
				'path'      => array( 'typography', 'fontFamilies' ),
				'value_key' => 'fontFamily',
			),
		);
	}

	/**
	 * Collect a pattern's tokens and everything its references need.
	 *
	 * A page pattern is mostly `core/pattern` references, and almost none of the
	 * presets it depends on appear in its own markup — they are in the sections
	 * it composes. Collecting only the top level under-reports what the page
	 * uses and, worse, renders it against another theme carrying a fraction of
	 * what it needs. An upload does not have this problem, because it sends the
	 * whole tree and each member brings its own; anything that looks at one
	 * composed pattern does.
	 *
	 * @param string $content The pattern's markup.
	 * @param array  $seen    Slugs already walked, against a reference loop.
	 * @return array Tokens, one entry per type and slug.
	 */
	public static function collect_tree( $content, $seen = array() ) {
		$tokens = self::collect( $content );

		/*
		 * A block style variation is the other place a preset reference hides.
		 * The markup carries `is-style-{slug}` and no colour at all; the
		 * `var:preset|color|accent` the variation resolves to lives in its
		 * definition. Collecting only the markup ships the variation with a
		 * token it references and nothing defining it at the far end.
		 */
		foreach ( Pattern_Builder_Block_Style_Variations::used_in( $content ) as $variation_slug ) {
			$definition = Pattern_Builder_Block_Style_Variations::definition( $variation_slug );
			if ( null === $definition || empty( $definition['styles'] ) ) {
				continue;
			}
			$tokens = array_merge( $tokens, self::collect( (string) wp_json_encode( $definition['styles'] ) ) );
		}

		if ( preg_match_all( '/<!--\s*wp:pattern\s+(\{.*?\})\s*\/-->/s', (string) $content, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$attrs = json_decode( $json, true );

				if ( ! is_array( $attrs ) || empty( $attrs['slug'] ) ) {
					continue;
				}

				$slug = (string) $attrs['slug'];

				if ( isset( $seen[ $slug ] ) ) {
					continue;
				}

				$seen[ $slug ] = true;

				$registered = \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );

				if ( ! $registered || empty( $registered['content'] ) ) {
					continue;
				}

				$tokens = array_merge( $tokens, self::collect_tree( $registered['content'], $seen ) );
			}
		}

		$unique = array();

		foreach ( $tokens as $token ) {
			$unique[ $token['type'] . '|' . $token['slug'] ] = $token;
		}

		return array_values( $unique );
	}

	/**
	 * Collect the tokens a pattern's markup references, resolved to this
	 * site's current values. References this site can't resolve are
	 * dropped (there is no default to carry).
	 *
	 * @param string $content Serialized block markup.
	 * @return array PBP token list.
	 */
	public static function collect( $content ) {
		$tokens = array();

		foreach ( self::referenced( $content ) as $type => $slugs ) {
			foreach ( $slugs as $slug ) {
				$preset = self::find_preset( $type, $slug );
				if ( ! $preset ) {
					continue;
				}

				$value = self::preset_value( $type, $preset );
				if ( '' === $value ) {
					continue;
				}

				$tokens[] = array(
					'type'  => $type,
					'slug'  => $slug,
					'name'  => isset( $preset['name'] ) ? (string) $preset['name'] : ucwords( str_replace( '-', ' ', $slug ) ),
					'value' => $value,
				);
			}
		}

		return $tokens;
	}

	/**
	 * Every preset reference in a pattern's markup, by token type.
	 *
	 * Sources: named block attributes, `var:preset|…` style paths,
	 * `var(--wp--preset--…)` custom properties, and the derived
	 * `has-…` classes.
	 *
	 * @param string $content Serialized block markup.
	 * @return array type => slug[]
	 */
	public static function referenced( $content ) {
		$found = array();
		$note  = function ( $type, $slug ) use ( &$found ) {
			$slug = strtolower( (string) $slug );
			// `custom` is core's word for "not a preset": a block carrying an
			// explicit value renders `has-custom-font-size` beside whatever
			// preset class it also has, so reading it as a slug reports a
			// token nothing defines and nothing ever will.
			if ( 'custom' === $slug ) {
				return;
			}
			if ( preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) ) {
				$found[ $type ][ $slug ] = true;
			}
		};

		// Named attributes inside block comment JSON.
		$attr_types = array(
			'textColor'       => 'color',
			'backgroundColor' => 'color',
			'borderColor'     => 'color',
			'gradient'        => 'gradient',
			'fontSize'        => 'fontSize',
			'fontFamily'      => 'fontFamily',
		);
		foreach ( $attr_types as $attr => $type ) {
			if ( preg_match_all( '/"' . $attr . '"\s*:\s*"([a-z0-9-]+)"/i', $content, $matches ) ) {
				foreach ( $matches[1] as $slug ) {
					$note( $type, $slug );
				}
			}
		}

		// Style-object preset paths and rendered custom properties. The
		// wire spelling of each type is kebab-case.
		$path_types = array(
			'color'       => 'color',
			'gradient'    => 'gradient',
			'spacing'     => 'spacing',
			'font-size'   => 'fontSize',
			'font-family' => 'fontFamily',
		);
		if ( preg_match_all( '/var:preset\|([a-z-]+)\|([a-z0-9-]+)/i', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( isset( $path_types[ strtolower( $match[1] ) ] ) ) {
					$note( $path_types[ strtolower( $match[1] ) ], $match[2] );
				}
			}
		}
		if ( preg_match_all( '/var\(--wp--preset--([a-z-]+?)--([a-z0-9-]+)\)/i', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( isset( $path_types[ strtolower( $match[1] ) ] ) ) {
					$note( $path_types[ strtolower( $match[1] ) ], $match[2] );
				}
			}
		}

		// Derived classes; generic support classes (has-text-color et al.) are
		// not preset references, so their pseudo-slugs are excluded.
		$class_types = array(
			'/\bhas-([a-z0-9-]+)-color\b/'               => array( 'color', array( 'text', 'link', 'border', 'icon', 'heading', 'caption', 'button' ) ),
			'/\bhas-([a-z0-9-]+)-background-color\b/'    => array( 'color', array() ),
			'/\bhas-([a-z0-9-]+)-border-color\b/'        => array( 'color', array() ),
			'/\bhas-([a-z0-9-]+)-gradient-background\b/' => array( 'gradient', array() ),
			'/\bhas-([a-z0-9-]+)-font-size\b/'           => array( 'fontSize', array() ),
			'/\bhas-([a-z0-9-]+)-font-family\b/'         => array( 'fontFamily', array() ),
		);
		foreach ( $class_types as $regex => $spec ) {
			if ( preg_match_all( $regex, $content, $matches ) ) {
				foreach ( $matches[1] as $slug ) {
					// The specific -background-color / -border-color regexes
					// already captured these with the right slug.
					if ( preg_match( '/-(background|border)$/', $slug ) ) {
						continue;
					}
					if ( ! in_array( $slug, $spec[1], true ) ) {
						$note( $spec[0], $slug );
					}
				}
			}
		}

		return array_map( 'array_keys', $found );
	}

	/**
	 * The subset of a package's tokens this site does not define.
	 *
	 * @param array $tokens PBP token list.
	 * @return array
	 */
	public static function missing( $tokens ) {
		$missing = array();
		foreach ( (array) $tokens as $token ) {
			if ( ! is_array( $token ) || empty( $token['type'] ) || empty( $token['slug'] ) ) {
				continue;
			}
			if ( ! array_key_exists( $token['type'], self::types() ) ) {
				continue;
			}
			if ( ! self::find_preset( $token['type'], $token['slug'] ) ) {
				$missing[] = $token;
			}
		}
		return $missing;
	}

	/**
	 * Write tokens into the chosen home. Tokens the site already defines
	 * are skipped; values are re-validated locally (never trust the wire).
	 *
	 * @param array  $tokens      PBP token list (normally missing() output).
	 * @param string $destination 'user' (Global Styles) or 'theme' (theme.json).
	 * @return array|WP_Error Slugs written, keyed by type.
	 */
	public static function apply( $tokens, $destination ) {
		$to_write = array();
		foreach ( self::missing( $tokens ) as $token ) {
			$value = self::sanitize_value( $token['type'], isset( $token['value'] ) ? (string) $token['value'] : '' );
			if ( false === $value ) {
				return new WP_Error(
					'pb_cloud_bad_token',
					sprintf(
						/* translators: 1: token slug, 2: token type such as color or spacing. */
						__( 'The value given for the "%1$s" %2$s token is not one this site will store.', 'pattern-builder' ),
						$token['slug'],
						$token['type']
					),
					array( 'status' => 400 )
				);
			}
			$token['value']               = $value;
			$to_write[ $token['type'] ][] = $token;
		}

		if ( ! $to_write ) {
			return array();
		}

		$result = Pattern_Builder_Theme_Json::edit(
			$destination,
			function ( $config ) use ( $to_write ) {
				return self::merge_settings( $config, $to_write );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$written = array();
		foreach ( $to_write as $type => $list ) {
			$written[ $type ] = wp_list_pluck( $list, 'slug' );
		}
		return $written;
	}

	/**
	 * Append token presets under a config's settings paths (skipping slugs
	 * that already exist at that path).
	 *
	 * @param array $config   theme.json-shaped config.
	 * @param array $to_write type => token[].
	 * @return array
	 */
	private static function merge_settings( $config, $to_write ) {
		$types = self::types();

		foreach ( $to_write as $type => $list ) {
			$path      = $types[ $type ]['path'];
			$value_key = $types[ $type ]['value_key'];

			$existing = isset( $config['settings'][ $path[0] ][ $path[1] ] ) && is_array( $config['settings'][ $path[0] ][ $path[1] ] )
				? $config['settings'][ $path[0] ][ $path[1] ]
				: array();
			$slugs    = array();
			foreach ( $existing as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
					$slugs[ strtolower( (string) $entry['slug'] ) ] = true;
				}
			}

			foreach ( $list as $token ) {
				if ( isset( $slugs[ $token['slug'] ] ) ) {
					continue;
				}
				$preset = array(
					'slug'     => $token['slug'],
					'name'     => $token['name'],
					$value_key => $token['value'],
				);

				/*
				 * A preset occasionally carries more than its value. The one
				 * case is a `fontFamily` naming a self-hosted font, which
				 * needs the `fontFace` descriptors beside the stack or the
				 * browser has nothing to load — see
				 * `Pattern_Builder_Fonts`. Kept as a merge rather than a
				 * second write path so there is still one implementation of
				 * "put a preset in theme.json or in Global Styles".
				 */
				if ( isset( $token['extra'] ) && is_array( $token['extra'] ) ) {
					$preset = array_merge( $preset, $token['extra'] );
				}

				$existing[] = $preset;
			}

			$config['settings'][ $path[0] ][ $path[1] ] = $existing;
		}

		return $config;
	}

	/**
	 * Find a preset by slug in this site's merged settings, preferring
	 * user customizations over the theme over core defaults.
	 *
	 * @param string $type Token type.
	 * @param string $slug Preset slug.
	 * @return array|null Preset entry.
	 */
	private static function find_preset( $type, $slug ) {
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}

		$presets = wp_get_global_settings( $types[ $type ]['path'] );
		if ( ! is_array( $presets ) ) {
			return null;
		}

		// Origin-keyed (default/theme/custom) or, defensively, a flat list.
		$origins = array();
		foreach ( array( 'custom', 'theme', 'default' ) as $origin ) {
			if ( isset( $presets[ $origin ] ) && is_array( $presets[ $origin ] ) ) {
				$origins[] = $presets[ $origin ];
			}
		}
		if ( ! $origins && array_values( $presets ) === $presets ) {
			$origins[] = $presets;
		}

		foreach ( $origins as $list ) {
			foreach ( $list as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) && strtolower( (string) $entry['slug'] ) === strtolower( (string) $slug ) ) {
					return $entry;
				}
			}
		}
		return null;
	}

	/**
	 * The CSS value a preset entry resolves to.
	 *
	 * @param string $type   Token type.
	 * @param array  $preset Preset entry.
	 * @return string
	 */
	private static function preset_value( $type, $preset ) {
		$value_key = self::types()[ $type ]['value_key'];

		if ( 'fontSize' === $type && function_exists( 'wp_get_typography_font_size_value' ) ) {
			// Applies fluid typography, matching what the site renders.
			$value = wp_get_typography_font_size_value( $preset );
			return is_string( $value ) ? $value : '';
		}

		return isset( $preset[ $value_key ] ) && is_string( $preset[ $value_key ] ) ? $preset[ $value_key ] : '';
	}

	/**
	 * The same strict per-type value grammar the service enforces —
	 * re-run locally before anything is written (never trust the wire).
	 *
	 * @param string $type  Token type.
	 * @param string $value Raw value.
	 * @return string|false
	 */
	public static function sanitize_value( $type, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 400 ) {
			return false;
		}

		$lower = strtolower( $value );
		foreach ( array( 'url(', 'expression(', 'var(', 'image-set(', '@', ';', '{', '}', '<', '>', '\\' ) as $forbidden ) {
			if ( false !== strpos( $lower, $forbidden ) ) {
				return false;
			}
		}

		switch ( $type ) {
			case 'color':
				if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
					return $lower;
				}
				if ( in_array( $lower, array( 'transparent', 'currentcolor' ), true ) ) {
					return $lower;
				}
				if ( preg_match( '/^(rgba?|hsla?)\(\s*[0-9\.\,\s%\/deg]+\)$/i', $value ) ) {
					return preg_replace( '/\s+/', ' ', $lower );
				}
				return false;

			case 'gradient':
				if ( ! preg_match( '/^(linear|radial|conic)-gradient\(/i', $value ) ) {
					return false;
				}
				if ( ! preg_match( '/^[a-z0-9#\s\.\,\%\(\)\/\-\+\*]+$/i', $value ) ) {
					return false;
				}
				if ( substr_count( $value, '(' ) !== substr_count( $value, ')' ) ) {
					return false;
				}
				return $value;

			case 'spacing':
			case 'fontSize':
				if ( ! preg_match( '/^[a-z0-9\s\.\,\%\(\)\/\-\+\*]+$/i', $value ) ) {
					return false;
				}
				if ( substr_count( $value, '(' ) !== substr_count( $value, ')' ) ) {
					return false;
				}
				$residue = preg_replace( '/\b(clamp|calc|min|max)\(/i', '(', $value );
				$residue = preg_replace( '/(?<=[0-9\s])(px|rem|em|%|vw|vh|svw|svh|dvw|dvh|ch|ex|pt)\b/i', '', $residue );
				if ( preg_match( '/[a-z]/i', $residue ) ) {
					return false;
				}
				return $value;

			case 'fontFamily':
				if ( ! preg_match( '/^[a-z0-9\s\,\'\"\-]+$/i', $value ) ) {
					return false;
				}
				return $value;
		}

		return false;
	}
}
