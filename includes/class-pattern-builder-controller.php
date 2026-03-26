<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- camelCase properties intentionally mirror the JS AbstractPattern class.

namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_Query;

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-builder-localization.php';
require_once __DIR__ . '/class-pattern-builder-security.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

class Pattern_Builder_Controller {

	/**
	 * Encodes a namespaced pattern slug for storage as a WordPress post_name.
	 *
	 * WordPress post_name does not support '/' — encode it as '-x-x-'.
	 *
	 * @param string $slug Pattern slug (e.g. 'my-theme/pattern-name').
	 * @return string Encoded slug safe for post_name storage.
	 */
	public function format_pattern_slug_for_post( $slug ) {
		return str_replace( '/', '-x-x-', $slug );
	}

	/**
	 * Decodes a post_name-encoded slug back to the original namespaced slug.
	 *
	 * @param string $slug Encoded slug (e.g. 'my-theme-x-x-pattern-name').
	 * @return string Decoded pattern slug.
	 */
	public static function format_pattern_slug_from_post( $slug ) {
		return str_replace( '-x-x-', '/', $slug );
	}

	/**
	 * Gets the tbell_pattern_block post for a pattern, creating it if it doesn't exist.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return \WP_Post|null The pattern post or null if not found.
	 */
	public function get_tbell_pattern_block_post_for_pattern( $pattern ) {
		$path = $this->format_pattern_slug_for_post( $pattern->name );

		$query = new WP_Query(
			array(
				'name'                   => sanitize_title( $path ),
				'post_type'              => 'tbell_pattern_block',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$pattern_post = $query->have_posts() ? $query->posts[0] : null;

		// Clean up after the query.
		wp_reset_postdata();

		if ( $pattern_post ) {
			$pattern_post->post_name = $pattern->name;
			return $pattern_post;
		}

		return $this->create_tbell_pattern_block_post_for_pattern( $pattern );
	}

	/**
	 * Creates or updates the tbell_pattern_block post that mirrors a theme pattern file.
	 *
	 * @param Abstract_Pattern $pattern The pattern to upsert.
	 * @return \WP_Post The created or updated post.
	 */
	public function create_tbell_pattern_block_post_for_pattern( $pattern ) {

		$existing_post = get_page_by_path( $this->format_pattern_slug_for_post( $pattern->name ), OBJECT, array( 'tbell_pattern_block' ) );

		$post_id = $existing_post ? $existing_post->ID : null;

		$meta = array();

		if ( ! $pattern->synced ) {
			$meta['wp_pattern_sync_status'] = 'unsynced';
		} else {
			delete_post_meta( $post_id, 'wp_pattern_sync_status' );
		}

		if ( $pattern->blockTypes ) {
			$meta['wp_pattern_block_types'] = implode( ',', $pattern->blockTypes );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_block_types' );
		}

		if ( $pattern->templateTypes ) {
			$meta['wp_pattern_template_types'] = implode( ',', $pattern->templateTypes );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_template_types' );
		}

		if ( $pattern->postTypes ) {
			$meta['wp_pattern_post_types'] = implode( ',', $pattern->postTypes );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_post_types' );
		}

		if ( $pattern->keywords ) {
			$meta['wp_pattern_keywords'] = implode( ',', $pattern->keywords );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_keywords' );
		}

		if ( false === $pattern->inserter ) {
			$meta['wp_pattern_inserter'] = 'no';
		} else {
			delete_post_meta( $post_id, 'wp_pattern_inserter' );
		}

		$post_data = array(
			'post_title'     => $pattern->title,
			'post_name'      => $this->format_pattern_slug_for_post( $pattern->name ),
			'post_content'   => $pattern->content,
			'post_excerpt'   => $pattern->description,
			'post_type'      => 'tbell_pattern_block',
			'post_status'    => 'publish',
			'ping_status'    => 'closed',
			'comment_status' => 'closed',
			'meta_input'     => $meta,
		);

		if ( $post_id ) {
			$post_data['ID'] = $post_id;
		}

		$post_id = wp_insert_post( $post_data, true );

		// Store categories.
		wp_set_object_terms( $post_id, $pattern->categories, 'wp_pattern_category', false );

		// Return the post by post ID.
		$post            = get_post( $post_id );
		$post->post_name = $pattern->name;

		return $post;
	}

	/**
	 * Updates a theme pattern — writes the PHP file and syncs the DB post.
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @param array            $options Optional settings: 'localize' (bool), 'import_images' (bool).
	 * @return Abstract_Pattern|WP_Error
	 */
	public function update_theme_pattern( Abstract_Pattern $pattern, $options = array() ) {
		// Check if user has permission to modify theme patterns.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to modify theme patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		$post = get_page_by_path( $this->format_pattern_slug_for_post( $pattern->name ), OBJECT, array( 'tbell_pattern_block', 'wp_block' ) );

		if ( $post && 'wp_block' === $post->post_type ) {
			// Being converted to a theme pattern; prefix the slug with the theme domain.
			$pattern->name = get_stylesheet() . '/' . $pattern->name;
		}

		// Import images unless explicitly disabled.
		if ( ! isset( $options['import_images'] ) || true === $options['import_images'] ) {
			$pattern = $this->import_pattern_image_assets( $pattern );
		}

		// Localize if enabled.
		if ( isset( $options['localize'] ) && true === $options['localize'] ) {
			$pattern = Pattern_Builder_Localization::localize_pattern_content( $pattern );
		}

		// Write the pattern file.
		$this->update_theme_pattern_file( $pattern );

		// Rebuild the pattern from the file (so that content has no PHP tags).
		$filepath = $this->get_pattern_filepath( $pattern );
		if ( ! is_wp_error( $filepath ) && $filepath ) {
			$pattern = Abstract_Pattern::from_file( $filepath );
		}

		$post_id = wp_update_post(
			array(
				'ID'           => $post ? $post->ID : null,
				'post_title'   => $pattern->title,
				'post_name'    => $this->format_pattern_slug_for_post( $pattern->name ),
				'post_excerpt' => $pattern->description,
				'post_content' => $pattern->content,
				'post_type'    => 'tbell_pattern_block',
			)
		);

		if ( $pattern->synced ) {
			delete_post_meta( $post_id, 'wp_pattern_sync_status' );
		} else {
			update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
		}

		if ( $pattern->keywords ) {
			update_post_meta( $post_id, 'wp_pattern_keywords', implode( ',', $pattern->keywords ) );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_keywords' );
		}

		if ( $pattern->blockTypes ) {
			update_post_meta( $post_id, 'wp_pattern_block_types', implode( ',', $pattern->blockTypes ) );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_block_types' );
		}

		if ( $pattern->templateTypes ) {
			update_post_meta( $post_id, 'wp_pattern_template_types', implode( ',', $pattern->templateTypes ) );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_template_types' );
		}

		if ( $pattern->postTypes ) {
			update_post_meta( $post_id, 'wp_pattern_post_types', implode( ',', $pattern->postTypes ) );
		} else {
			delete_post_meta( $post_id, 'wp_pattern_post_types' );
		}

		// Store categories.
		wp_set_object_terms( $post_id, $pattern->categories, 'wp_pattern_category', false );

		return $pattern;
	}

	/**
	 * Exports pattern image assets from the theme directory to the WordPress media library.
	 *
	 * Used when converting a theme pattern to a user pattern.
	 *
	 * @param Abstract_Pattern $pattern The pattern whose images should be exported.
	 * @return Abstract_Pattern Updated pattern with media library URLs.
	 */
	private function export_pattern_image_assets( $pattern ) {

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
	 * Updates a user pattern (wp_block post type).
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @return Abstract_Pattern|WP_Error
	 */
	public function update_user_pattern( Abstract_Pattern $pattern ) {
		// Check if user has permission to edit pattern blocks.
		if ( ! current_user_can( 'edit_tbell_pattern_blocks' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to modify patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		$post                       = get_page_by_path( $pattern->name, OBJECT, 'wp_block' );
		$convert_from_theme_pattern = false;

		if ( empty( $post ) ) {
			// Check if the pattern exists as a tbell_pattern_block; if so it's being converted.
			$slug                       = $this->format_pattern_slug_for_post( $pattern->name );
			$post                       = get_page_by_path( $slug, OBJECT, 'tbell_pattern_block' );
			$convert_from_theme_pattern = true;
		}

		// Export any theme assets to the media library.
		$pattern = $this->export_pattern_image_assets( $pattern );

		if ( empty( $post ) ) {
			$post_id = wp_insert_post(
				array(
					'post_title'   => $pattern->title,
					'post_name'    => basename( $pattern->name ),
					'post_content' => $pattern->content,
					'post_excerpt' => $pattern->description,
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
				)
			);
		} else {
			$post_id = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_title'   => $pattern->title,
					'post_name'    => basename( $pattern->name ),
					'post_content' => $pattern->content,
					'post_excerpt' => $pattern->description,
					'post_type'    => 'wp_block',
				)
			);
		}

		// Ensure the sync status meta key is accurate.
		if ( $pattern->synced ) {
			delete_post_meta( $post_id, 'wp_pattern_sync_status' );
		} else {
			update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
		}

		// Store categories.
		wp_set_object_terms( $post_id, $pattern->categories, 'wp_pattern_category', false );

		// If converting from a theme pattern, delete the theme pattern file.
		if ( $convert_from_theme_pattern ) {
			$path = $this->get_pattern_filepath( $pattern );
			if ( ! is_wp_error( $path ) && $path ) {
				Pattern_Builder_Security::safe_file_delete( $path );
			}
		}

		return $pattern;
	}

	/**
	 * Returns all patterns found as PHP files in the active theme's /patterns/ directory.
	 *
	 * @return Abstract_Pattern[]
	 */
	public function get_block_patterns_from_theme_files() {
		$pattern_files = glob( get_stylesheet_directory() . '/patterns/*.php' );
		$patterns      = array();

		foreach ( $pattern_files as $pattern_file ) {
			$pattern    = Abstract_Pattern::from_file( $pattern_file );
			$patterns[] = $pattern;
		}

		return $patterns;
	}

	/**
	 * Returns all user patterns (wp_block posts) from the database.
	 *
	 * @return Abstract_Pattern[]
	 */
	public function get_block_patterns_from_database(): array {
		$query    = new WP_Query( array( 'post_type' => 'wp_block' ) );
		$patterns = array();

		foreach ( $query->posts as $post ) {
			$patterns[] = Abstract_Pattern::from_post( $post );
		}

		return $patterns;
	}

	/**
	 * Deletes a user pattern (wp_block) from the database.
	 *
	 * @param Abstract_Pattern $pattern The pattern to delete.
	 * @return array|WP_Error Success message array or WP_Error on failure.
	 */
	public function delete_user_pattern( Abstract_Pattern $pattern ) {
		// Check if user has permission to delete pattern blocks.
		if ( ! current_user_can( 'delete_tbell_pattern_blocks' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to delete patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		$post = get_page_by_path( $pattern->name, OBJECT, 'wp_block' );

		if ( empty( $post ) ) {
			return new WP_Error( 'pattern_not_found', 'Pattern not found.', array( 'status' => 404 ) );
		}

		$deleted = wp_delete_post( $post->ID, true );

		if ( ! $deleted ) {
			return new WP_Error( 'pattern_delete_failed', 'Failed to delete pattern.', array( 'status' => 500 ) );
		}

		return array( 'message' => 'Pattern deleted successfully.' );
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

		$patterns        = $this->get_block_patterns_from_theme_files();
		$filtered        = array_filter(
			$patterns,
			function ( $p ) use ( $pattern ) {
				return $p->name === $pattern->name;
			}
		);
		$matched_pattern = reset( $filtered );

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
	 * Deletes a theme pattern — removes the PHP file and the tbell_pattern_block post.
	 *
	 * @param Abstract_Pattern $pattern The pattern to delete.
	 * @return array|WP_Error Success message array or WP_Error on failure.
	 */
	public function delete_theme_pattern( Abstract_Pattern $pattern ) {
		// Check if user has permission to modify theme patterns.
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

		$tbell_pattern_block_post = $this->get_tbell_pattern_block_post_for_pattern( $pattern );
		$deleted                  = wp_delete_post( $tbell_pattern_block_post->ID, true );

		if ( ! $deleted ) {
			return new WP_Error( 'pattern_delete_failed', 'Failed to delete pattern.', array( 'status' => 500 ) );
		}

		return array( 'message' => 'Pattern deleted successfully.' );
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
		$inserter      = $pattern->inserter ? '' : "\n * Inserter: no";
		$synced        = $pattern->synced ? "\n * Synced: yes" : '';

		$metadata  = "<?php\n";
		$metadata .= "/**\n";
		$metadata .= " * Title: $pattern->title\n";
		$metadata .= " * Slug: $pattern->name\n";
		$metadata .= " * Description: $pattern->description$categories$keywords$blockTypes$postTypes$templateTypes$inserter$synced\n";
		$metadata .= " */\n";
		$metadata .= "?>\n";
		return $metadata;
	}

	/**
	 * Remaps wp:block blocks that reference theme patterns to wp:pattern blocks.
	 *
	 * @param Abstract_Pattern $pattern The pattern whose content should be remapped.
	 * @return Abstract_Pattern
	 */
	public function remap_patterns( Abstract_Pattern $pattern ) {
		$pattern->content = preg_replace_callback(
			'/wp:block\s+({.*})\s*\/?-->/sU',
			function ( $matches ) use ( $pattern ) {

				$attributes = json_decode( $matches[1], true );

				if ( isset( $attributes['ref'] ) ) {

					$pattern_post = get_post( $attributes['ref'], OBJECT );

					if ( $pattern_post && 'tbell_pattern_block' === $pattern_post->post_type ) {

						$pattern_slug = $pattern_post->post_name;

						// TODO: Optimize this.
						// NOTE: Because the name of the post is the slug, but the slug has /'s removed,
						// we have to find the actual slug from the file.
						$all_patterns     = $this->get_block_patterns_from_theme_files();
						$filtered_matches = array_filter(
							$all_patterns,
							function ( $p ) use ( $pattern_slug ) {
								return sanitize_title( $p->name ) === sanitize_title( $pattern_slug );
							}
						);
						$matched          = reset( $filtered_matches );

						if ( $matched ) {
							unset( $attributes['ref'] );
							$attributes['slug'] = $matched->name;
							return 'wp:pattern ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) . ' /-->';
						}
					}
				}

				return 'wp:block ' . $matches[1] . ' /-->';
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
