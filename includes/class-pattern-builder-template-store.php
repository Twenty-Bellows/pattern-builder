<?php
/**
 * The theme's block templates and template parts, read and written as files.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Block_Templates_Registry;
use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates and template parts as WordPress resolves them, written only into the active
 * theme's own folders.
 *
 * What renders comes from one of three places, in core's order: a Site Editor
 * customization saved as a `wp_template` or `wp_template_part` row, then a theme file
 * (the child theme's before its parent's), then a template a plugin registered. Every
 * write lands in a file, and folds a customization of the same slug into it.
 */
class Pattern_Builder_Template_Store {
	/**
	 * The types an agent names, and the post types core keeps them as.
	 */
	const POST_TYPES = array(
		'template'      => 'wp_template',
		'template-part' => 'wp_template_part',
	);

	/**
	 * A slug this store will write: lowercase, flat, and safe as a filename.
	 */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,199}$/';

	/**
	 * The core post type for a type name.
	 *
	 * @param string $type "template" or "template-part".
	 * @return string|null
	 */
	public static function post_type( $type ) {
		return isset( self::POST_TYPES[ $type ] ) ? self::POST_TYPES[ $type ] : null;
	}

	/**
	 * Whether a slug is one this store will write.
	 *
	 * @param string $slug Template slug.
	 * @return bool
	 */
	public static function is_writable_slug( $slug ) {
		return 1 === preg_match( self::SLUG_PATTERN, (string) $slug );
	}

	/**
	 * Every template or template part that resolves on this site, without content.
	 *
	 * @param string $type "template" or "template-part".
	 * @return array[] Summaries, by slug.
	 */
	public function all( $type ) {
		$post_type = self::post_type( $type );
		if ( null === $post_type ) {
			return array();
		}

		$files          = $this->files( $post_type );
		$customizations = array();
		foreach ( $this->customizations( $post_type ) as $post ) {
			if ( ! isset( $customizations[ $post->post_name ] ) ) {
				$customizations[ $post->post_name ] = $post;
			}
		}

		$slugs = array_merge( array_keys( $files ), array_keys( $customizations ) );
		if ( 'wp_template' === $post_type ) {
			foreach ( WP_Block_Templates_Registry::get_instance()->get_all_registered() as $registered ) {
				$slugs[] = $registered->slug;
			}
		}

		$slugs = array_unique( array_map( 'strval', $slugs ) );
		sort( $slugs );

		$found = array();
		foreach ( $slugs as $slug ) {
			$record = $this->resolve(
				$type,
				$slug,
				isset( $files[ $slug ] ) ? $files[ $slug ] : null,
				isset( $customizations[ $slug ] ) ? $customizations[ $slug ] : null
			);
			if ( null !== $record ) {
				unset( $record['content'] );
				$found[ $slug ] = $record;
			}
		}

		return $found;
	}

	/**
	 * One template or template part as it renders, with its content.
	 *
	 * @param string $type "template" or "template-part".
	 * @param string $slug Template slug.
	 * @return array|null
	 */
	public function find( $type, $slug ) {
		$post_type = self::post_type( $type );
		$slug      = (string) $slug;
		if ( null === $post_type || '' === $slug ) {
			return null;
		}

		$files = $this->files( $post_type );

		return $this->resolve(
			$type,
			$slug,
			isset( $files[ $slug ] ) ? $files[ $slug ] : null,
			$this->customization( $post_type, $slug )
		);
	}

	/**
	 * Put together what renders for one slug from the places it can come from.
	 *
	 * @param string        $type "template" or "template-part".
	 * @param string        $slug Template slug.
	 * @param array|null    $file The theme file, as `files()` describes it.
	 * @param \WP_Post|null $customization The customization row.
	 * @return array|null
	 */
	private function resolve( $type, $slug, $file, $customization ) {
		$post_type  = self::post_type( $type );
		$registered = 'wp_template' === $post_type ? WP_Block_Templates_Registry::get_instance()->get_by_slug( $slug ) : null;

		if ( null === $file && null === $customization && null === $registered ) {
			return null;
		}

		$record = array(
			'type'         => $type,
			'slug'         => $slug,
			'title'        => $slug,
			'description'  => '',
			'source'       => 'theme',
			'theme'        => null === $file ? '' : $file['theme'],
			'path'         => null === $file ? '' : $file['relative'],
			'hasThemeFile' => null !== $file,
			'customized'   => null !== $customization,
			'content'      => '',
		);

		if ( null !== $file ) {
			$record = array_merge( $record, $this->file_metadata( $post_type, $slug ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a theme's own template file.
			$record['content'] = (string) file_get_contents( $file['path'] );
		} elseif ( null !== $registered ) {
			$record['source']      = 'plugin';
			$record['title']       = (string) $registered->title;
			$record['description'] = (string) $registered->description;
			$record['plugin']      = (string) $registered->plugin;
			$record['content']     = (string) $registered->content;
			if ( ! empty( $registered->post_types ) ) {
				$record['postTypes'] = array_values( (array) $registered->post_types );
			}
		}

		if ( null !== $customization ) {
			$record['source']  = 'custom';
			$record['content'] = (string) $customization->post_content;
			if ( '' !== $customization->post_title ) {
				$record['title'] = $customization->post_title;
			}
			if ( '' !== $customization->post_excerpt ) {
				$record['description'] = $customization->post_excerpt;
			}
			if ( 'wp_template_part' === $post_type ) {
				$areas          = get_the_terms( $customization, 'wp_template_part_area' );
				$record['area'] = is_array( $areas ) && $areas ? $areas[0]->name : WP_TEMPLATE_PART_AREA_UNCATEGORIZED;
			}
		}

		return $record;
	}

	/**
	 * Write a template or template part into the active theme.
	 *
	 * A Site Editor customization of the same slug is what the caller read and edited, so
	 * once the file holds the new content the row is removed, as the Site Editor's own reset
	 * removes it, and the file renders.
	 *
	 * @param string $type "template" or "template-part".
	 * @param string $slug Template slug.
	 * @param string $content Block markup.
	 * @param array  $meta Optional: title; area (parts); postTypes (templates).
	 * @return array|WP_Error { template, folded, warnings }.
	 */
	public function save( $type, $slug, $content, $meta = array() ) {
		$post_type = self::post_type( $type );
		$refused   = $this->refuse_write( $post_type, $slug );
		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		$content = self::without_own_theme_attribute( (string) $content );
		if ( false !== strpos( $content, '<?' ) ) {
			return new WP_Error(
				'pb_template_has_php',
				__( 'The template was not stored, because it contains "<?". WordPress reads a template file as text and never runs it, so any PHP in one is sent to the browser as it stands, and the same bytes in a pattern file would run. Put dynamic output in a block, or in a pattern the template references.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		$path    = $this->own_path( $post_type, $slug );
		$written = Pattern_Builder_Security::safe_file_write( $path, rtrim( $content ) . "\n", $this->own_folders() );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		clearstatcache( true, $path );

		$folded = false;
		foreach ( $this->customizations( $post_type, $slug ) as $post ) {
			wp_delete_post( $post->ID, true );
			$folded = true;
		}

		$warnings = $this->write_metadata( $post_type, $slug, $meta );

		return array(
			'template' => $this->find( $type, $slug ),
			'folded'   => $folded,
			'warnings' => $warnings,
		);
	}

	/**
	 * Remove a template or template part: the active theme's file, any customization of
	 * it, and its entry in the active theme's theme.json.
	 *
	 * @param string $type "template" or "template-part".
	 * @param string $slug Template slug.
	 * @return array|WP_Error { removed, now }: what was removed, and what renders in its
	 * place, if anything.
	 */
	public function delete( $type, $slug ) {
		$post_type = self::post_type( $type );
		$refused   = $this->refuse_write( $post_type, $slug );
		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		$record = $this->find( $type, $slug );
		if ( null === $record ) {
			return self::not_found( $type, $slug );
		}

		$own_path = $this->own_path( $post_type, $slug );
		$own_file = file_exists( $own_path );

		if ( ! $own_file && ! $record['customized'] ) {
			if ( 'plugin' === $record['source'] ) {
				return new WP_Error(
					'pb_template_is_read_only',
					sprintf(
						/* translators: %s: template slug. */
						__( '%s is registered by a plugin, which owns it, and the theme has no copy of its own to remove.', 'pattern-builder' ),
						$slug
					),
					array( 'status' => 409 )
				);
			}

			return new WP_Error(
				'pb_template_in_parent',
				sprintf(
					/* translators: 1: template slug, 2: parent theme slug. */
					__( '%1$s comes from the parent theme, %2$s, and Pattern Builder writes only to the active theme. There is nothing of the active theme\'s own to remove.', 'pattern-builder' ),
					$slug,
					get_template()
				),
				array( 'status' => 409 )
			);
		}

		$parent_has_one = get_template() !== get_stylesheet() && file_exists( $this->theme_path( get_template(), $post_type, $slug ) );
		if ( 'wp_template' === $post_type && 'index' === $slug && $own_file && ! $parent_has_one ) {
			return new WP_Error(
				'pb_template_index_required',
				__( 'The index template cannot be removed: it is the last fallback every request reaches, and WordPress stops treating a theme with no templates/index.html as a block theme at all.', 'pattern-builder' ),
				array( 'status' => 409 )
			);
		}

		$removed = array(
			'file'          => '',
			'customization' => false,
		);

		if ( $own_file ) {
			$deleted = Pattern_Builder_Security::safe_file_delete( $own_path, $this->own_folders() );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			clearstatcache( true, $own_path );
			$removed['file'] = $record['path'];
		}

		foreach ( $this->customizations( $post_type, $slug ) as $post ) {
			wp_delete_post( $post->ID, true );
			$removed['customization'] = true;
		}

		$this->remove_metadata( $post_type, $slug );

		return array(
			'removed' => $removed,
			'now'     => $this->find( $type, $slug ),
		);
	}

	/**
	 * Whether a slug would still resolve once the active theme's own file and any
	 * customization were gone: to the parent theme's file, or to a plugin's template.
	 *
	 * @param string $type "template" or "template-part".
	 * @param string $slug Template slug.
	 * @return bool
	 */
	public function has_fallback( $type, $slug ) {
		$post_type = self::post_type( $type );
		if ( null === $post_type ) {
			return false;
		}

		if ( get_template() !== get_stylesheet() && file_exists( $this->theme_path( get_template(), $post_type, $slug ) ) ) {
			return true;
		}

		return 'wp_template' === $post_type && null !== WP_Block_Templates_Registry::get_instance()->get_by_slug( $slug );
	}

	/**
	 * The refusal for a write this store will not make, if any.
	 *
	 * @param string|null $post_type Core post type.
	 * @param string      $slug Template slug.
	 * @return true|WP_Error
	 */
	private function refuse_write( $post_type, $slug ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to modify the theme\'s templates.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		if ( null === $post_type ) {
			return new WP_Error(
				'pb_template_bad_type',
				__( 'The type must be "template" or "template-part".', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::is_writable_slug( $slug ) ) {
			return new WP_Error(
				'pb_template_bad_slug',
				sprintf(
					/* translators: %s: the slug given. */
					__( '"%s" is not a slug Pattern Builder will write: use lowercase letters, numbers, hyphens and underscores, as in "single-product" or "header-dark", with no folders.', 'pattern-builder' ),
					$slug
				),
				array( 'status' => 400 )
			);
		}

		if ( ! wp_is_block_theme() ) {
			return new WP_Error(
				'pb_template_not_block_theme',
				__( 'The active theme is not a block theme, so it has no block templates or template parts to write.', 'pattern-builder' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * The refusal for a slug nothing answers to.
	 *
	 * @param string $type "template" or "template-part".
	 * @param string $slug Template slug.
	 * @return WP_Error
	 */
	public static function not_found( $type, $slug ) {
		return new WP_Error(
			'pb_template_not_found',
			sprintf(
				/* translators: 1: template type, 2: template slug. */
				__( 'No %1$s named %2$s resolves on this site. list-templates says what does.', 'pattern-builder' ),
				'template-part' === $type ? __( 'template part', 'pattern-builder' ) : __( 'template', 'pattern-builder' ),
				$slug
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * The template files of the active theme and its parent, the child's winning a slug both
	 * have, read fresh rather than through core's per-process list, which would hide a file
	 * written during this request.
	 *
	 * @param string $post_type Core post type.
	 * @return array slug => { path, relative, theme }.
	 */
	private function files( $post_type ) {
		$found = array();

		foreach ( $this->themes() as $theme => $directory ) {
			$folder = $this->folder( $theme, $post_type );
			$base   = $directory . '/' . $folder;
			if ( ! is_dir( $base ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || 'html' !== strtolower( $file->getExtension() ) ) {
					continue;
				}

				$relative = substr( wp_normalize_path( $file->getPathname() ), strlen( wp_normalize_path( $base ) ) + 1 );
				$slug     = substr( $relative, 0, -5 );
				if ( isset( $found[ $slug ] ) ) {
					continue;
				}

				$found[ $slug ] = array(
					'path'     => $file->getPathname(),
					'relative' => $folder . '/' . $relative,
					'theme'    => $theme,
				);
			}
		}

		return $found;
	}

	/**
	 * The active theme and its parent, the active one first.
	 *
	 * @return array stylesheet => directory.
	 */
	private function themes() {
		$themes = array( get_stylesheet() => get_stylesheet_directory() );
		if ( get_template() !== get_stylesheet() ) {
			$themes[ get_template() ] = get_template_directory();
		}

		return $themes;
	}

	/**
	 * The folder a theme keeps one kind of template in, which older themes name differently.
	 *
	 * @param string $theme Theme stylesheet.
	 * @param string $post_type Core post type.
	 * @return string
	 */
	private function folder( $theme, $post_type ) {
		$folders = get_block_theme_folders( $theme );

		return $folders[ $post_type ];
	}

	/**
	 * Where a slug's file is, or would be, in a theme.
	 *
	 * @param string $theme Theme stylesheet.
	 * @param string $post_type Core post type.
	 * @param string $slug Template slug.
	 * @return string
	 */
	private function theme_path( $theme, $post_type, $slug ) {
		$directory = get_stylesheet() === $theme ? get_stylesheet_directory() : get_template_directory();

		return $directory . '/' . $this->folder( $theme, $post_type ) . '/' . $slug . '.html';
	}

	/**
	 * Where a slug's file is, or would be, in the active theme.
	 *
	 * @param string $post_type Core post type.
	 * @param string $slug Template slug.
	 * @return string
	 */
	private function own_path( $post_type, $slug ) {
		return $this->theme_path( get_stylesheet(), $post_type, $slug );
	}

	/**
	 * The only folders a write may land in.
	 *
	 * @return string[]
	 */
	private function own_folders() {
		$folders = array();
		foreach ( self::POST_TYPES as $post_type ) {
			$folders[] = get_stylesheet_directory() . '/' . $this->folder( get_stylesheet(), $post_type );
		}

		return $folders;
	}

	/**
	 * The active theme's published customizations: the rows that render in place of a file.
	 *
	 * @param string      $post_type Core post type.
	 * @param string|null $slug One slug, or every one.
	 * @return \WP_Post[]
	 */
	private function customizations( $post_type, $slug = null ) {
		$args = array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Core files every customization under its theme this way; there is no other key to find them by.
			'tax_query'              => array(
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => get_stylesheet(),
				),
			),
		);
		if ( null !== $slug ) {
			$args['post_name__in'] = array( $slug );
		}

		$query = new WP_Query( $args );

		return $query->posts;
	}

	/**
	 * The customization that renders for one slug, if any.
	 *
	 * @param string $post_type Core post type.
	 * @param string $slug Template slug.
	 * @return \WP_Post|null
	 */
	private function customization( $post_type, $slug ) {
		$posts = $this->customizations( $post_type, $slug );

		return $posts ? $posts[0] : null;
	}

	/**
	 * What a theme file's metadata is, from the same places core reads it: the default
	 * template types, then the theme's theme.json, then a plugin registration of the slug.
	 *
	 * @param string $post_type Core post type.
	 * @param string $slug Template slug.
	 * @return array title, description, and area or postTypes.
	 */
	private function file_metadata( $post_type, $slug ) {
		$metadata = array();

		if ( 'wp_template_part' === $post_type ) {
			$parts            = $this->theme_json_entries( 'templateParts' );
			$metadata['area'] = isset( $parts[ $slug ]['area'] ) ? _filter_block_template_part_area( $parts[ $slug ]['area'] ) : WP_TEMPLATE_PART_AREA_UNCATEGORIZED;
			if ( ! empty( $parts[ $slug ]['title'] ) ) {
				$metadata['title'] = (string) $parts[ $slug ]['title'];
			}

			return $metadata;
		}

		$defaults = get_default_block_template_types();
		if ( isset( $defaults[ $slug ] ) ) {
			return array(
				'title'       => (string) $defaults[ $slug ]['title'],
				'description' => (string) $defaults[ $slug ]['description'],
			);
		}

		$custom = $this->theme_json_entries( 'customTemplates' );
		if ( ! empty( $custom[ $slug ]['title'] ) ) {
			$metadata['title'] = (string) $custom[ $slug ]['title'];
		}
		if ( ! empty( $custom[ $slug ]['postTypes'] ) ) {
			$metadata['postTypes'] = array_values( (array) $custom[ $slug ]['postTypes'] );
		}

		$registered = WP_Block_Templates_Registry::get_instance()->get_by_slug( $slug );
		if ( $registered ) {
			if ( ! isset( $metadata['title'] ) && '' !== (string) $registered->title ) {
				$metadata['title'] = (string) $registered->title;
			}
			$metadata['description'] = (string) $registered->description;
		}

		return $metadata;
	}

	/**
	 * The `customTemplates` or `templateParts` entries of the active theme and its parent, by
	 * name, the active theme's winning a name both define.
	 *
	 * Read from the files rather than through core's resolver, which keeps each theme.json it
	 * has decoded for the rest of the request, so an entry written during it would not show.
	 *
	 * @param string $key "customTemplates" or "templateParts".
	 * @return array name => entry.
	 */
	private function theme_json_entries( $key ) {
		$entries = array();

		foreach ( array_reverse( $this->themes(), true ) as $directory ) {
			$path = $directory . '/theme.json';
			if ( ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a theme's own theme.json.
			$config = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $config ) || empty( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
				continue;
			}

			foreach ( $config[ $key ] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['name'] ) ) {
					$entries[ (string) $entry['name'] ] = $entry;
				}
			}
		}

		return $entries;
	}

	/**
	 * Record a template's title and post types, or a part's title and area, in the active
	 * theme's theme.json, which is the only place a theme file's metadata lives.
	 *
	 * @param string $post_type Core post type.
	 * @param string $slug Template slug.
	 * @param array  $meta title; area (parts); postTypes (templates).
	 * @return array Warnings, as { code, message }.
	 */
	private function write_metadata( $post_type, $slug, $meta ) {
		$entry = array();
		if ( isset( $meta['title'] ) && '' !== trim( (string) $meta['title'] ) ) {
			$entry['title'] = trim( (string) $meta['title'] );
		}
		if ( 'wp_template_part' === $post_type && isset( $meta['area'] ) && '' !== (string) $meta['area'] ) {
			$entry['area'] = (string) $meta['area'];
		}
		if ( 'wp_template' === $post_type && isset( $meta['postTypes'] ) ) {
			$entry['postTypes'] = array_values( array_map( 'strval', (array) $meta['postTypes'] ) );
		}

		if ( ! $entry ) {
			return array();
		}

		if ( 'wp_template' === $post_type && isset( get_default_block_template_types()[ $slug ] ) ) {
			return array(
				array(
					'code'    => 'template_default_metadata',
					'message' => sprintf(
						/* translators: %s: template slug. */
						__( '%s is one of WordPress\'s own template types, which takes its title from WordPress and applies wherever the template hierarchy reaches it, so the title and post types given were not recorded.', 'pattern-builder' ),
						$slug
					),
				),
			);
		}

		$key    = 'wp_template' === $post_type ? 'customTemplates' : 'templateParts';
		$config = Pattern_Builder_Theme_Json::load( 'theme' );
		if ( ! is_wp_error( $config ) ) {
			$list  = isset( $config[ $key ] ) && is_array( $config[ $key ] ) ? array_values( $config[ $key ] ) : array();
			$index = null;
			foreach ( $list as $position => $item ) {
				if ( isset( $item['name'] ) && $slug === $item['name'] ) {
					$index = $position;
					break;
				}
			}

			$current = null === $index ? array( 'name' => $slug ) : $list[ $index ];
			$merged  = array_merge( $current, $entry );
			// Core reads an empty postTypes as "no post type", not "every post type".
			if ( isset( $merged['postTypes'] ) && ! $merged['postTypes'] ) {
				unset( $merged['postTypes'] );
			}

			if ( null !== $index && $merged === $current ) {
				return array();
			}

			if ( null === $index ) {
				$list[] = $merged;
			} else {
				$list[ $index ] = $merged;
			}
			$config[ $key ] = $list;
			$config         = Pattern_Builder_Theme_Json::save( 'theme', $config );
		}

		if ( is_wp_error( $config ) ) {
			return array(
				array(
					'code'    => 'template_metadata_not_recorded',
					'message' => sprintf(
						/* translators: %s: why theme.json could not be changed. */
						__( 'The file was written, but its title, area or post types were not recorded, because theme.json could not be changed: %s', 'pattern-builder' ),
						$config->get_error_message()
					),
				),
			);
		}

		return array();
	}

	/**
	 * Drop a slug's entry from the active theme's theme.json, when it has one.
	 *
	 * @param string $post_type Core post type.
	 * @param string $slug Template slug.
	 * @return void
	 */
	private function remove_metadata( $post_type, $slug ) {
		$config = Pattern_Builder_Theme_Json::load( 'theme' );
		$key    = 'wp_template' === $post_type ? 'customTemplates' : 'templateParts';
		if ( is_wp_error( $config ) || empty( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
			return;
		}

		$kept = array_values(
			array_filter(
				$config[ $key ],
				static function ( $item ) use ( $slug ) {
					return ! isset( $item['name'] ) || $slug !== $item['name'];
				}
			)
		);
		if ( count( $kept ) === count( $config[ $key ] ) ) {
			return;
		}

		if ( $kept ) {
			$config[ $key ] = $kept;
		} else {
			unset( $config[ $key ] );
		}
		Pattern_Builder_Theme_Json::save( 'theme', $config );
	}

	/**
	 * Drop a template part reference's `theme` attribute where it names the active theme,
	 * as core's own export does: a theme's file reaches its own parts without it, and keeps
	 * reaching them under any other theme slug.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	public static function without_own_theme_attribute( $content ) {
		$stylesheet = get_stylesheet();

		return (string) preg_replace_callback(
			'#<!--\s+wp:(core/)?template-part\s+(\{.*?\})\s+(/?)-->#s',
			static function ( $found ) use ( $stylesheet ) {
				$attrs = json_decode( $found[2], true );
				if ( ! is_array( $attrs ) || ! isset( $attrs['theme'] ) || $stylesheet !== $attrs['theme'] ) {
					return $found[0];
				}

				unset( $attrs['theme'] );

				return '<!-- wp:' . $found[1] . 'template-part ' . ( $attrs ? serialize_block_attributes( $attrs ) . ' ' : '' ) . $found[3] . '-->';
			},
			$content
		);
	}
}
