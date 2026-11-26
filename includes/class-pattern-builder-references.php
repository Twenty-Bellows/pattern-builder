<?php
/**
 * Moving the references to a pattern when the pattern changes storage.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;

/**
 * Finds every place that names a pattern and points it at the pattern's new address.
 *
 * A theme pattern is placed with `core/pattern` and its slug, a user pattern with
 * `core/block` and its post ID. Converting one into the other changes which of the two can
 * reach it, so everything that placed it has to follow or it renders nothing.
 *
 * A pattern's address is a token, as in `Pattern_Builder_Usage`: `slug:<name>` or `ref:<id>`.
 */
class Pattern_Builder_References {

	/**
	 * How many candidate posts are read per query.
	 */
	const BATCH = 100;

	/**
	 * A void `core/pattern` or `core/block` comment with attributes. A reference is always
	 * void; one written with a closing comment is left alone rather than half rewritten.
	 */
	const REFERENCE = '/<!--\s+wp:(?:core\/)?(pattern|block)\s+(\{.*?\})\s+\/-->/s';

	/**
	 * How a pattern is referenced: by its post ID for a user pattern, by its name otherwise.
	 *
	 * @param Abstract_Pattern $pattern The pattern.
	 * @return string
	 */
	public static function token_of( Abstract_Pattern $pattern ) {
		return 'user' === $pattern->source ? 'ref:' . (int) $pattern->id : 'slug:' . $pattern->name;
	}

	/**
	 * Point every reference to one pattern in a piece of markup at another address.
	 *
	 * The markup is treated as text and only the matching comments change, so PHP in a
	 * pattern file and markup the block parser would not round-trip both survive untouched.
	 * Every other attribute, the slots a place fills among them, moves across as it is.
	 *
	 * @param string $content Block markup, or a pattern file's source.
	 * @param string $from The address to look for.
	 * @param string $to The address to write in its place.
	 * @return string
	 */
	public static function repoint( $content, $from, $to ) {
		if ( ! is_string( $content ) || ! self::might_reference( $content, $from ) ) {
			return $content;
		}

		list( $to_type, $to_value ) = explode( ':', $to, 2 );

		$rewritten = preg_replace_callback(
			self::REFERENCE,
			static function ( $matches ) use ( $from, $to_type, $to_value ) {
				// As objects, so an empty `content` stays `{}` rather than coming back as `[]`.
				$attributes = json_decode( $matches[2] );
				if ( ! $attributes instanceof \stdClass || self::address( $matches[1], $attributes ) !== $from ) {
					return $matches[0];
				}

				$moved = new \stdClass();
				if ( 'ref' === $to_type ) {
					$moved->ref = (int) $to_value;
				} else {
					$moved->slug = $to_value;
				}
				foreach ( get_object_vars( $attributes ) as $key => $value ) {
					if ( 'ref' !== $key && 'slug' !== $key ) {
						$moved->$key = $value;
					}
				}

				return '<!-- wp:' . ( 'ref' === $to_type ? 'block' : 'pattern' ) . ' ' . serialize_block_attributes( $moved ) . ' /-->';
			},
			$content
		);

		return is_string( $rewritten ) ? $rewritten : $content;
	}

	/**
	 * Whether a piece of markup references a pattern.
	 *
	 * @param string $content Block markup, or a pattern file's source.
	 * @param string $token The pattern's address.
	 * @return bool
	 */
	public static function has( $content, $token ) {
		if ( ! is_string( $content ) || ! self::might_reference( $content, $token ) ) {
			return false;
		}

		if ( ! preg_match_all( self::REFERENCE, $content, $matches, PREG_SET_ORDER ) ) {
			return false;
		}

		foreach ( $matches as $match ) {
			$attributes = json_decode( $match[2] );
			if ( $attributes instanceof \stdClass && self::address( $match[1], $attributes ) === $token ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every place that references a pattern directly.
	 *
	 * @param string $token The pattern's address.
	 * @return array {
	 *     files:   array[] Theme pattern, template and template part files: kind, file, path.
	 *     plugins: array[] Patterns a plugin registered: name, title.
	 *     posts:   array[] Rows of any post type but revisions: id, title, postType, status.
	 * }
	 */
	public static function places( $token ) {
		return array(
			'files'   => self::files( $token ),
			'plugins' => self::plugin_patterns( $token ),
			'posts'   => self::posts( $token ),
		);
	}

	/**
	 * Point every reference to a pattern, in the database and in the theme's files, at
	 * another address.
	 *
	 * @param string $from The address the pattern had.
	 * @param string $to The address it has now.
	 * @return array|WP_Error { posts: array[], files: array[] } as places() describes them,
	 * holding only what was rewritten; or the first file that could not be written, in which
	 * case no row has been touched.
	 */
	public static function repoint_everywhere( $from, $to ) {
		$files = array();

		// Files first: a write can fail where an update of a row will not, and failing
		// before any row has moved leaves the references agreeing with one another.
		foreach ( self::files( $from ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local theme file, read as text.
			$source  = (string) file_get_contents( $file['path'] );
			$written = Pattern_Builder_Security::safe_file_write( $file['path'], self::repoint( $source, $from, $to ), self::theme_folders() );
			if ( is_wp_error( $written ) ) {
				return $written;
			}

			clearstatcache( true, $file['path'] );
			wp_opcache_invalidate( $file['path'], true );
			$files[] = $file;
		}

		$posts = array();

		// The rewrite is the plugin's, not the current user's: with kses on, a user without
		// unfiltered_html (every administrator on multisite) would have the rest of each
		// post's markup filtered as the price of moving one comment.
		kses_remove_filters();
		try {
			foreach ( self::posts( $from ) as $post ) {
				$updated = wp_update_post(
					// wp_update_post() takes slashed data, and block attributes are full of backslashes.
					wp_slash(
						array(
							'ID'           => $post['id'],
							'post_content' => self::repoint( (string) get_post_field( 'post_content', $post['id'], 'raw' ), $from, $to ),
						)
					),
					true
				);
				if ( ! is_wp_error( $updated ) ) {
					$posts[] = $post;
				}
			}
		} finally {
			kses_init();
		}

		return array(
			'posts' => $posts,
			'files' => $files,
		);
	}

	/**
	 * The address a reference comment names, or '' when it names none.
	 *
	 * @param string    $block "pattern" or "block", from the comment.
	 * @param \stdClass $attributes Its decoded attributes.
	 * @return string
	 */
	private static function address( $block, $attributes ) {
		if ( 'pattern' === $block && isset( $attributes->slug ) && is_string( $attributes->slug ) ) {
			return 'slug:' . trim( $attributes->slug );
		}

		if ( 'block' === $block && isset( $attributes->ref ) && is_scalar( $attributes->ref ) ) {
			return 'ref:' . (int) $attributes->ref;
		}

		return '';
	}

	/**
	 * Cheap test for markup worth running the pattern over.
	 *
	 * @param string $content Markup.
	 * @param string $token An address.
	 * @return bool
	 */
	private static function might_reference( $content, $token ) {
		$block = 0 === strpos( $token, 'ref:' ) ? 'block' : 'pattern';

		return false !== strpos( $content, 'wp:' . $block ) || false !== strpos( $content, 'wp:core/' . $block );
	}

	/**
	 * The theme files that reference a pattern: pattern files, templates and template parts
	 * of the active theme and its parent.
	 *
	 * @param string $token The pattern's address.
	 * @return array[] kind, file (relative to its theme), path.
	 */
	private static function files( $token ) {
		$found = array();

		foreach ( self::theme_files() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local theme file, read as text.
			if ( self::has( (string) file_get_contents( $file['path'] ), $token ) ) {
				$found[] = $file;
			}
		}

		return $found;
	}

	/**
	 * Every pattern, template and template part file of the active theme and its parent.
	 *
	 * @return array[] kind, file (relative to its theme), path.
	 */
	private static function theme_files() {
		$themes = array( get_stylesheet() => get_stylesheet_directory() );
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$themes[ get_template() ] = get_template_directory();
		}

		$files = array();
		foreach ( $themes as $theme => $directory ) {
			$template_folders = get_block_theme_folders( $theme );
			$folders          = array(
				'patterns'                            => array( 'pattern', 'php' ),
				$template_folders['wp_template']      => array( 'template', 'html' ),
				$template_folders['wp_template_part'] => array( 'template-part', 'html' ),
			);

			foreach ( $folders as $folder => $kind ) {
				if ( ! is_dir( $directory . '/' . $folder ) ) {
					continue;
				}

				$entries = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory . '/' . $folder, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $entries as $entry ) {
					if ( $entry->isFile() && strtolower( $entry->getExtension() ) === $kind[1] ) {
						$files[] = array(
							'kind' => $kind[0],
							'file' => substr( wp_normalize_path( $entry->getPathname() ), strlen( wp_normalize_path( $directory ) ) + 1 ),
							'path' => $entry->getPathname(),
						);
					}
				}
			}
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return $files;
	}

	/**
	 * The folders a rewrite may write into.
	 *
	 * @return string[]
	 */
	private static function theme_folders() {
		return array_values( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) );
	}

	/**
	 * The patterns a plugin registered that reference a pattern. Only a slug can be
	 * referenced from one worth reporting: these are read-only, so they are the places a
	 * rewrite cannot reach.
	 *
	 * @param string $token The pattern's address.
	 * @return array[] name, title.
	 */
	private static function plugin_patterns( $token ) {
		$found = array();
		$store = new Pattern_File_Store();

		foreach ( $store->get_plugin_patterns() as $pattern ) {
			if ( self::has( (string) $pattern->content, $token ) ) {
				$found[] = array(
					'name'  => (string) $pattern->name,
					'title' => (string) $pattern->title,
				);
			}
		}

		return $found;
	}

	/**
	 * The rows that reference a pattern, in ID order: posts and pages of every type and
	 * status, user patterns, and Site Editor customizations. Revisions are history and stay
	 * as they were written.
	 *
	 * @param string $token The pattern's address.
	 * @return array[] id, title, postType, status.
	 */
	private static function posts( $token ) {
		global $wpdb;

		list( $type, $value ) = explode( ':', $token, 2 );

		// Candidates only: has() confirms each one, so a slug that appears in prose is not a match.
		$needles = 'ref' === $type
			? array( '"ref":' . $value, '"ref": ' . $value, '"ref":"' . $value . '"' )
			: array_unique( array( $value, str_replace( '/', '\\/', $value ) ) );
		$likes   = array();
		foreach ( $needles as $needle ) {
			$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		$found   = array();
		$last_id = 0;
		$sql     = "SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts}
			WHERE ID > %d
			AND post_type <> 'revision'
			AND (" . implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) ) . ')
			ORDER BY ID ASC
			LIMIT %d';

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The SQL is built from placeholders only, and every value goes through prepare(); a LIKE on post_content has no API equivalent.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $last_id ), $likes, array( self::BATCH ) ) ) );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->ID;
				if ( self::has( (string) $row->post_content, $token ) ) {
					$found[] = array(
						'id'       => $last_id,
						'title'    => (string) $row->post_title,
						'postType' => (string) $row->post_type,
						'status'   => (string) $row->post_status,
					);
				}
			}

			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}

		return $found;
	}
}
