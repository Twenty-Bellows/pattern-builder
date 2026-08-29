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
 *
 * Theme patterns live in PHP files in the theme's (and parent theme's)
 * `patterns/` directory — the files are the only source of truth, nothing is
 * mirrored into the database. User patterns are core `wp_block` posts and are
 * only touched here for listing and conversion.
 */
class Pattern_File_Store {

	/**
	 * Returns all patterns found as PHP files in the active theme's and the
	 * parent theme's `patterns/` directories.
	 *
	 * @return Abstract_Pattern[]
	 */
	public function get_theme_patterns() {
		$patterns = array();
		$seen     = array();

		foreach ( $this->get_pattern_directories() as $directory ) {
			$pattern_files = glob( $directory . '/*.php' );

			if ( ! is_array( $pattern_files ) ) {
				continue;
			}

			foreach ( $pattern_files as $pattern_file ) {
				$pattern = Abstract_Pattern::from_file( $pattern_file );

				if ( '' === $pattern->name || isset( $seen[ $pattern->name ] ) ) {
					// A child theme pattern overrides a parent pattern with the same slug.
					continue;
				}

				$seen[ $pattern->name ] = true;
				$patterns[]             = $pattern;
			}
		}

		return $patterns;
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
	 * Updates a theme pattern by writing its PHP file.
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @param array            $options Optional settings: 'localize' (bool), 'import_images' (bool).
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

		// Import images unless explicitly disabled.
		if ( ! isset( $options['import_images'] ) || true === $options['import_images'] ) {
			$pattern = $this->import_pattern_image_assets( $pattern );
		}

		// Localize if enabled.
		if ( isset( $options['localize'] ) && true === $options['localize'] ) {
			$pattern = Pattern_Builder_Localization::localize_pattern_content( $pattern );
		}

		$result = $this->update_theme_pattern_file( $pattern );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->flush_pattern_caches();

		// Rebuild the pattern from the file (so that content has no PHP tags).
		$filepath = $this->get_pattern_filepath( $pattern );
		if ( ! is_wp_error( $filepath ) && $filepath ) {
			$pattern = Abstract_Pattern::from_file( $filepath );
		}

		return $pattern;
	}

	/**
	 * Creates a theme pattern from a user pattern (wp_block), deleting the post.
	 *
	 * @param \WP_Post         $post    The wp_block post to convert.
	 * @param Abstract_Pattern $pattern The pattern data to write (already carrying any edits).
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

		// Theme patterns are namespaced with the theme slug.
		if ( false === strpos( $pattern->name, '/' ) ) {
			$pattern->name = get_stylesheet() . '/' . $pattern->name;
		}

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
	 * @return Abstract_Pattern|WP_Error The new user pattern (with its post ID), or an error.
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

		// Export any theme assets to the media library.
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

		// Delete the theme pattern file.
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
		$path = $pattern->filePath ?? get_stylesheet_directory() . '/patterns/' . sanitize_file_name( basename( $pattern->name ) ) . '.php';

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
	 * Writes a theme pattern's PHP file to disk.
	 *
	 * Creates the file if it doesn't exist. Content is formatted before writing.
	 *
	 * @param Abstract_Pattern $pattern The pattern to write.
	 * @return Abstract_Pattern|WP_Error
	 */
	public function update_theme_pattern_file( Abstract_Pattern $pattern ) {
		$path = $this->get_pattern_filepath( $pattern );

		// If get_pattern_filepath returns an error, construct a new path.
		if ( is_wp_error( $path ) ) {
			$filename = sanitize_file_name( basename( $pattern->name ) );
			$path     = get_stylesheet_directory() . '/patterns/' . $filename . '.php';
		}

		$formatted_content = $this->format_block_markup( $pattern->content );
		$file_content      = $this->build_pattern_file_metadata( $pattern ) . $formatted_content;

		$allowed_dirs = array(
			get_stylesheet_directory() . '/patterns',
			get_template_directory() . '/patterns',
		);
		$response     = Pattern_Builder_Security::safe_file_write( $path, $file_content, $allowed_dirs );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $pattern;
	}

	/**
	 * Forgets every cache derived from the theme's pattern files.
	 *
	 * Covers core's per-theme pattern header cache, this plugin's synced-slug
	 * lookup, and — when the Synced Patterns for Themes plugin is active — its
	 * synced-slug lookup too, so a file write is visible everywhere at once.
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

		if ( class_exists( '\\TwentyBellows\\SyncedPatternsForThemes\\Synced_Patterns' ) ) {
			\TwentyBellows\SyncedPatternsForThemes\Synced_Patterns::flush();
		}
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

		$categories    = $pattern->categories ? "\n * Categories: " . implode( ', ', $pattern->categories ) : '';
		$keywords      = $pattern->keywords ? "\n * Keywords: " . implode( ', ', $pattern->keywords ) : '';
		$blockTypes    = $pattern->blockTypes ? "\n * Block Types: " . implode( ', ', $pattern->blockTypes ) : '';
		$postTypes     = $pattern->postTypes ? "\n * Post Types: " . implode( ', ', $pattern->postTypes ) : '';
		$templateTypes = $pattern->templateTypes ? "\n * Template Types: " . implode( ', ', $pattern->templateTypes ) : '';
		$viewportWidth = $pattern->viewportWidth ? "\n * Viewport Width: " . (int) $pattern->viewportWidth : '';
		$inserter      = $pattern->inserter ? '' : "\n * Inserter: no";
		$synced        = $pattern->synced ? "\n * Synced: yes" : '';

		$metadata  = "<?php\n";
		$metadata .= "/**\n";
		$metadata .= " * Title: $pattern->title\n";
		$metadata .= " * Slug: $pattern->name\n";
		$metadata .= " * Description: $pattern->description$categories$keywords$blockTypes$postTypes$templateTypes$viewportWidth$inserter$synced\n";
		$metadata .= " */\n";
		$metadata .= "?>\n";
		return $metadata;
	}

	/**
	 * Exports pattern image assets from the theme directory to the WordPress media library.
	 *
	 * Used when converting a theme pattern to a user pattern.
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
		$upload_image = function ( $url ) use ( $home_url ) {

			// Skip if the asset isn't an image.
			if ( ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url ) ) {
				return new WP_Error(
					'invalid_image_type',
					__( 'Asset is not a valid image type.', 'pattern-builder' ),
					array( 'url' => $url )
				);
			}

			$download_file = false;

			// Convert the URL to a local file path.
			$file_path = str_replace( $home_url, ABSPATH, $url );
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
				// Try again with port 80 if we're inside a Docker container on localhost.
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

			// Return existing URL if the file is already in uploads.
			if ( file_exists( $upload_file ) ) {
				return $upload_dir['url'] . '/' . basename( $upload_file );
			}

			// Move the downloaded file to the uploads directory.
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

		// Handle HTML attributes (src and href).
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

		// Handle JSON-encoded URLs.
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
	 * Imports pattern image assets from the media library into the theme's assets directory.
	 *
	 * Used when saving a theme pattern — downloads URLs pointing to home_url and
	 * stores them as static theme assets, replacing the URLs with PHP template tags.
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
			// Skip if the asset isn't an image.
			if ( ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url ) ) {
				return false;
			}

			$download_file = download_url( $url );

			if ( is_wp_error( $download_file ) ) {
				// Try again with port 80 if we're inside a Docker container on localhost.
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

		// Handle HTML attributes (src and href).
		$pattern->content = preg_replace_callback(
			'/(src|href)="(' . preg_quote( $home_url, '/' ) . '[^"]+)"/',
			function ( $matches ) use ( $download_and_save_image ) {
				$new_url = $download_and_save_image( $matches[2] );
				if ( $new_url ) {
					return $matches[1] . '="<?php echo get_stylesheet_directory_uri() . \'' . $new_url . '\'; ?>"';
				}
				return $matches[0];
			},
			$pattern->content
		);

		// Handle JSON-encoded URLs.
		$pattern->content = preg_replace_callback(
			'/"url"\s*:\s*"(' . preg_quote( $home_url, '/' ) . '[^"]+)"/',
			function ( $matches ) use ( $download_and_save_image ) {
				$new_url = $download_and_save_image( $matches[1] );
				if ( $new_url ) {
					return '"url":"<?php echo get_stylesheet_directory_uri() . \'' . $new_url . '\'; ?>"';
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
	 * This is a PHP port of the JavaScript formatBlockMarkup() function.
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
		// Add newlines before and after each comment.
		$block_markup = preg_replace_callback(
			'/<!--(.*?)-->/s',
			function ( $matches ) {
				$content = trim( $matches[1] );
				return "\n<!-- {$content} -->\n";
			},
			$block_markup
		);

		// Fix spacing for self-closing blocks.
		$block_markup = str_replace( '/ -->', '/-->', $block_markup );

		// Normalize multiple newlines into a single one.
		$block_markup = preg_replace( '/\n{2,}/', "\n", $block_markup );

		// Eliminate blank lines.
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
		$lines        = explode( "\n", $block_markup );
		$lines        = array_map( 'trim', $lines );
		$indent_str   = '  ';
		$indent_level = 0;
		$output       = array();

		foreach ( $lines as $line ) {
			// Detect closing tags/comments — reduce indent before rendering.
			$is_closing_comment = preg_match( '/^<!--\s*\/[\w:-]+\s*-->$/', $line );
			$is_closing_tag     = preg_match( '/^<\/[\w:-]+>$/', $line );

			if ( $is_closing_comment || $is_closing_tag ) {
				$indent_level = max( $indent_level - 1, 0 );
			}

			$output[] = str_repeat( $indent_str, $indent_level ) . $line;

			// Detect opening comment (not self-closing).
			$is_opening_comment = preg_match( '/^<!--\s*[\w:-]+\b.*-->$/', $line ) &&
				! preg_match( '/\/\s*-->$/', $line );

			// Detect opening tag (not self-closing).
			$is_opening_tag = preg_match( '/^<([\w:-]+)(\s[^>]*)?>$/', $line );

			// Self-closing HTML tag.
			$is_self_closing_tag = preg_match( '/^<[^>]+\/>$/', $line );

			// Self-closing block markup.
			$is_self_closing_comment = preg_match( '/^<!--.*\/\s*-->$/', $line );

			if ( ( $is_opening_comment || $is_opening_tag ) && ! $is_self_closing_tag && ! $is_self_closing_comment ) {
				++$indent_level;
			}
		}

		return implode( "\n", $output );
	}
}
