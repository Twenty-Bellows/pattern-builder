<?php

namespace TwentyBellows\PatternBuilder;

use WP_Error;

/**
 * Converts between local patterns (theme files / wp_block posts) and the
 * Portable Pattern Package (PBP) wire format used by patternbuilderwp.com.
 *
 * Export: local pattern → PBP + asset files (local image URLs become
 * `pbp-asset://{key}` placeholders; the files travel with the upload).
 *
 * Import: PBP → local pattern. Assets are fetched from the service into the
 * media library first; a theme-destination pattern then flows through
 * Pattern_File_Store::update_theme_pattern(), which moves home-URL images
 * into theme assets exactly as user→theme conversion always has.
 */
class Pattern_Builder_Cloud_Porter {

	/**
	 * File store instance.
	 *
	 * @var Pattern_File_Store
	 */
	private $store;

	/**
	 * Set up the file store the porter reads and writes through.
	 */
	public function __construct() {
		$this->store = new Pattern_File_Store();
	}

	/**
	 * Export a local pattern to PBP form.
	 *
	 * @param string     $type 'theme' or 'user'.
	 * @param string|int $id   Theme pattern name or wp_block post ID.
	 * @return array|WP_Error { pbp: array, files: array (key => path), localKey: string }
	 */
	public function export_local( $type, $id ) {
		$pattern = $this->load_local( $type, $id );
		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}

		$content = (string) $pattern->content;
		$files   = array();
		$assets  = array();

		$collected = $this->collect_local_assets( $content );
		if ( is_wp_error( $collected ) ) {
			return $collected;
		}

		foreach ( $collected as $url => $info ) {
			$content = str_replace( $url, 'pbp-asset://' . $info['key'], $content );

			$files[ $info['key'] ]  = $info['path'];
			$assets[ $info['key'] ] = array(
				'key'      => $info['key'],
				'mime'     => $info['mime'],
				'filename' => basename( $info['path'] ),
			);
		}

		$slug = $pattern->name ? basename( (string) $pattern->name ) : sanitize_title( $pattern->title );

		$pbp = array(
			'format'        => 'pbp/1',
			'title'         => $pattern->title,
			'slug'          => $slug,
			'description'   => (string) $pattern->description,
			'keywords'      => array_values( (array) $pattern->keywords ),
			'categories'    => array_values( (array) $pattern->categories ),
			'viewportWidth' => (int) $pattern->viewportWidth, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'synced'        => (bool) $pattern->synced,
			'blockTypes'    => array_values( (array) $pattern->blockTypes ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'postTypes'     => array_values( (array) $pattern->postTypes ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'templateTypes' => array_values( (array) $pattern->templateTypes ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'content'       => $content,
			'assets'        => array_values( $assets ),
			'tokens'        => Pattern_Builder_Cloud_Tokens::collect( (string) $pattern->content ),
			'origin'        => array(
				'site' => home_url(),
				'kind' => $type,
			),
		);

		return array(
			'pbp'      => $pbp,
			'files'    => $files,
			'localKey' => self::local_key( $type, $id ),
		);
	}

	/**
	 * Import a PBP as a local pattern.
	 *
	 * @param array  $pbp         Package from the service.
	 * @param string $destination 'user' or 'theme'.
	 * @return array|WP_Error { type: string, id: string|int, title: string }
	 */
	public function import_pbp( $pbp, $destination ) {
		if ( ! is_array( $pbp ) || empty( $pbp['content'] ) || empty( $pbp['title'] ) ) {
			return new WP_Error( 'pb_cloud_bad_package', __( 'The downloaded pattern package is malformed.', 'pattern-builder' ), array( 'status' => 502 ) );
		}

		$content = (string) $pbp['content'];

		// 1. Fetch the package's assets into the media library.
		if ( ! empty( $pbp['assets'] ) && is_array( $pbp['assets'] ) ) {
			foreach ( $pbp['assets'] as $asset ) {
				if ( empty( $asset['key'] ) || empty( $asset['url'] ) ) {
					continue;
				}
				$local_url = $this->import_asset( $asset );
				if ( is_wp_error( $local_url ) ) {
					return $local_url;
				}
				$content = str_replace(
					array( 'pbp-asset://' . $asset['key'], 'pbp-asset:\/\/' . $asset['key'] ),
					array( $local_url, str_replace( '/', '\/', $local_url ) ),
					$content
				);
			}
		}

		if ( false !== strpos( $content, 'pbp-asset:' ) ) {
			return new WP_Error( 'pb_cloud_missing_asset', __( 'The downloaded pattern references an asset that was not delivered.', 'pattern-builder' ), array( 'status' => 502 ) );
		}

		// 2. Re-sanitize: never trust the wire, even our own service.
		$content = $this->sanitize_content( $content );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$title       = sanitize_text_field( (string) $pbp['title'] );
		$slug        = sanitize_title( ! empty( $pbp['slug'] ) ? (string) $pbp['slug'] : $title );
		$description = sanitize_textarea_field( isset( $pbp['description'] ) ? (string) $pbp['description'] : '' );
		$categories  = array_map( 'sanitize_text_field', isset( $pbp['categories'] ) && is_array( $pbp['categories'] ) ? $pbp['categories'] : array() );
		$synced      = ! empty( $pbp['synced'] );

		// 3. Land it as the requested local kind.
		if ( 'theme' === $destination ) {
			return $this->import_as_theme_pattern( $pbp, $title, $slug, $description, $categories, $synced, $content );
		}

		return $this->import_as_user_pattern( $title, $slug, $description, $categories, $synced, $content );
	}

	/**
	 * Build the link-map key for a local pattern.
	 *
	 * @param string     $type 'theme' or 'user'.
	 * @param string|int $id   Local identifier.
	 * @return string
	 */
	public static function local_key( $type, $id ) {
		return $type . ':' . $id;
	}

	/**
	 * Load a local pattern as an Abstract_Pattern.
	 *
	 * @param string     $type 'theme' or 'user'.
	 * @param string|int $id   Local identifier.
	 * @return Abstract_Pattern|WP_Error
	 */
	private function load_local( $type, $id ) {
		if ( 'theme' === $type ) {
			$pattern = $this->store->find_theme_pattern( (string) $id );
			if ( ! $pattern ) {
				return new WP_Error( 'pb_cloud_not_found', __( 'Theme pattern not found.', 'pattern-builder' ), array( 'status' => 404 ) );
			}
			return $pattern;
		}

		$post = get_post( (int) $id );
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return new WP_Error( 'pb_cloud_not_found', __( 'User pattern not found.', 'pattern-builder' ), array( 'status' => 404 ) );
		}
		return Abstract_Pattern::from_post( $post );
	}

	/**
	 * Find every local image URL in content and resolve it to a file on disk.
	 *
	 * @param string $content Block markup.
	 * @return array|WP_Error url => { key, path, mime }
	 */
	private function collect_local_assets( $content ) {
		$home = untrailingslashit( home_url() );

		preg_match_all( '/(?:src|href)="(' . preg_quote( $home, '/' ) . '[^"]+\.(?:jpg|jpeg|png|gif|webp))"/i', $content, $attr_matches );
		preg_match_all( '/"url"\s*:\s*"(' . preg_quote( $home, '/' ) . '[^"]+\.(?:jpg|jpeg|png|gif|webp))"/i', $content, $json_matches );

		$urls   = array_unique( array_merge( $attr_matches[1], $json_matches[1] ) );
		$assets = array();

		foreach ( $urls as $url ) {
			$path = $this->url_to_path( $url );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$mime = wp_check_filetype( basename( $path ) )['type'];
			$name = sanitize_key( preg_replace( '/\.[^.]+$/', '', basename( $path ) ) );
			$name = preg_replace( '/[^a-z0-9_\-]/', '', str_replace( '.', '-', $name ) );
			$key  = substr( $name, 0, 60 ) . '-' . substr( md5( $url ), 0, 6 );

			$assets[ $url ] = array(
				'key'  => $key,
				'path' => $path,
				'mime' => $mime ? $mime : 'image/png',
			);
		}

		return $assets;
	}

	/**
	 * Map a local URL to its file on disk (uploads or theme directories).
	 *
	 * @param string $url Local URL.
	 * @return string|WP_Error
	 */
	private function url_to_path( $url ) {
		$candidates = array();

		$uploads = wp_get_upload_dir();
		if ( 0 === strpos( $url, $uploads['baseurl'] ) ) {
			$candidates[] = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );
		}
		if ( 0 === strpos( $url, get_stylesheet_directory_uri() ) ) {
			$candidates[] = str_replace( get_stylesheet_directory_uri(), get_stylesheet_directory(), $url );
		}
		if ( 0 === strpos( $url, get_template_directory_uri() ) ) {
			$candidates[] = str_replace( get_template_directory_uri(), get_template_directory(), $url );
		}

		foreach ( $candidates as $candidate ) {
			$candidate = strtok( $candidate, '?' );
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return new WP_Error(
			'pb_cloud_unresolvable_asset',
			sprintf(
				/* translators: %s: image URL. */
				__( 'The pattern references an image that could not be found on this site: %s', 'pattern-builder' ),
				$url
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Fetch one package asset from the service into the media library.
	 *
	 * @param array $asset Asset entry (key, url, filename, mime).
	 * @return string|WP_Error The local attachment URL.
	 */
	private function import_asset( $asset ) {
		$url = (string) $asset['url'];

		/**
		 * Pre-empt the remote fetch (tests, mirrors). Return a readable file
		 * path to use instead of downloading.
		 *
		 * @param string|null $path  Local file path or null.
		 * @param array       $asset Asset entry.
		 */
		$pre = apply_filters( 'pattern_builder_cloud_pre_fetch_asset', null, $asset );

		if ( null === $pre ) {
			$service_host = wp_parse_url( Pattern_Builder_Cloud::service_url(), PHP_URL_HOST );
			$asset_host   = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! $asset_host || strtolower( $asset_host ) !== strtolower( (string) $service_host ) ) {
				return new WP_Error( 'pb_cloud_foreign_asset', __( 'The pattern package referenced an asset outside the pattern service.', 'pattern-builder' ), array( 'status' => 502 ) );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temp = download_url( $url, 60 );
			if ( is_wp_error( $temp ) ) {
				return new WP_Error( 'pb_cloud_asset_failed', __( 'Could not download a pattern image from the service.', 'pattern-builder' ), array( 'status' => 502 ) );
			}
		} else {
			$temp_copy = wp_tempnam( basename( $pre ) );
			copy( $pre, $temp_copy );
			$temp = $temp_copy;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = ! empty( $asset['filename'] ) ? sanitize_file_name( $asset['filename'] ) : sanitize_file_name( $asset['key'] . '.png' );

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $temp,
				'error'    => 0,
				'size'     => filesize( $temp ),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Defense-in-depth sanitization of downloaded markup.
	 *
	 * @param string $content Markup (local URLs already in place).
	 * @return string|WP_Error
	 */
	private function sanitize_content( $content ) {
		$decoded = html_entity_decode( $content, ENT_QUOTES );
		$folded  = strtolower( preg_replace( '/[\s\x00-\x1f]+/', '', $decoded ) );
		if ( false !== strpos( $folded, 'javascript:' ) || false !== strpos( $folded, 'vbscript:' ) ) {
			return new WP_Error( 'pb_cloud_unsafe_content', __( 'The downloaded pattern contained unsafe content and was rejected.', 'pattern-builder' ), array( 'status' => 502 ) );
		}

		return wp_kses_post( $content );
	}

	/**
	 * Land a package as a wp_block user pattern.
	 *
	 * @param string   $title       Title.
	 * @param string   $slug        Slug.
	 * @param string   $description Description.
	 * @param string[] $categories  Category names.
	 * @param bool     $synced      Synced flag.
	 * @param string   $content     Sanitized markup with local URLs.
	 * @return array|WP_Error
	 */
	private function import_as_user_pattern( $title, $slug, $description, $categories, $synced, $content ) {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => wp_slash( $content ),
				'post_excerpt' => $description,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $synced ) {
			delete_post_meta( $post_id, 'wp_pattern_sync_status' );
		} else {
			update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
		}

		if ( ! empty( $categories ) ) {
			wp_set_object_terms( $post_id, $categories, 'wp_pattern_category', false );
		}

		return array(
			'type'  => 'user',
			'id'    => $post_id,
			'title' => $title,
		);
	}

	/**
	 * Land a package as a theme pattern file.
	 *
	 * @param array    $pbp         Package (for viewport/keywords extras).
	 * @param string   $title       Title.
	 * @param string   $slug        Slug.
	 * @param string   $description Description.
	 * @param string[] $categories  Category names.
	 * @param bool     $synced      Synced flag.
	 * @param string   $content     Sanitized markup with local URLs.
	 * @return array|WP_Error
	 */
	private function import_as_theme_pattern( $pbp, $title, $slug, $description, $categories, $synced, $content ) {
		$pattern = new Abstract_Pattern(
			array(
				'id'            => get_stylesheet() . '/' . $slug,
				'name'          => get_stylesheet() . '/' . $slug,
				'title'         => $title,
				'description'   => $description,
				'content'       => $content,
				'categories'    => $categories,
				'keywords'      => isset( $pbp['keywords'] ) && is_array( $pbp['keywords'] ) ? array_map( 'sanitize_text_field', $pbp['keywords'] ) : array(),
				'viewportWidth' => isset( $pbp['viewportWidth'] ) ? absint( $pbp['viewportWidth'] ) : null,
				'synced'        => $synced,
				'inserter'      => true,
				'source'        => 'theme',
			)
		);

		$saved = $this->store->update_theme_pattern( $pattern );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'type'  => 'theme',
			'id'    => $saved->name,
			'title' => $saved->title,
		);
	}
}
