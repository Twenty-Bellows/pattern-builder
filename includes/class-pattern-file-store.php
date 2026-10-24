<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- camelCase properties intentionally mirror the JS AbstractPattern class.

namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_Query;

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-builder-localization.php';
require_once __DIR__ . '/class-pattern-builder-security.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

/**
 * Reads and writes block patterns.
 */
class Pattern_File_Store {
	/**
	 * Post meta holding a user pattern's attribution — the same thing the `Origin:` header
	 * holds for a theme pattern (D38).
	 */
	const META_ORIGIN = 'pattern_builder_origin';

	/**
	 * Post meta holding the name of a user pattern's copy on the cloud — the same thing the
	 * `Cloud:` header holds for a theme pattern.
	 */
	const META_CLOUD = 'pattern_builder_cloud';

	/**
	 * Returns all patterns found as PHP files in the active theme's and the parent theme's
	 * `patterns/` directories.
	 *
	 * @return Abstract_Pattern[]
	 */
	public function get_theme_patterns() {
		$patterns = array();
		$seen     = array();

		foreach ( $this->get_pattern_directories() as $directory ) {
			foreach ( $this->pattern_files_in( $directory ) as $pattern_file ) {
				$pattern = Abstract_Pattern::from_file( $pattern_file );

				if ( '' === $pattern->name || isset( $seen[ $pattern->name ] ) ) {
					continue;
				}

				$seen[ $pattern->name ] = true;
				$patterns[]             = $pattern;
			}
		}

		return $patterns;
	}

	/**
	 * Every pattern file under a directory, however deep.
	 *
	 * @param string $directory Directory to scan.
	 * @return string[] Absolute file paths.
	 */
	private function pattern_files_in( $directory ) {
		$files = glob( $directory . '/*.php' );
		$files = is_array( $files ) ? $files : array();
		sort( $files );

		$subdirectories = glob( $directory . '/*', GLOB_ONLYDIR );
		if ( is_array( $subdirectories ) ) {
			sort( $subdirectories );
			foreach ( $subdirectories as $subdirectory ) {
				$files = array_merge( $files, $this->pattern_files_in( $subdirectory ) );
			}
		}

		return $files;
	}

	/**
	 * Finds a single theme pattern by its namespaced name.
	 *
	 * @param string $name Pattern name (e.g. "theme-slug/pattern-name").
	 * @return Abstract_Pattern|null
	 */
	public function find_theme_pattern( $name ) {
		foreach ( $this->get_theme_patterns() as $pattern ) {
			if ( $pattern->name === $name ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * Returns all user patterns (wp_block posts) from the database.
	 *
	 * @return Abstract_Pattern[]
	 */
	public function get_user_patterns(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		$patterns = array();

		foreach ( $query->posts as $post ) {
			$patterns[] = Abstract_Pattern::from_post( $post );
		}

		return $patterns;
	}

	/**
	 * Every pattern registered in PHP by something other than this site's own theme — what
	 * the browse page files under Plugin. Read-only: there is no file to write and no row to
	 * update, so a copy is the only way to change one.
	 *
	 * @return Abstract_Pattern[]
	 */
	public function get_plugin_patterns() {
		$theme_slugs = $this->theme_pattern_slugs();
		$patterns    = array();

		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $registered ) {
			$name = (string) ( $registered['name'] ?? '' );

			if ( '' === $name || isset( $theme_slugs[ $name ] ) || self::is_wordpress_own( $registered ) ) {
				continue;
			}

			$patterns[] = Abstract_Pattern::from_registered( $registered, self::plugin_label( $name ) );
		}

		return $patterns;
	}

	/**
	 * Finds a single plugin pattern by its registered name.
	 *
	 * @param string $name Pattern name including namespace.
	 * @return Abstract_Pattern|null
	 */
	public function find_plugin_pattern( $name ) {
		$name       = (string) $name;
		$registered = \WP_Block_Patterns_Registry::get_instance()->get_registered( $name );

		if ( ! is_array( $registered ) || self::is_wordpress_own( $registered ) || isset( $this->theme_pattern_slugs()[ $name ] ) ) {
			return null;
		}

		return Abstract_Pattern::from_registered( $registered, self::plugin_label( $name ) );
	}

	/**
	 * Whether a registered pattern is one WordPress itself put there — a core pattern or one
	 * fetched from the pattern directory. Core is the only thing that sets `source`; a
	 * pattern a theme or plugin registered carries none.
	 *
	 * @param array $registered The pattern as the registry reports it.
	 * @return bool
	 */
	private static function is_wordpress_own( array $registered ) {
		$source = (string) ( $registered['source'] ?? '' );

		return 'core' === $source || 0 === strpos( $source, 'pattern-directory' );
	}

	/**
	 * The slugs the theme's own pattern files claim, as a lookup.
	 *
	 * The registry cannot be asked which of its entries came from the theme: resolving a
	 * pattern's content unsets the `filePath` that would say so, so by the second time
	 * anything looks it is gone. So the files are read — the same directories everything else
	 * here reads, which is what keeps this honest while a lab theme is worn, and only their
	 * `Slug:` header rather than a full scan, which would render every one of them.
	 *
	 * @return array slug => true
	 */
	private function theme_pattern_slugs() {
		$slugs = array();

		foreach ( $this->get_pattern_directories() as $directory ) {
			foreach ( $this->pattern_files_in( $directory ) as $pattern_file ) {
				$slug = trim( get_file_data( $pattern_file, array( 'slug' => 'Slug' ) )['slug'] );

				if ( '' !== $slug ) {
					$slugs[ $slug ] = true;
				}
			}
		}

		return $slugs;
	}

	/**
	 * What to call the thing that registered a pattern: the name of an installed plugin whose
	 * directory or text domain matches the pattern's namespace, or the namespace itself.
	 *
	 * @param string $name Pattern name including namespace.
	 * @return string
	 */
	private static function plugin_label( $name ) {
		static $names = null;

		$namespace = strtok( (string) $name, '/' );

		if ( ! is_string( $namespace ) || '' === $namespace ) {
			return '';
		}

		if ( null === $names ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$names = array();

			foreach ( get_plugins() as $file => $plugin ) {
				if ( empty( $plugin['Name'] ) ) {
					continue;
				}

				foreach ( array( dirname( $file ), $plugin['TextDomain'] ?? '' ) as $key ) {
					if ( '' !== $key && '.' !== $key && ! isset( $names[ $key ] ) ) {
						$names[ $key ] = (string) $plugin['Name'];
					}
				}
			}
		}

		return $names[ $namespace ] ?? $namespace;
	}

	/**
	 * The patterns a piece of markup places, at any depth — the `slug` of every
	 * `core/pattern` block, deduplicated, in document order. A `core/block` is not one of
	 * these: it names a `wp_block` post, which the pattern registry never holds.
	 *
	 * @param string $content Block markup.
	 * @return string[] Referenced pattern names.
	 */
	public static function references_of( $content ) {
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return array();
		}

		return array_values( array_unique( self::reference_slugs( parse_blocks( $content ) ) ) );
	}

	/**
	 * Walk a parsed tree collecting `core/pattern` slugs.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return string[]
	 */
	private static function reference_slugs( $blocks ) {
		$found = array();

		foreach ( (array) $blocks as $block ) {
			if ( isset( $block['blockName'], $block['attrs']['slug'] ) && 'core/pattern' === $block['blockName'] ) {
				$slug = trim( (string) $block['attrs']['slug'] );
				if ( '' !== $slug ) {
					$found[] = $slug;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::reference_slugs( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}

	/**
	 * Point a pattern's references at the names given, leaving every other reference alone.
	 *
	 * @param string $content Block markup.
	 * @param array  $map Referenced name => the name to use instead.
	 * @return string
	 */
	public static function repoint_references( $content, array $map ) {
		foreach ( $map as $from => $to ) {
			if ( $from === $to ) {
				continue;
			}

			$content = preg_replace(
				'/("slug"\s*:\s*)"' . preg_quote( (string) $from, '/' ) . '"/',
				'$1"' . str_replace( '$', '\\$', (string) $to ) . '"',
				$content
			);
		}

		return $content;
	}

	/**
	 * The scheme an `Origin:` carries when it names something that is not a pattern on the
	 * cloud. An origin with a scheme is local to this site and never travels with an upload;
	 * a cloud name has none, and is the only kind the service can resolve. A colon cannot
	 * occur in a cloud name, whose every segment is a slug, so the two can never be mistaken
	 * for one another.
	 */
	const ORIGIN_PLUGIN_SCHEME = 'plugin:';

	/**
	 * How a copy of a plugin pattern records what it came from.
	 *
	 * @param string $name The registered pattern name.
	 * @return string
	 */
	public static function plugin_origin( $name ) {
		return self::ORIGIN_PLUGIN_SCHEME . $name;
	}

	/**
	 * Whether an `Origin:` names something on the cloud, which is the only kind that can be
	 * sent with an upload or resolved to a page there.
	 *
	 * @param string $origin The origin.
	 * @return bool
	 */
	public static function is_cloud_origin( $origin ) {
		$origin = (string) $origin;

		return '' !== $origin && false === strpos( $origin, ':' );
	}

	/**
	 * Copies a pattern a plugin registered into the theme, with every pattern it places at
	 * any depth, and points its references at the copies.
	 *
	 * Copying is idempotent: a plugin pattern the theme already carries a copy of is reused
	 * rather than copied again, so copying two pages that share a section leaves one section
	 * that both of them place. `Origin:` is what makes that exact — a free-text note could
	 * not be matched on.
	 *
	 * A reference the theme already answers to is left alone, and one nothing on this site
	 * answers to is left alone and reported: the plugin's own pattern renders it no better,
	 * so refusing the copy would help nobody.
	 *
	 * @param Abstract_Pattern $pattern The plugin pattern to copy.
	 * @param array            $overrides Fields to set on the copy before it is written.
	 * @return array|WP_Error { pattern: Abstract_Pattern, created: bool, copied: string[],
	 * unresolved: string[] }
	 */
	public function copy_plugin_pattern( Abstract_Pattern $pattern, $overrides = array() ) {
		$copied     = array();
		$unresolved = array();

		$copy = $this->copy_plugin_tree( $pattern, $overrides, array(), $copied, $unresolved );

		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		return array(
			'pattern'    => $copy['pattern'],
			'created'    => $copy['created'],
			'copied'     => $copied,
			'unresolved' => array_values( array_unique( $unresolved ) ),
		);
	}

	/**
	 * Copies one plugin pattern, its references first.
	 *
	 * @param Abstract_Pattern $pattern    The plugin pattern to copy.
	 * @param array            $overrides  Fields to set on the copy before it is written.
	 * @param array            $path       The names being copied above this one, for cycles.
	 * @param array            $copied     Accumulates the names written, leaves first.
	 * @param array            $unresolved Accumulates references nothing here answers to.
	 * @return array|WP_Error { pattern: Abstract_Pattern, created: bool }
	 */
	private function copy_plugin_tree( Abstract_Pattern $pattern, $overrides, $path, &$copied, &$unresolved ) {
		$existing = $this->find_copy_of_plugin_pattern( $pattern->name );

		if ( null !== $existing ) {
			return array(
				'pattern' => $existing,
				'created' => false,
			);
		}

		$map = array();

		foreach ( self::references_of( (string) $pattern->content ) as $reference ) {
			if ( in_array( $reference, $path, true ) ) {
				return new WP_Error(
					'pattern_builder_reference_cycle',
					sprintf(
						/* translators: %s: the chain of pattern names that loops. */
						__( 'These patterns place each other in a loop, so there is no order to copy them in: %s', 'pattern-builder' ),
						implode( ' → ', array_merge( $path, array( $reference ) ) )
					),
					array( 'status' => 400 )
				);
			}

			// Already a pattern of this theme's: the reference reaches it as it stands.
			if ( null !== $this->find_theme_pattern( $reference ) ) {
				continue;
			}

			$already = $this->find_copy_of_plugin_pattern( $reference );

			if ( null !== $already ) {
				$map[ $reference ] = $already->name;
				continue;
			}

			$dependency = $this->find_plugin_pattern( $reference );

			if ( null === $dependency ) {
				$unresolved[] = $reference;
				continue;
			}

			$written = $this->copy_plugin_tree(
				$dependency,
				array(),
				array_merge( $path, array( $pattern->name ) ),
				$copied,
				$unresolved
			);

			if ( is_wp_error( $written ) ) {
				return $written;
			}

			$map[ $reference ] = $written['pattern']->name;
		}

		$copy = clone $pattern;

		$copy->name         = basename( (string) $pattern->name );
		$copy->source       = 'theme';
		$copy->plugin       = '';
		$copy->id           = null;
		$copy->filePath     = null;
		$copy->hasCustomPhp = false;
		$copy->origin       = self::plugin_origin( $pattern->name );
		$copy->content      = self::repoint_references( (string) $pattern->content, $map );

		foreach ( $overrides as $field => $value ) {
			$copy->{$field} = $value;
		}

		$copy->name = self::namespaced_name( $copy->name );

		if ( null !== $this->find_theme_pattern( $copy->name ) ) {
			// Copying is not naming: the user picked a pattern, not a slug, so a name the
			// theme already uses for something else gets a number rather than an error.
			$copy->name = $this->unused_theme_name( $copy->name );
		}

		$copy->id = $copy->name;
		$saved    = $this->update_theme_pattern( $copy );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$copied[] = $saved->name;

		return array(
			'pattern' => $saved,
			'created' => true,
		);
	}

	/**
	 * The theme's own copy of a plugin pattern, by what that copy records it came from.
	 *
	 * @param string $name The registered pattern name.
	 * @return Abstract_Pattern|null
	 */
	public function find_copy_of_plugin_pattern( $name ) {
		$origin = self::plugin_origin( $name );

		foreach ( $this->get_theme_patterns() as $pattern ) {
			if ( (string) $pattern->origin === $origin ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * A namespaced name the theme is not already using, numbering the one given until it is
	 * free.
	 *
	 * @param string $name The namespaced name a copy would like.
	 * @return string
	 */
	public function unused_theme_name( $name ) {
		$suffix = 2;

		while ( null !== $this->find_theme_pattern( $name . '-' . $suffix ) ) {
			++$suffix;
		}

		return $name . '-' . $suffix;
	}

	/**
	 * Every cloud name a local pattern answers to, with the pattern it names.
	 *
	 * @return array name => { type: string, id: string|int, title: string }
	 */
	public function cloud_names() {
		$names = array();

		foreach ( $this->get_pattern_directories() as $directory ) {
			foreach ( $this->pattern_files_in( $directory ) as $pattern_file ) {
				$headers = get_file_data(
					$pattern_file,
					array(
						'title' => 'Title',
						'slug'  => 'Slug',
						'cloud' => 'Cloud',
					)
				);

				$slug = trim( $headers['slug'] );
				if ( '' === $slug ) {
					continue;
				}

				foreach ( array( $slug, trim( $headers['cloud'] ) ) as $name ) {
					if ( 2 === substr_count( $name, '/' ) && ! isset( $names[ $name ] ) ) {
						$names[ $name ] = array(
							'type'  => 'theme',
							'id'    => $slug,
							'title' => trim( $headers['title'] ),
						);
					}
				}
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_CLOUD, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		foreach ( $posts as $post ) {
			$name = (string) get_post_meta( $post->ID, self::META_CLOUD, true );
			if ( '' !== $name && ! isset( $names[ $name ] ) ) {
				$names[ $name ] = array(
					'type'  => 'user',
					'id'    => (int) $post->ID,
					'title' => $post->post_title,
				);
			}
		}

		return $names;
	}

	/**
	 * Updates a theme pattern by writing its PHP file.
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @param array            $options Optional settings: 'localize' (bool),
	 * 'import_images' (bool).
	 * @return Abstract_Pattern|WP_Error The pattern as re-read from disk, or an error.
	 */
	public function update_theme_pattern( Abstract_Pattern $pattern, $options = array() ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to modify theme patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}
		$pattern->name = self::namespaced_name( $pattern->name );
		if ( 'theme' === $pattern->source ) {
			$pattern->id = $pattern->name;
		}

		$writable = $this->refuse_if_file_has_custom_php( $pattern );
		if ( is_wp_error( $writable ) ) {
			return $writable;
		}
		if ( ! isset( $options['import_images'] ) || true === $options['import_images'] ) {
			$pattern = $this->import_pattern_image_assets( $pattern );
		}
		$pattern = $this->restore_home_links( $pattern );
		if ( isset( $options['localize'] ) && true === $options['localize'] ) {
			$pattern = Pattern_Builder_Localization::localize_pattern_content( $pattern );
		}

		$result = $this->update_theme_pattern_file( $pattern );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->flush_pattern_caches();
		$filepath = $this->get_pattern_filepath( $pattern );
		if ( ! is_wp_error( $filepath ) && $filepath ) {
			$pattern = Abstract_Pattern::from_file( $filepath );
		}

		return $pattern;
	}

	/**
	 * Record which cloud pattern a local pattern is a copy of, or forget it.
	 *
	 * @param Abstract_Pattern $pattern The local pattern.
	 * @param string           $name `{handle}/{collection}/{slug}`, or '' to clear it.
	 * @return true|WP_Error
	 */
	public function set_cloud_reference( Abstract_Pattern $pattern, $name ) {
		$name = (string) $name;
		if ( (string) $pattern->cloud === $name ) {
			return true;
		}

		if ( 'user' === $pattern->source ) {
			if ( '' === $name ) {
				delete_post_meta( (int) $pattern->id, self::META_CLOUD );
			} else {
				update_post_meta( (int) $pattern->id, self::META_CLOUD, $name );
			}
			return true;
		}

		$pattern->cloud = $name;
		$saved          = $this->update_theme_pattern( $pattern );

		return is_wp_error( $saved ) ? $saved : true;
	}

	/**
	 * Creates a theme pattern from a user pattern (wp_block), deleting the post.
	 *
	 * @param \WP_Post         $post The wp_block post to convert.
	 * @param Abstract_Pattern $pattern The pattern data to write (already carrying any
	 * edits).
	 * @param array            $options Optional settings passed to update_theme_pattern().
	 * @return Abstract_Pattern|WP_Error The new theme pattern, or an error.
	 */
	public function convert_user_pattern_to_theme( $post, Abstract_Pattern $pattern, $options = array() ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to modify theme patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		$pattern->name     = self::namespaced_name( $pattern->name );
		$pattern->source   = 'theme';
		$pattern->id       = $pattern->name;
		$pattern->filePath = null;

		$saved = $this->update_theme_pattern( $pattern, $options );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		wp_delete_post( $post->ID, true );

		return $saved;
	}

	/**
	 * Converts a theme pattern into a user pattern (wp_block), deleting the file.
	 *
	 * @param Abstract_Pattern $pattern The theme pattern to convert.
	 * @return Abstract_Pattern|WP_Error The new user pattern (with its post ID), or an
	 * error.
	 */
	public function convert_theme_pattern_to_user( Abstract_Pattern $pattern ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to modify theme patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		$filepath = $this->get_pattern_filepath( $pattern );
		$created  = $this->create_user_pattern( $pattern );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		if ( ! is_wp_error( $filepath ) && $filepath ) {
			$deleted = Pattern_Builder_Security::safe_file_delete(
				$filepath,
				array(
					get_stylesheet_directory() . '/patterns',
					get_template_directory() . '/patterns',
				)
			);

			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		$this->flush_pattern_caches();

		return $created;
	}

	/**
	 * Writes a pattern into the database as a new `wp_block`, exporting the images it points
	 * at into the media library so the post does not depend on the files it came from.
	 *
	 * @param Abstract_Pattern $pattern The pattern to write.
	 * @return Abstract_Pattern|WP_Error The new user pattern, with its post ID.
	 */
	public function create_user_pattern( Abstract_Pattern $pattern ) {
		$pattern = $this->export_pattern_image_assets( $pattern );

		$post_id = wp_insert_post(
			array(
				'post_title'   => $pattern->title,
				'post_name'    => basename( $pattern->name ),
				'post_content' => $pattern->content,
				'post_excerpt' => $pattern->description,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $pattern->synced ) {
			delete_post_meta( $post_id, 'wp_pattern_sync_status' );
		} else {
			update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
		}

		wp_set_object_terms( $post_id, $pattern->categories, 'wp_pattern_category', false );
		if ( $pattern->origin ) {
			update_post_meta( $post_id, self::META_ORIGIN, $pattern->origin );
		}
		if ( $pattern->cloud ) {
			update_post_meta( $post_id, self::META_CLOUD, $pattern->cloud );
		}

		return Abstract_Pattern::from_post( get_post( $post_id ) );
	}

	/**
	 * Deletes a theme pattern's PHP file.
	 *
	 * @param Abstract_Pattern $pattern The pattern to delete.
	 * @return array|WP_Error Success message array or WP_Error on failure.
	 */
	public function delete_theme_pattern( Abstract_Pattern $pattern ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to delete theme patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		$path = $this->get_pattern_filepath( $pattern );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$allowed_dirs = array(
			get_stylesheet_directory() . '/patterns',
			get_template_directory() . '/patterns',
		);
		$deleted      = Pattern_Builder_Security::safe_file_delete( $path, $allowed_dirs );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		$this->flush_pattern_caches();

		return array( 'message' => __( 'Pattern deleted successfully.', 'pattern-builder' ) );
	}

	/**
	 * Gets the filesystem path for a pattern's PHP file.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return string|WP_Error Pattern file path on success, WP_Error if not found.
	 */
	public function get_pattern_filepath( $pattern ) {
		$path = $pattern->filePath ?? $this->path_for_name( $pattern->name );

		if ( file_exists( $path ) ) {
			return $path;
		}

		$matched_pattern = $this->find_theme_pattern( $pattern->name );

		if ( $matched_pattern && isset( $matched_pattern->filePath ) ) {
			return $matched_pattern->filePath;
		}

		return new WP_Error(
			'pattern_file_not_found',
			__( 'Pattern file not found.', 'pattern-builder' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * A theme pattern's fully namespaced name.
	 *
	 * @param string $name Pattern name, namespaced or bare.
	 * @return string
	 */
	public static function namespaced_name( $name ) {
		$name = (string) $name;

		if ( '' === $name || false !== strpos( $name, '/' ) ) {
			return $name;
		}

		return get_stylesheet() . '/' . $name;
	}

	/**
	 * The file a pattern's name implies, under the active theme.
	 *
	 * @param string $name Namespaced pattern name.
	 * @return string Absolute path.
	 */
	private function path_for_name( $name ) {
		$segments = array_values( array_filter( explode( '/', (string) $name ), 'strlen' ) );
		$slug     = array_pop( $segments );
		if ( 1 === count( $segments ) && in_array( $segments[0], array( get_stylesheet(), get_template() ), true ) ) {
			$segments = array();
		}

		$parts = array_map( 'sanitize_file_name', array_merge( $segments, array( $slug ) ) );

		return get_stylesheet_directory() . '/patterns/' . implode( '/', $parts ) . '.php';
	}

	/**
	 * Writes a theme pattern's PHP file to disk.
	 *
	 * @param Abstract_Pattern $pattern The pattern to write.
	 * @return Abstract_Pattern|WP_Error
	 */
	public function update_theme_pattern_file( Abstract_Pattern $pattern ) {
		$writable = $this->refuse_if_file_has_custom_php( $pattern );
		if ( is_wp_error( $writable ) ) {
			return $writable;
		}

		$path = $this->get_pattern_filepath( $pattern );
		if ( is_wp_error( $path ) ) {
			$path = $this->path_for_name( $pattern->name );
		}

		$formatted_content = $this->format_block_markup( $pattern->content );
		$file_content      = $this->build_pattern_file_metadata( $pattern ) . $formatted_content . "\n";

		// Checked on the bytes about to be written, so every field and every caller is
		// covered at once, this plugin's own writers of PHP included.
		$custom_php = Abstract_Pattern::find_custom_php( $file_content );
		if ( null !== $custom_php ) {
			return new WP_Error(
				'pattern_has_custom_php',
				sprintf(
					/* translators: %s: the PHP found, e.g. "echo get_bloginfo( 'name' );". */
					__( 'The pattern was not stored, because it would put PHP into the theme’s file: %s. Pattern Builder writes only the PHP it generates itself, for localized text, the theme’s own images and links to the site’s own pages. Write any other PHP in the theme’s pattern file directly.', 'pattern-builder' ),
					$custom_php
				),
				array( 'status' => 400 )
			);
		}

		$allowed_dirs = array(
			get_stylesheet_directory() . '/patterns',
			get_template_directory() . '/patterns',
		);
		$response     = Pattern_Builder_Security::safe_file_write( $path, $file_content, $allowed_dirs );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		self::forget_compiled( $path );

		return $pattern;
	}

	/**
	 * Drop a pattern file from PHP's caches, having just written it.
	 *
	 * A pattern file is read by running it, and a write is normally followed by a read — the
	 * response to a save is the pattern as it now is. PHP has two reasons to hand back the
	 * file as it *was*: the stat cache, and opcache, which under the usual
	 * `validate_timestamps` with a `revalidate_freq` of a second or two does not look at the
	 * file's mtime again for that long. Either one makes a save appear not to have taken.
	 *
	 * @param string $path The file just written.
	 * @return void
	 */
	private static function forget_compiled( $path ) {
		clearstatcache( true, $path );

		if ( function_exists( 'opcache_invalidate' ) && ini_get( 'opcache.enable' ) ) {
			opcache_invalidate( $path, true );
		}
	}

	/**
	 * Refuses to write over a pattern file that holds PHP.
	 *
	 * Reading such a file runs it and keeps the output, so the pattern in hand is a
	 * snapshot of one run in whatever context that run had — not the program on disk.
	 * Writing it back would put the snapshot where the program was, losing every branch
	 * the run did not take. A pattern with no file yet is a creation and passes.
	 *
	 * @param Abstract_Pattern $pattern The pattern about to be written.
	 * @return true|WP_Error
	 */
	private function refuse_if_file_has_custom_php( Abstract_Pattern $pattern ) {
		$path = $this->get_pattern_filepath( $pattern );

		if ( is_wp_error( $path ) || ! Abstract_Pattern::file_has_custom_php( $path ) ) {
			return true;
		}

		return new WP_Error(
			'pattern_file_has_custom_php',
			__( 'This pattern’s file contains PHP, so Pattern Builder will not write it. Saving from here would replace that code with the output of a single run. Edit the file in the theme instead.', 'pattern-builder' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Forgets every cache derived from the theme's pattern files.
	 *
	 * @return void
	 */
	public function flush_pattern_caches() {
		$theme = wp_get_theme();
		if ( $theme->exists() ) {
			$theme->delete_pattern_cache();
		}

		$parent = $theme->parent();
		if ( $parent instanceof \WP_Theme && $parent->exists() ) {
			$parent->delete_pattern_cache();
		}

		Synced_Patterns::flush();

		delete_transient( 'synced_patterns_for_themes_' . get_stylesheet() );
	}

	/**
	 * Lists the pattern directories of the active theme and its parent.
	 *
	 * @return string[] Absolute directory paths.
	 */
	private function get_pattern_directories() {
		$directories = array( get_stylesheet_directory() . '/patterns' );

		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$directories[] = get_template_directory() . '/patterns';
		}

		return array_filter( $directories, 'is_dir' );
	}

	/**
	 * Builds the PHP header metadata block for a pattern file.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return string PHP header comment string.
	 */
	private function build_pattern_file_metadata( Abstract_Pattern $pattern ): string {
		$list = static function ( $values ) {
			return implode( ', ', array_map( array( self::class, 'header_value' ), (array) $values ) );
		};

		$title         = self::header_value( $pattern->title );
		$name          = self::header_value( $pattern->name );
		$description   = self::header_value( $pattern->description );
		$categories    = $pattern->categories ? "\n * Categories: " . $list( $pattern->categories ) : '';
		$keywords      = $pattern->keywords ? "\n * Keywords: " . $list( $pattern->keywords ) : '';
		$blockTypes    = $pattern->blockTypes ? "\n * Block Types: " . $list( $pattern->blockTypes ) : '';
		$postTypes     = $pattern->postTypes ? "\n * Post Types: " . $list( $pattern->postTypes ) : '';
		$templateTypes = $pattern->templateTypes ? "\n * Template Types: " . $list( $pattern->templateTypes ) : '';
		$viewportWidth = $pattern->viewportWidth ? "\n * Viewport Width: " . (int) $pattern->viewportWidth : '';
		$inserter      = $pattern->inserter ? '' : "\n * Inserter: no";
		$synced        = $pattern->synced ? "\n * Synced: yes" : '';
		$origin        = $pattern->origin ? "\n * Origin: " . self::header_value( $pattern->origin ) : '';
		$cloud         = $pattern->cloud ? "\n * Cloud: " . self::header_value( $pattern->cloud ) : '';

		$metadata  = "<?php\n";
		$metadata .= "/**\n";
		$metadata .= " * Title: $title\n";
		$metadata .= " * Slug: $name\n";
		$metadata .= " * Description: $description$categories$keywords$blockTypes$postTypes$templateTypes$viewportWidth$inserter$synced$origin$cloud\n";
		$metadata .= $this->build_additional_metadata( $pattern->additionalMetadata ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$metadata .= " */\n";
		$metadata .= "?>\n";
		return $metadata;
	}

	/**
	 * One header's value as it can sit in the file's comment block: on one line, because a
	 * newline would start a header of its own, and with `*` and `/` kept apart, because
	 * together they close the comment and make the rest of the value code. Newlines go
	 * first, or a `*` and `/` either side of one would meet.
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	private static function header_value( $value ) {
		return str_replace( '*/', '* /', (string) preg_replace( '/[\r\n]+/', ' ', (string) $value ) );
	}

	/**
	 * Renders a pattern's preserved header lines back into its file's comment block.
	 *
	 * This text reaches us from the editor, so it cannot be trusted to stay inside the
	 * comment it is written into: `*` followed by `/` closes the comment, and everything
	 * after it would be parsed as PHP. That sequence is the only way out of a block
	 * comment, and it is broken apart rather than refused so that pasting a code sample
	 * into the field still saves.
	 *
	 * @param string $text The preserved lines, newline-separated.
	 * @return string The comment lines to append, or '' when there is nothing to write.
	 */
	private function build_additional_metadata( $text ) {
		$kept = array();

		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) ) as $line ) {
			// A header typed in here would sit beside the real one and be ignored by
			// get_file_data(), so it is dropped: this field holds what the others do not.
			if ( ! Abstract_Pattern::is_recognised_header( $line ) ) {
				$kept[] = rtrim( str_replace( '*/', '* /', $line ) );
			}
		}

		$text = trim( implode( "\n", $kept ), "\n" );

		if ( '' === $text ) {
			return '';
		}

		$lines = array();

		foreach ( explode( "\n", $text ) as $line ) {
			$lines[] = '' === $line ? ' *' : ' * ' . $line;
		}

		// A blank line separates the headers from the rest, as theme pattern files write it.
		return " *\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Exports pattern image assets from the theme directory to the WordPress media library.
	 *
	 * @param Abstract_Pattern $pattern The pattern whose images should be exported.
	 * @return Abstract_Pattern Updated pattern with media library URLs.
	 */
	public function export_pattern_image_assets( $pattern ) {
		$home_url = home_url();

		/**
		 * Downloads a URL and uploads it to the media library.
		 *
		 * @param string $url Source URL.
		 * @return string|WP_Error New media library URL, or WP_Error on failure.
		 */
		$upload_image     = function ( $url ) use ( $home_url ) {
			if ( ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url ) ) {
				return new WP_Error(
					'invalid_image_type',
					__( 'Asset is not a valid image type.', 'pattern-builder' ),
					array( 'url' => $url )
				);
			}

			$download_file = false;
			$file_path     = str_replace( $home_url, ABSPATH, $url );
			if ( file_exists( $file_path ) ) {
				$temp_file = wp_tempnam( basename( $file_path ) );
				if ( copy( $file_path, $temp_file ) ) {
					$download_file = $temp_file;
				}
			}

			if ( ! $download_file ) {
				$download_file = download_url( $url );
			}

			if ( is_wp_error( $download_file ) ) {
				$parsed_url = wp_parse_url( $url );
				if ( 'localhost' === $parsed_url['host'] && '80' !== ( $parsed_url['port'] ?? null ) ) {
					$download_file = download_url( str_replace( 'localhost:' . $parsed_url['port'], 'localhost:80', $url ) );
				}
			}

			if ( is_wp_error( $download_file ) ) {
				return new WP_Error(
					'image_download_failed',
					__( 'Failed to download image asset.', 'pattern-builder' ),
					array(
						'url'   => $url,
						'error' => $download_file->get_error_message(),
					)
				);
			}

			$upload_dir = wp_upload_dir();
			if ( ! is_dir( $upload_dir['path'] ) ) {
				wp_mkdir_p( $upload_dir['path'] );
			}

			$upload_file = $upload_dir['path'] . '/' . basename( $url );
			if ( file_exists( $upload_file ) ) {
				return $upload_dir['url'] . '/' . basename( $upload_file );
			}
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				WP_Filesystem();
			}
			if ( ! $wp_filesystem->move( $download_file, $upload_file ) ) {
				return new WP_Error(
					'file_move_failed',
					__( 'Failed to move image file to uploads directory.', 'pattern-builder' ),
					array(
						'source'      => $download_file,
						'destination' => $upload_file,
					)
				);
			}

			$filetype   = wp_check_filetype( basename( $upload_file ), null );
			$attachment = array(
				'guid'           => $upload_dir['url'] . '/' . basename( $upload_file ),
				'post_mime_type' => $filetype['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $upload_file ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload_file );
			if ( is_wp_error( $attachment_id ) ) {
				return new WP_Error(
					'attachment_insert_failed',
					__( 'Failed to create media library attachment.', 'pattern-builder' ),
					array(
						'file'  => $upload_file,
						'error' => $attachment_id->get_error_message(),
					)
				);
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload_file );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			return wp_get_attachment_url( $attachment_id );
		};
		$pattern->content = preg_replace_callback(
			'/(src|href)="(' . preg_quote( $home_url, '/' ) . '[^"]+)"/',
			function ( $matches ) use ( $upload_image ) {
				$new_url = $upload_image( $matches[2] );
				if ( $new_url && ! is_wp_error( $new_url ) ) {
					return $matches[1] . '="' . $new_url . '"';
				}
				return $matches[0];
			},
			$pattern->content
		);
		$pattern->content = preg_replace_callback(
			'/"url"\s*:\s*"(' . preg_quote( $home_url, '/' ) . '[^"]+)"/',
			function ( $matches ) use ( $upload_image ) {
				$url     = $matches[1];
				$new_url = $upload_image( $url );
				if ( $new_url && ! is_wp_error( $new_url ) ) {
					return '"url":"' . $new_url . '"';
				}
				return $matches[0];
			},
			$pattern->content
		);

		return $pattern;
	}

	/**
	 * Points each link to a page of this site back at home_url(), which is what reading a
	 * pattern file renders it from, so a save keeps the call and the link follows the theme
	 * to any site. A path with a query string is left as it is: esc_url() would write its
	 * entities differently, so the call could not give back the same link.
	 *
	 * @param Abstract_Pattern $pattern The pattern about to be written.
	 * @return Abstract_Pattern
	 */
	private function restore_home_links( $pattern ) {
		$home = untrailingslashit( home_url() );

		$pattern->content = preg_replace_callback(
			'/href="' . preg_quote( $home, '/' ) . '(\/[A-Za-z0-9\/._~%#-]*)?"/',
			function ( $matches ) {
				$path = isset( $matches[1] ) ? $matches[1] : '';

				if ( preg_match( '#^/wp-(content|admin)(/|$)#', $path ) ) {
					return $matches[0];
				}

				$call = '' === $path ? 'home_url()' : "home_url( '" . $path . "' )";

				return 'href="<?php echo esc_url( ' . $call . ' ); ?>"';
			},
			(string) $pattern->content
		);

		return $pattern;
	}

	/**
	 * Imports pattern image assets from the media library into the theme's assets
	 * directory.
	 *
	 * @param Abstract_Pattern $pattern The pattern whose images should be imported.
	 * @return Abstract_Pattern Updated pattern with theme-relative asset paths.
	 */
	private function import_pattern_image_assets( $pattern ) {
		$home_url = home_url();

		/**
		 * Downloads a URL and saves it to the theme's assets/images directory.
		 *
		 * @param string $url Source URL.
		 * @return string|false Theme-relative path on success, false on failure.
		 */
		$download_and_save_image = function ( $url ) {
			if ( ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url ) ) {
				return false;
			}

			$download_file = download_url( $url );

			if ( is_wp_error( $download_file ) ) {
				$parsed_url = wp_parse_url( $url );
				if ( 'localhost' === $parsed_url['host'] && '80' !== ( $parsed_url['port'] ?? null ) ) {
					$download_file = download_url( str_replace( 'localhost:' . $parsed_url['port'], 'localhost:80', $url ) );
				}
			}

			if ( is_wp_error( $download_file ) ) {
				return false;
			}

			$filename         = sanitize_file_name( basename( $url ) );
			$asset_dir        = get_stylesheet_directory() . '/assets/images/';
			$destination_path = $asset_dir . $filename;

			if ( ! is_dir( $asset_dir ) ) {
				wp_mkdir_p( $asset_dir );
			}

			$allowed_dirs = array(
				'/tmp',
				get_stylesheet_directory() . '/assets',
				get_template_directory() . '/assets',
			);
			$result       = Pattern_Builder_Security::safe_file_move( $download_file, $destination_path, $allowed_dirs );

			if ( is_wp_error( $result ) ) {
				if ( file_exists( $download_file ) ) {
					wp_delete_file( $download_file );
				}
				return false;
			}

			return '/assets/images/' . $filename;
		};
		$pattern->content        = preg_replace_callback(
			'/(src|href)="(' . preg_quote( $home_url, '/' ) . '[^"]+)"/',
			function ( $matches ) use ( $download_and_save_image ) {
				$new_url = $download_and_save_image( $matches[2] );
				if ( $new_url ) {
					return $matches[1] . '="' . Pattern_Builder_Assets::theme_reference( $new_url ) . '"';
				}
				return $matches[0];
			},
			$pattern->content
		);
		$pattern->content        = preg_replace_callback(
			'/"url"\s*:\s*"(' . preg_quote( $home_url, '/' ) . '[^"]+)"/',
			function ( $matches ) use ( $download_and_save_image ) {
				$new_url = $download_and_save_image( $matches[1] );
				if ( $new_url ) {
					return '"url":"' . Pattern_Builder_Assets::theme_reference( $new_url ) . '"';
				}
				return $matches[0];
			},
			$pattern->content
		);

		return $pattern;
	}

	/**
	 * Formats block markup for readability.
	 *
	 * @param string $block_markup The block markup to format.
	 * @return string Formatted block markup.
	 */
	public function format_block_markup( $block_markup ) {
		$block_markup = $this->add_new_lines_to_block_markup( $block_markup );
		$block_markup = $this->indent_block_markup( $block_markup );
		return trim( $block_markup );
	}

	/**
	 * Adds newlines around block comment markers for readability.
	 *
	 * @param string $block_markup The block markup.
	 * @return string Block markup with newlines added.
	 */
	private function add_new_lines_to_block_markup( $block_markup ) {
		$block_markup = preg_replace_callback(
			'/<!--(.*?)-->/s',
			function ( $matches ) {
				$content = trim( $matches[1] );

				$content = preg_replace( '/\R\s*/', '', $content );

				return "\n<!-- {$content} -->\n";
			},
			$block_markup
		);
		$block_markup = str_replace( '/ -->', '/-->', $block_markup );
		$block_markup = preg_replace( '/\n{2,}/', "\n", $block_markup );
		$block_markup = preg_replace( '/^\s*[\r\n]/m', '', $block_markup );

		return $block_markup;
	}

	/**
	 * Applies indentation to block markup based on nesting depth.
	 *
	 * @param string $block_markup The block markup to indent.
	 * @return string Indented block markup.
	 */
	private function indent_block_markup( $block_markup ) {
		$lines = array_map( 'trim', explode( "\n", $block_markup ) );
		$lines = array_values( array_filter( $lines, 'strlen' ) );

		$indent_level = 0;
		$output       = array();
		$total        = count( $lines );

		foreach ( $lines as $index => $line ) {
			$previous = $index > 0 ? $lines[ $index - 1 ] : '';
			$next     = $index + 1 < $total ? $lines[ $index + 1 ] : '';

			$is_closing_comment = (bool) preg_match( '/^<!--\s*\/[\w:-]+\s*-->$/', $line );
			$is_closing_tag     = (bool) preg_match( '/^<\/[\w:-]+>$/', $line );

			if ( $is_closing_tag || ( $is_closing_comment && ! $this->is_own_markup( $previous ) ) ) {
				$indent_level = max( $indent_level - 1, 0 );
			}

			$indent   = str_repeat( "\t", $indent_level );
			$output[] = $indent . $this->expand_pattern_content( $line, $indent );

			$is_opening_comment = preg_match( '/^<!--\s*[\w:-]+\b.*-->$/', $line ) &&
				! preg_match( '/\/\s*-->$/', $line );
			$is_opening_tag     = (bool) preg_match( '/^<([\w:-]+)(\s[^>]*)?>$/', $line );

			if ( $is_opening_tag || ( $is_opening_comment && ! $this->is_own_markup( $next ) ) ) {
				++$indent_level;
			}
		}

		return implode( "\n", $output );
	}

	/**
	 * Whether a line is a block's own HTML rather than a delimiter.
	 *
	 * @param string $line One trimmed line of block markup.
	 * @return bool
	 */
	private function is_own_markup( $line ) {
		return '' !== $line && 0 === strpos( $line, '<' ) && 0 !== strpos( $line, '<!--' );
	}

	/**
	 * Writes a pattern reference's `content` out one slot to a line.
	 *
	 * @param string $line   One trimmed line of block markup.
	 * @param string $indent The line's own indentation, for the lines it breaks onto.
	 * @return string
	 */
	private function expand_pattern_content( $line, $indent ) {
		if ( ! preg_match( '/^<!--\s+wp:pattern\s+(\{.*\})\s+(\/)?-->$/', $line, $matches ) ) {
			return $line;
		}

		$attributes = json_decode( $matches[1] );

		if ( ! $attributes instanceof \stdClass || ! isset( $attributes->content ) || ! $attributes->content instanceof \stdClass ) {
			return $line;
		}

		$flags   = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$slots   = get_object_vars( $attributes->content );
		$encoded = array();

		foreach ( get_object_vars( $attributes ) as $key => $value ) {
			if ( 'content' !== $key || empty( $slots ) ) {
				$encoded[] = wp_json_encode( $key, $flags ) . ':' . wp_json_encode( $value, $flags );
				continue;
			}

			$entries = array();
			foreach ( $slots as $slot => $slot_value ) {
				$entries[] = $indent . "\t" . wp_json_encode( $slot, $flags ) . ':' . wp_json_encode( $slot_value, $flags );
			}

			$encoded[] = wp_json_encode( $key, $flags ) . ":{\n" . implode( ",\n", $entries ) . "\n" . $indent . '}';
		}

		return '<!-- wp:pattern {' . implode( ',', $encoded ) . '}' . ( $matches[2] ? ' /' : ' ' ) . '-->';
	}
}
