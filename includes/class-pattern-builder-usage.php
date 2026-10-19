<?php
/**
 * Where a pattern is used: the other half of the dependency walk.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

/**
 * Finds every place that places a pattern, directly or through another pattern.
 *
 * `Pattern_File_Store::references_of()` walks down, from a pattern to what it places. This
 * walks up, which is the question that matters before changing a synced pattern: a change to
 * it reaches every pattern, template, template part and post that places it, and every one
 * that places one of those.
 */
class Pattern_Builder_Usage {

	/**
	 * How many posts an answer names before it only counts the rest. Templates and patterns
	 * are listed in full, since a site has few of them; posts can be every page on it.
	 */
	const NAMED_POSTS = 10;

	/**
	 * How many candidate posts are read per query while confirming matches.
	 */
	const BATCH = 100;

	/**
	 * Post types whose content is covered elsewhere (patterns and templates are read through
	 * their own APIs) or never carries a pattern.
	 */
	const SKIPPED_POST_TYPES = array(
		'revision',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'customize_changeset',
		'oembed_cache',
		'user_request',
	);

	/**
	 * Post statuses that count as a use: anything that is or may become a page.
	 */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Every place that uses a pattern.
	 *
	 * @param Abstract_Pattern $pattern The pattern.
	 * @param int              $named_posts How many posts to name before only counting.
	 * @return array { places: array, total: int, hasMore: bool }
	 */
	public static function of( Abstract_Pattern $pattern, $named_posts = self::NAMED_POSTS ) {
		$target = self::token_of( $pattern );

		$carriers = self::carriers( $pattern, $target );
		$places   = $carriers['places'];
		$names    = $carriers['names'];

		$template_kinds = array(
			'wp_template'      => 'template',
			'wp_template_part' => 'template-part',
		);
		foreach ( $template_kinds as $type => $kind ) {
			foreach ( get_block_templates( array(), $type ) as $template ) {
				$found   = self::scan( (string) $template->content );
				$through = self::through( $found, $target, $names );
				if ( null === $through ) {
					continue;
				}
				$places[] = self::place(
					array(
						'kind'  => $kind,
						'id'    => (string) $template->id,
						'title' => (string) $template->title,
					),
					$found,
					$through,
					$target,
					$names
				);
			}
		}

		$posts = self::posts( $target, $names );
		$named = array_slice( $posts, 0, max( 0, (int) $named_posts ) );

		return array(
			'places'  => array_merge( $places, $named ),
			'total'   => count( $places ) + count( $posts ),
			'hasMore' => count( $posts ) > count( $named ),
		);
	}

	/**
	 * The patterns that carry the target at any depth, as places, plus the name of each
	 * carrier so an indirect place can say what it goes through.
	 *
	 * @param Abstract_Pattern $pattern The target.
	 * @param string           $target Its token.
	 * @return array { places: array, names: array token => name }
	 */
	private static function carriers( Abstract_Pattern $pattern, $target ) {
		$store = new Pattern_File_Store();
		$index = array();
		foreach ( array_merge( $store->get_theme_patterns(), $store->get_user_patterns(), $store->get_plugin_patterns() ) as $candidate ) {
			$token = self::token_of( $candidate );
			if ( $token !== $target && ! isset( $index[ $token ] ) ) {
				$index[ $token ] = $candidate;
			}
		}

		$names  = array( $target => self::name_of( $pattern ) );
		$places = array();
		$refs   = array();
		$queue  = array( $target );

		while ( $queue ) {
			$carrier = array_shift( $queue );

			foreach ( $index as $token => $candidate ) {
				if ( isset( $names[ $token ] ) ) {
					continue;
				}
				if ( ! isset( $refs[ $token ] ) ) {
					$refs[ $token ] = self::scan( (string) $candidate->content );
				}
				if ( ! isset( $refs[ $token ][ $carrier ] ) ) {
					continue;
				}

				$names[ $token ] = self::name_of( $candidate );
				$queue[]         = $token;
				$places[]        = self::place(
					array(
						'kind'   => 'pattern',
						'source' => (string) $candidate->source,
						'id'     => 'user' === $candidate->source ? (int) $candidate->id : (string) $candidate->name,
						'title'  => (string) $candidate->title,
					),
					$refs[ $token ],
					self::through( $refs[ $token ], $target, $names ),
					$target,
					$names
				);
			}
		}

		return array(
			'places' => $places,
			'names'  => $names,
		);
	}

	/**
	 * The posts that place the target or one of its carriers, readable by the current user, in
	 * ID order.
	 *
	 * A LIKE on each carrier's name finds the candidates and parsing confirms them, so a name
	 * that only appears in prose is not reported.
	 *
	 * @param string $target The target's token.
	 * @param array  $names Carrier token => name.
	 * @return array Places.
	 */
	private static function posts( $target, $names ) {
		global $wpdb;

		$likes = array();
		foreach ( array_keys( $names ) as $token ) {
			foreach ( self::needles( $token ) as $needle ) {
				$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
			}
		}

		$found   = array();
		$last_id = 0;
		$sql     = "SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts}
			WHERE ID > %d
			AND post_type NOT IN (" . implode( ', ', array_fill( 0, count( self::SKIPPED_POST_TYPES ), '%s' ) ) . ')
			AND post_status IN (' . implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) ) . ')
			AND (' . implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) ) . ')
			ORDER BY ID ASC
			LIMIT %d';

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The SQL is built from placeholders only, and every value goes through prepare(); a LIKE on post_content has no API equivalent.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $last_id ), self::SKIPPED_POST_TYPES, self::STATUSES, $likes, array( self::BATCH ) ) ) );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->ID;
				$scanned = self::scan( (string) $row->post_content );
				$through = self::through( $scanned, $target, $names );
				if ( null === $through || ! current_user_can( 'read_post', $last_id ) ) {
					continue;
				}
				$found[] = self::place(
					array(
						'kind'     => 'post',
						'id'       => $last_id,
						'title'    => (string) $row->post_title,
						'postType' => (string) $row->post_type,
						'status'   => (string) $row->post_status,
					),
					$scanned,
					$through,
					$target,
					$names
				);
			}

			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * What to look for in post_content to find a reference to a token: the serialized form
	 * WordPress writes, and the escaped slash hand-written markup sometimes has.
	 *
	 * @param string $token A token.
	 * @return string[]
	 */
	private static function needles( $token ) {
		list( $type, $value ) = explode( ':', $token, 2 );

		if ( 'ref' === $type ) {
			return array( '"ref":' . $value, '"ref": ' . $value );
		}

		return array_unique( array( $value, str_replace( '/', '\\/', $value ) ) );
	}

	/**
	 * A place, saying whether it uses the target directly or through another pattern, and
	 * which of the target's slots a direct one fills.
	 *
	 * @param array  $place The place's own fields.
	 * @param array  $found What its markup places: token => slot keys.
	 * @param string $through The token it reaches the target through.
	 * @param string $target The target's token.
	 * @param array  $names Carrier token => name.
	 * @return array
	 */
	private static function place( $place, $found, $through, $target, $names ) {
		$place['direct'] = $through === $target;
		if ( ! $place['direct'] ) {
			$place['via'] = $names[ $through ];
		} elseif ( ! empty( $found[ $target ] ) ) {
			$place['fills'] = $found[ $target ];
		}

		return $place;
	}

	/**
	 * Which carrier a piece of markup reaches the target through: the target itself when it
	 * places it directly, otherwise the first carrier it places, or null when it places none.
	 *
	 * @param array  $found What the markup places: token => slot keys.
	 * @param string $target The target's token.
	 * @param array  $names Carrier token => name.
	 * @return string|null
	 */
	private static function through( $found, $target, $names ) {
		if ( isset( $found[ $target ] ) ) {
			return $target;
		}

		foreach ( array_keys( $found ) as $reference ) {
			if ( isset( $names[ $reference ] ) ) {
				return $reference;
			}
		}

		return null;
	}

	/**
	 * Every pattern a piece of markup places, at any depth, as tokens: `slug:<name>` for a
	 * `core/pattern`, `ref:<id>` for a `core/block`.
	 *
	 * @param string $content Block markup.
	 * @return string[]
	 */
	public static function references_in( $content ) {
		return array_keys( self::scan( $content ) );
	}

	/**
	 * Every pattern a piece of markup places, each with the slot keys its references fill
	 * through `content` (the same attribute on a `core/pattern` and a `core/block`).
	 *
	 * @param string $content Block markup.
	 * @return array token => string[] slot keys.
	 */
	private static function scan( $content ) {
		if ( false === strpos( $content, 'wp:pattern' ) && false === strpos( $content, 'wp:block' ) ) {
			return array();
		}

		return self::scan_blocks( parse_blocks( $content ) );
	}

	/**
	 * Walk a parsed tree for references and the keys they fill.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array token => string[] slot keys.
	 */
	private static function scan_blocks( $blocks ) {
		$found = array();

		foreach ( (array) $blocks as $block ) {
			$name  = isset( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$token = '';

			if ( 'core/pattern' === $name && isset( $attrs['slug'] ) && '' !== trim( (string) $attrs['slug'] ) ) {
				$token = 'slug:' . trim( (string) $attrs['slug'] );
			} elseif ( 'core/block' === $name && ! empty( $attrs['ref'] ) ) {
				$token = 'ref:' . (int) $attrs['ref'];
			}

			if ( '' !== $token ) {
				$keys            = isset( $attrs['content'] ) && is_array( $attrs['content'] ) ? array_map( 'strval', array_keys( $attrs['content'] ) ) : array();
				$found[ $token ] = array_values( array_unique( array_merge( isset( $found[ $token ] ) ? $found[ $token ] : array(), $keys ) ) );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( self::scan_blocks( $block['innerBlocks'] ) as $inner => $keys ) {
					$found[ $inner ] = array_values( array_unique( array_merge( isset( $found[ $inner ] ) ? $found[ $inner ] : array(), $keys ) ) );
				}
			}
		}

		return $found;
	}

	/**
	 * How a pattern is referenced: by its post ID for a user pattern, by its name otherwise.
	 *
	 * @param Abstract_Pattern $pattern The pattern.
	 * @return string
	 */
	private static function token_of( Abstract_Pattern $pattern ) {
		return 'user' === $pattern->source ? 'ref:' . (int) $pattern->id : 'slug:' . $pattern->name;
	}

	/**
	 * A pattern's name as a person would recognise it in an answer.
	 *
	 * @param Abstract_Pattern $pattern The pattern.
	 * @return string
	 */
	private static function name_of( Abstract_Pattern $pattern ) {
		return 'user' === $pattern->source ? (string) $pattern->title : (string) $pattern->name;
	}
}
