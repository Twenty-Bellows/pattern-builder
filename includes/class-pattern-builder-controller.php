<?php

namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_Query;

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-builder-localization.php';
require_once __DIR__ . '/class-pattern-builder-security.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

class Pattern_Builder_Controller {

	public function format_pattern_slug_for_post( $slug ) {
		$new_slug = str_replace( '/', '-x-x-', $slug );
		return $new_slug;
	}

	public static function format_pattern_slug_from_post( $slug ) {
		$new_slug = str_replace( '-x-x-', '/', $slug );
		return $new_slug;
	}

	/**
	 * Get tbell_pattern_block post for a pattern with proper sanitization and caching.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return WP_Post|null The pattern post or null if not found.
	 */
	public function get_tbell_pattern_block_post_for_pattern( $pattern ) {
		// Sanitize the pattern name for safe database usage
		$sanitized_name = sanitize_title_with_dashes( $pattern->name );
		$sanitized_name = wp_strip_all_tags( $sanitized_name );
		$path           = $this->format_pattern_slug_for_post( $sanitized_name );

		// Create cache key for this specific pattern
		$cache_key    = 'tbell_pattern_post_' . md5( $sanitized_name );
		$pattern_post = get_transient( $cache_key );

		if ( false === $pattern_post ) {
			// Use WP_Query for better performance and security
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

			// Cache the result for 1 hour
			set_transient( $cache_key, $pattern_post, HOUR_IN_SECONDS );

			// Clean up
			wp_reset_postdata();
		}

		if ( $pattern_post ) {
			$pattern_post->post_name = $pattern->name;
			return $pattern_post;
		}

		return $this->create_tbell_pattern_block_post_for_pattern( $pattern );
	}

	/**
	 * Secure alternative to get_page_by_path with proper sanitization and caching.
	 *
	 * @param string       $page_path  The page path to search for.
	 * @param string       $output     Optional. Output type. OBJECT, ARRAY_N, or ARRAY_A.
	 * @param string|array $post_type  Optional. Post type or types to search.
	 * @return WP_Post|array|null The page object or null if not found.
	 */
	private function get_page_by_path_secure( $page_path, $output = OBJECT, $post_type = 'page' ) {
		// Sanitize the page path
		$sanitized_path = sanitize_title_with_dashes( $page_path );
		$sanitized_path = wp_strip_all_tags( $sanitized_path );

		// Ensure post_type is safe
		$post_types = is_array( $post_type ) ? $post_type : array( $post_type );
		$post_types = array_map( 'sanitize_key', $post_types );

		// Create cache key
		$cache_key   = 'page_by_path_' . md5( $sanitized_path . serialize( $post_types ) );
		$cached_post = get_transient( $cache_key );

		if ( false === $cached_post ) {
			// Use WP_Query instead of direct get_page_by_path
			$query = new WP_Query(
				array(
					'name'                   => $sanitized_path,
					'post_type'              => $post_types,
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			$cached_post = $query->have_posts() ? $query->posts[0] : null;

			// Cache for 30 minutes (shorter than pattern cache due to potentially more frequent changes)
			set_transient( $cache_key, $cached_post, 30 * MINUTE_IN_SECONDS );

			// Clean up
			wp_reset_postdata();
		}

		if ( $cached_post && $output === OBJECT ) {
			return $cached_post;
		} elseif ( $cached_post && $output === ARRAY_A ) {
			return get_object_vars( $cached_post );
		} elseif ( $cached_post && $output === ARRAY_N ) {
			return array_values( get_object_vars( $cached_post ) );
		}

		return null;
	}

	/**
	 * Invalidate pattern-related caches when patterns are modified.
	 *
	 * @param string $pattern_name The pattern name that was modified.
	 */
	private function invalidate_pattern_cache( $pattern_name ) {
		// Sanitize the pattern name
		$sanitized_name = sanitize_title_with_dashes( $pattern_name );
		$sanitized_name = wp_strip_all_tags( $sanitized_name );

		// Delete relevant transients
		$cache_keys = array(
			'tbell_pattern_post_' . md5( $sanitized_name ),
			'page_by_path_' . md5( $sanitized_name . serialize( array( 'wp_block' ) ) ),
			'page_by_path_' . md5( $sanitized_name . serialize( array( 'tbell_pattern_block' ) ) ),
			'page_by_path_' . md5( $this->format_pattern_slug_for_post( $sanitized_name ) . serialize( array( 'tbell_pattern_block' ) ) ),
		);

		foreach ( $cache_keys as $key ) {
			delete_transient( $key );
		}
	}

	public function create_tbell_pattern_block_post_for_pattern( $pattern ) {
		$existing_post = $this->get_page_by_path_secure( $this->format_pattern_slug_for_post( $pattern->name ), OBJECT, array( 'tbell_pattern_block' ) );

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

		if ( $pattern->inserter === false ) {
			$meta['wp_pattern_inserter'] = 'no';
		} else {
			delete_post_meta( $post_id, 'wp_pattern_inserter' );
		}
		if ( ! $post_id ) {

			$post_id = wp_insert_post(
				array(
					'post_title'     => $pattern->title,
					'post_name'      => $this->format_pattern_slug_for_post( $pattern->name ),
					'post_content'   => $pattern->content,
					'post_excerpt'   => $pattern->description,
					'post_type'      => 'tbell_pattern_block',
					'post_status'    => 'publish',
					'ping_status'    => 'closed',
					'comment_status' => 'closed',
					'meta_input'     => $meta,
				),
				true
			);

		} else {

			$post_id = wp_insert_post(
				array(
					'ID'             => $post_id,
					'post_title'     => $pattern->title,
					'post_name'      => $this->format_pattern_slug_for_post( $pattern->name ),
					'post_content'   => $pattern->content,
					'post_excerpt'   => $pattern->description,
					'post_type'      => 'tbell_pattern_block',
					'post_status'    => 'publish',
					'ping_status'    => 'closed',
					'comment_status' => 'closed',
					'meta_input'     => $meta,
				),
				true
			);

		}

		// store categories
		wp_set_object_terms( $post_id, $pattern->categories, 'wp_pattern_category', false );

		// return the post by post id
		$post            = get_post( $post_id );
		$post->post_name = $pattern->name;

		return $post;
	}

	public function update_theme_pattern( Abstract_Pattern $pattern, $options = array() ) {
		// Check if user has permission to modify theme patterns
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to modify theme patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		// get the tbell_pattern_block post if it already exists
		$post = $this->get_page_by_path_secure( $this->format_pattern_slug_for_post( $pattern->name ), OBJECT, array( 'tbell_pattern_block', 'wp_block' ) );

		if ( $post && $post->post_type === 'wp_block' ) {
			// this is being converted to theme patterns, change the slug to include the theme domain
			$pattern->name = get_stylesheet() . '/' . $pattern->name;
		}

		// Check if image importing is enabled (default to true for backward compatibility)
		if ( ! isset( $options['import_images'] ) || $options['import_images'] === true ) {
			$pattern = $this->import_pattern_image_assets( $pattern );
		}

		// Check if localization is enabled
		if ( isset( $options['localize'] ) && $options['localize'] === true ) {
			$pattern = Pattern_Builder_Localization::localize_pattern_content( $pattern );
		}

		// update the pattern file
		$this->update_theme_pattern_file( $pattern );

		// rebuild the pattern from the file (so that the content has no PHP tags)
		$filepath = $this->get_pattern_filepath( $pattern );
		if ( $filepath ) {
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

		// store categories
		wp_set_object_terms( $post_id, $pattern->categories, 'wp_pattern_category', false );

		// Invalidate cache for this pattern
		$this->invalidate_pattern_cache( $pattern->name );

		return $pattern;
	}

	private function export_pattern_image_assets( $pattern ) {

		$home_url = home_url();

		// Helper function to download and save image
		$upload_image = function ( $url ) use ( $home_url ) {

			// skip if the asset isn't an image
			if ( ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url ) ) {
				return \Pattern_Builder_Security::create_error(
					'invalid_image_type',
					__( 'Asset is not a valid image type.', 'pattern-builder' ),
					array( 'url' => $url ),
					__METHOD__,
					false // Don't log this as it's expected behavior
				);
			}

			$download_file = false;

			// convert the URL to a local file path
			$file_path = str_replace( $home_url, ABSPATH, $url );
			if ( file_exists( $file_path ) ) {

				$temp_file = wp_tempnam( basename( $file_path ) );

				// copy the image to a temporary location
				if ( copy( $file_path, $temp_file ) ) {
					$download_file = $temp_file;
				}
			}

			if ( ! $download_file ) {
				$download_file = download_url( $url );
			}

			if ( is_wp_error( $download_file ) ) {
				// we're going to try again with a new URL
				// we might be running this in a docker container
				// and if that's the case let's try again on port 80
				$parsed_url = wp_parse_url( $url );
				if ( 'localhost' === $parsed_url['host'] && '80' !== $parsed_url['port'] ) {
					$download_file = download_url( str_replace( 'localhost:' . $parsed_url['port'], 'localhost:80', $url ) );
				}
			}

			if ( is_wp_error( $download_file ) ) {
				return \Pattern_Builder_Security::create_error(
					'image_download_failed',
					__( 'Failed to download image asset.', 'pattern-builder' ),
					array(
						'url'   => $url,
						'error' => $download_file->get_error_message(),
					),
					__METHOD__
				);
			}

			// upload to the media library
			$upload_dir = wp_upload_dir();
			if ( ! is_dir( $upload_dir['path'] ) ) {
				wp_mkdir_p( $upload_dir['path'] );
			}

			$upload_file = $upload_dir['path'] . '/' . basename( $url );

			// check to see if the file is already in the uploads directory
			if ( file_exists( $upload_file ) ) {
				$uploaded_file_url = $upload_dir['url'] . '/' . basename( $upload_file );
				return $uploaded_file_url;
			}

			// Move the downloaded file to the uploads directory
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				WP_Filesystem();
			}
			if ( ! $wp_filesystem->move( $download_file, $upload_file ) ) {
				return \Pattern_Builder_Security::create_error(
					'file_move_failed',
					__( 'Failed to move image file to uploads directory.', 'pattern-builder' ),
					array(
						'source'      => $download_file,
						'destination' => $upload_file,
					),
					__METHOD__
				);
			}

			// Get the file type and create an attachment
			$filetype   = wp_check_filetype( basename( $upload_file ), null );
			$attachment = array(
				'guid'           => $upload_dir['url'] . '/' . basename( $upload_file ),
				'post_mime_type' => $filetype['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $upload_file ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			// Insert the attachment into the media library
			$attachment_id = wp_insert_attachment( $attachment, $upload_file );
			if ( is_wp_error( $attachment_id ) ) {
				return \Pattern_Builder_Security::create_error(
					'attachment_insert_failed',
					__( 'Failed to create media library attachment.', 'pattern-builder' ),
					array(
						'file'  => $upload_file,
						'error' => $attachment_id->get_error_message(),
					),
					__METHOD__
				);
			}

			// Generate attachment metadata and update the attachment
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload_file );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			$url = wp_get_attachment_url( $attachment_id );

			return $url;
		};

		// First, handle HTML attributes (src and href)
		$pattern->content = preg_replace_callback(
			'/(src|href)="(' . preg_quote( $home_url, '/' ) . '[^"]+)"/',
			function ( $matches ) use ( $upload_image ) {
				$new_url = $upload_image( $matches[2] );
				if ( $new_url && ! is_wp_error( $new_url ) ) {
					return $matches[1] . '="' . $new_url . '"';
				}
				// Log error if image upload failed, but don't break the pattern
				if ( is_wp_error( $new_url ) ) {
					\Pattern_Builder_Security::log_error(
						'Image upload failed during pattern import: ' . $new_url->get_error_message(),
						'import_pattern_image_assets',
						array( 'url' => $matches[2] )
					);
				}
				return $matches[0];
			},
			$pattern->content
		);

		// Second, handle JSON-encoded URLs
		$pattern->content = preg_replace_callback(
			'/"url"\s*:\s*"(' . preg_quote( $home_url, '/' ) . '[^"]+)"/',
			function ( $matches ) use ( $upload_image ) {
				$url     = $matches[1];
				$new_url = $upload_image( $url );
				if ( $new_url && ! is_wp_error( $new_url ) ) {
					return '"url":"' . $new_url . '"';
				}
				// Log error if image upload failed, but don't break the pattern
				if ( is_wp_error( $new_url ) ) {
					\Pattern_Builder_Security::log_error(
						'JSON URL image upload failed during pattern import: ' . $new_url->get_error_message(),
						'import_pattern_image_assets',
						array( 'url' => $url )
					);
				}
				return $matches[0];
			},
			$pattern->content
		);

		return $pattern;
	}

	/**
	 * Import image assets for a pattern into the media library.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return Abstract_Pattern Updated pattern object with new asset URLs.
	 */
	private function import_pattern_image_assets( $pattern ) {

		$home_url = home_url();

		// Helper function to download and save image
		$download_and_save_image = function ( $url ) use ( $home_url ) {
			// continue if the asset isn't an image
			if ( ! preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url ) ) {
				return false;
			}

			$download_file = download_url( $url );

			if ( is_wp_error( $download_file ) ) {
				// we're going to try again with a new URL
				// we might be running this in a docker container
				// and if that's the case let's try again on port 80
				$parsed_url = wp_parse_url( $url );
				if ( 'localhost' === $parsed_url['host'] && '80' !== $parsed_url['port'] ) {
					$download_file = download_url( str_replace( 'localhost:' . $parsed_url['port'], 'localhost:80', $url ) );
				}
			}

			if ( is_wp_error( $download_file ) ) {
				return false;
			}

			$filename         = \Pattern_Builder_Security::sanitize_filename( basename( $url ) );
			$asset_dir        = get_stylesheet_directory() . '/assets/images/';
			$destination_path = $asset_dir . $filename;

			// Validate destination path
			$validation = \Pattern_Builder_Security::validate_asset_path( $destination_path );
			if ( is_wp_error( $validation ) ) {
				// Clean up the temp file and return false
				if ( file_exists( $download_file ) ) {
					wp_delete_file( $download_file );
				}
				return false;
			}

			if ( ! is_dir( $asset_dir ) ) {
				wp_mkdir_p( $asset_dir );
			}

			// Use secure file move operation
			$allowed_dirs = array(
				get_stylesheet_directory() . '/assets',
				get_template_directory() . '/assets',
			);
			$result       = \Pattern_Builder_Security::safe_file_move( $download_file, $destination_path, $allowed_dirs );

			if ( is_wp_error( $result ) ) {
				// Clean up the temp file if move failed
				if ( file_exists( $download_file ) ) {
					wp_delete_file( $download_file );
				}
				return false;
			}

			return '/assets/images/' . $filename;
		};

		// First, handle HTML attributes (src and href)
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

		// Second, handle JSON-encoded URLs
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
	 * Updates a user pattern.
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @return Abstract_Pattern|WP_Error
	 */
	public function update_user_pattern( Abstract_Pattern $pattern ) {
		// Check if user has permission to edit pattern blocks
		if ( ! current_user_can( 'edit_tbell_pattern_blocks' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to modify patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}
		$post                       = $this->get_page_by_path_secure( $pattern->name, OBJECT, 'wp_block' );
		$convert_from_theme_pattern = false;

		if ( empty( $post ) ) {
			// check if the pattern exists in the database as a tbell_pattern_block post
			// this is for any user patterns that are being converted from theme patterns
			// It will be converted to a wp_block post when it is updated
			$slug                       = $this->format_pattern_slug_for_post( $pattern->name );
			$post                       = $this->get_page_by_path_secure( $slug, OBJECT, 'tbell_pattern_block' );
			$convert_from_theme_pattern = true;
		}

		// upload any assets from the theme
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

		// ensure the 'synced' meta key is set
		if ( $pattern->synced ) {
			delete_post_meta( $post_id, 'wp_pattern_sync_status' );
		} else {
			update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
		}

		// store categories
		wp_set_object_terms( $post_id, $pattern->categories, 'wp_pattern_category', false );

		// if we are converting a theme pattern to a user pattern delete the theme pattern file
		if ( $convert_from_theme_pattern ) {
			$path = $this->get_pattern_filepath( $pattern );
			if ( $path ) {
				// Validate that the path is within the patterns directory
				$validation = \Pattern_Builder_Security::validate_pattern_path( $path );
				if ( ! is_wp_error( $validation ) ) {
					// Use secure file delete operation
					$allowed_dirs = array(
						get_stylesheet_directory() . '/patterns',
						get_template_directory() . '/patterns',
					);
					$deleted      = \Pattern_Builder_Security::safe_file_delete( $path, $allowed_dirs );

					// Log if deletion failed but don't break the conversion
					if ( is_wp_error( $deleted ) ) {
						\Pattern_Builder_Security::log_error(
							'Failed to delete theme pattern file during conversion: ' . $deleted->get_error_message(),
							__METHOD__,
							array( 'path' => $path )
						);
					}
				}
			}
		}

		// Invalidate cache for this pattern
		$this->invalidate_pattern_cache( $pattern->name );

		return $pattern;
	}

	public function get_block_patterns_from_theme_files() {
		$pattern_files = glob( get_stylesheet_directory() . '/patterns/*.php' );
		$patterns      = array();

		foreach ( $pattern_files as $pattern_file ) {
			// Validate each pattern file path
			$validation = \Pattern_Builder_Security::validate_pattern_path( $pattern_file );
			if ( ! is_wp_error( $validation ) ) {
				$pattern    = Abstract_Pattern::from_file( $pattern_file );
				$patterns[] = $pattern;
			}
		}

		return $patterns;
	}

	public function get_block_patterns_from_database(): array {
		$query    = new WP_Query( array( 'post_type' => 'wp_block' ) );
		$patterns = array();

		foreach ( $query->posts as $post ) {
			$patterns[] = Abstract_Pattern::from_post( $post );
		}

		return $patterns;
	}

	public function delete_user_pattern( Abstract_Pattern $pattern ) {
		// Check if user has permission to delete pattern blocks
		if ( ! current_user_can( 'delete_tbell_pattern_blocks' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to delete patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		$post = $this->get_page_by_path_secure( $pattern->name, OBJECT, 'wp_block' );

		if ( empty( $post ) ) {
			return new WP_Error( 'pattern_not_found', 'Pattern not found', array( 'status' => 404 ) );
		}

		$deleted = wp_delete_post( $post->ID, true );

		if ( ! $deleted ) {
			return new WP_Error( 'pattern_delete_failed', 'Failed to delete pattern', array( 'status' => 500 ) );
		}

		return array( 'message' => 'Pattern deleted successfully' );
	}

	/**
	 * Get the file path for a pattern.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return string|WP_Error Pattern file path on success, WP_Error on failure.
	 */
	public function get_pattern_filepath( $pattern ) {
		$path = $pattern->filePath ?? get_stylesheet_directory() . '/patterns/' . \Pattern_Builder_Security::sanitize_filename( basename( $pattern->name ) );

		// Validate the path before checking existence
		$validation = \Pattern_Builder_Security::validate_pattern_path( $path );
		if ( is_wp_error( $validation ) ) {
			return \Pattern_Builder_Security::create_error(
				'invalid_pattern_path',
				__( 'Pattern file path validation failed.', 'pattern-builder' ),
				array( 'status' => 400 ),
				__METHOD__
			);
		}

		if ( file_exists( $path ) ) {
			return $path;
		}

		$patterns = $this->get_block_patterns_from_theme_files();
		$pattern  = array_find(
			$patterns,
			function ( $p ) use ( $pattern ) {
				return $p->name === $pattern->name;
			}
		);

		if ( $pattern && isset( $pattern->filePath ) ) {
			// Validate the found path as well
			$validation = \Pattern_Builder_Security::validate_pattern_path( $pattern->filePath );
			if ( ! is_wp_error( $validation ) && file_exists( $pattern->filePath ) ) {
				return $pattern->filePath;
			}
		}

		return \Pattern_Builder_Security::create_error(
			'pattern_file_not_found',
			__( 'Pattern file not found.', 'pattern-builder' ),
			array( 'status' => 404 ),
			__METHOD__
		);
	}

	public function delete_theme_pattern( Abstract_Pattern $pattern ) {
		// Check if user has permission to modify theme patterns
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to delete theme patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		$path = $this->get_pattern_filepath( $pattern );

		if ( is_wp_error( $path ) ) {
			return $path; // Return the error from get_pattern_filepath
		}

		// Validate that the path is within the patterns directory
		$validation = \Pattern_Builder_Security::validate_pattern_path( $path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

			// Use secure file delete operation
			$allowed_dirs = array(
				get_stylesheet_directory() . '/patterns',
				get_template_directory() . '/patterns',
			);
			$deleted      = \Pattern_Builder_Security::safe_file_delete( $path, $allowed_dirs );

			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}

			$tbell_pattern_block_post = $this->get_tbell_pattern_block_post_for_pattern( $pattern );
			$deleted                  = wp_delete_post( $tbell_pattern_block_post->ID, true );

			if ( ! $deleted ) {
				return new WP_Error( 'pattern_delete_failed', 'Failed to delete pattern', array( 'status' => 500 ) );
			}

			return array( 'message' => 'Pattern deleted successfully' );
	}

	public function update_theme_pattern_file( Abstract_Pattern $pattern ) {
		$path = $this->get_pattern_filepath( $pattern );

		// If get_pattern_filepath returns an error, create a new path
		if ( is_wp_error( $path ) ) {
			$filename = \Pattern_Builder_Security::sanitize_filename( basename( $pattern->name ) );
			$path     = get_stylesheet_directory() . '/patterns/' . $filename;
		}

		// Validate that the path is within the patterns directory
		$validation = \Pattern_Builder_Security::validate_pattern_path( $path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$formatted_content = $this->format_block_markup( $pattern->content );
		$file_content      = $this->build_pattern_file_metadata( $pattern ) . $formatted_content;

		// Use secure file write operation
		$allowed_dirs = array(
			get_stylesheet_directory() . '/patterns',
			get_template_directory() . '/patterns',
		);
		$response     = \Pattern_Builder_Security::safe_file_write( $path, $file_content, $allowed_dirs );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $pattern;
	}

	/**
	 * Builds metadata for a pattern file.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return string
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
	 * @param Abstract_Pattern $pattern The pattern to remap.
	 * @return Abstract_Pattern
	 */
	public function remap_patterns( Abstract_Pattern $pattern ) {
		// if this pattern's content contains wp:block blocks and they reference
		// theme patterns, remap them to wp:pattern blocks.

		$pattern->content = preg_replace_callback(
			'/wp:block\s+({.*})\s*\/?-->/sU',
			function ( $matches ) use ( $pattern ) {

				$attributes = json_decode( $matches[1], true );

				if ( isset( $attributes['ref'] ) ) {

					// get the post of the pattern
					$pattern_post = get_post( $attributes['ref'], OBJECT );

					// if the post is a tbell_pattern_block post, we can convert it to a wp:pattern block
					if ( $pattern_post && $pattern_post->post_type === 'tbell_pattern_block' ) {

						$pattern_slug = $pattern_post->post_name;

						// TODO: Optimize this
						// NOTE: Because the name of the post is the slug, but the slug has /'s removed, we have to find the ACTUALY slug from the file.
						$all_patterns = $this->get_block_patterns_from_theme_files();
						$pattern      = array_find(
							$all_patterns,
							function ( $p ) use ( $pattern_slug ) {
								return sanitize_title( $p->name ) === sanitize_title( $pattern_slug );
							}
						);

						if ( $pattern ) {

							unset( $attributes['ref'] );
							$attributes['slug'] = $pattern->name;

							return 'wp:pattern ' . json_encode( $attributes, JSON_UNESCAPED_SLASHES ) . ' /-->';
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
	 * Formats block markup to be nicely readable.
	 * This is a PHP port of the JavaScript formatBlockMarkup() function.
	 *
	 * @param string $block_markup The block markup to format.
	 * @return string The formatted block markup.
	 */
	public function format_block_markup( $block_markup ) {
		$block_markup = $this->add_new_lines_to_block_markup( $block_markup );
		$block_markup = $this->indent_block_markup( $block_markup );
		return trim( $block_markup );
	}

	/**
	 * Adds new lines to block markup for better readability.
	 *
	 * @param string $block_markup The block markup to add new lines to.
	 * @return string The block markup with new lines added.
	 */
	private function add_new_lines_to_block_markup( $block_markup ) {
		// Add newlines before and after each comment
		$block_markup = preg_replace_callback(
			'/<!--(.*?)-->/s',
			function ( $matches ) {
				$content = trim( $matches[1] );
				return "\n<!-- {$content} -->\n";
			},
			$block_markup
		);

		// Fix spacing for self-closing blocks
		$block_markup = str_replace( '/ -->', '/-->', $block_markup );

		// Normalize multiple newlines into a single one
		$block_markup = preg_replace( '/\n{2,}/', "\n", $block_markup );

		// eliminate blank lines
		$block_markup = preg_replace( '/^\s*[\r\n]/m', '', $block_markup );

		return $block_markup;
	}

	/**
	 * Indents block markup for better readability.
	 *
	 * @param string $block_markup The block markup to indent.
	 * @return string The indented block markup.
	 */
	private function indent_block_markup( $block_markup ) {
		$lines        = explode( "\n", $block_markup );
		$lines        = array_map( 'trim', $lines );
		$indent_str   = '  ';
		$indent_level = 0;
		$output       = array();

		foreach ( $lines as $line ) {
			// Detect closing tags/comments (should reduce indent before rendering)
			$is_closing_comment = preg_match( '/^<!--\s*\/[\w:-]+\s*-->$/', $line );
			$is_closing_tag     = preg_match( '/^<\/[\w:-]+>$/', $line );

			if ( $is_closing_comment || $is_closing_tag ) {
				$indent_level = max( $indent_level - 1, 0 );
			}

			$output[] = str_repeat( $indent_str, $indent_level ) . $line;

			// Detect opening comment (not self-closing)
			$is_opening_comment = preg_match( '/^<!--\s*[\w:-]+\b.*-->$/', $line ) &&
				! preg_match( '/\/\s*-->$/', $line );

			// Detect opening tag (not self-closing)
			$is_opening_tag = preg_match( '/^<([\w:-]+)(\s[^>]*)?>$/', $line );

			// Self-closing HTML tag
			$is_self_closing_tag = preg_match( '/^<[^>]+\/>$/', $line );

			// Self-closing block markup
			$is_self_closing_comment = preg_match( '/^<!--.*\/\s*-->$/', $line );

			if ( ( $is_opening_comment || $is_opening_tag ) && ! $is_self_closing_tag && ! $is_self_closing_comment ) {
				++$indent_level;
			}
		}

		return implode( "\n", $output );
	}
}
