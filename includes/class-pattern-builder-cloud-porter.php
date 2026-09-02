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
 * into theme assets exactly as user→theme conversion always has. A pattern
 * installed from a cloud collection lands under a local pattern category
 * named for that collection — its footprint on this site.
 *
 * Installing a whole collection is one method, install_collection(), used by
 * the REST route's caller and by the agent ability alike.
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
	 * @return array|WP_Error { pbp: array, files: array (key => path), localKey: string, contentHash: string }
	 */
	public function export_local( $type, $id ) {
		$pattern = $this->load_local( $type, $id );
		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}

		$content = (string) $pattern->content;
		$raw_md5 = md5( $content );
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

		$content = $this->strip_attachment_identity( $content );

		$slug = $pattern->name ? basename( (string) $pattern->name ) : sanitize_title( $pattern->title );

		$pbp = array(
			'format'             => 'pbp/1',
			'title'              => $pattern->title,
			'slug'               => $slug,
			'description'        => (string) $pattern->description,
			'keywords'           => array_values( (array) $pattern->keywords ),
			// The pattern file's own Categories: header travels along as
			// classification for the inserter. Which cloud collection the
			// pattern goes into is the request's business, not the package's.
			'inserterCategories' => array_values( (array) $pattern->categories ),
			'viewportWidth'      => (int) $pattern->viewportWidth, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'synced'             => (bool) $pattern->synced,
			'blockTypes'         => array_values( (array) $pattern->blockTypes ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'postTypes'          => array_values( (array) $pattern->postTypes ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'templateTypes'      => array_values( (array) $pattern->templateTypes ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'content'            => $content,
			'assets'             => array_values( $assets ),
			'tokens'             => Pattern_Builder_Cloud_Tokens::collect( (string) $pattern->content ),
			'origin'             => array(
				'site' => home_url(),
				'kind' => $type,
			),
		);

		return array(
			'pbp'         => $pbp,
			'files'       => $files,
			'localKey'    => self::local_key( $type, $id ),
			'contentHash' => $raw_md5,
		);
	}

	/**
	 * Hash of a local pattern's raw content — the "has it changed since
	 * upload?" fingerprint stored in the cloud-link map.
	 *
	 * @param string     $type 'theme' or 'user'.
	 * @param string|int $id   Local identifier.
	 * @return string|WP_Error
	 */
	public function content_hash( $type, $id ) {
		$pattern = $this->load_local( $type, $id );
		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}
		return md5( (string) $pattern->content );
	}

	/**
	 * A local pattern's identity, or null when it no longer exists — the
	 * liveness check behind "installed on this site".
	 *
	 * @param string     $type 'theme' or 'user'.
	 * @param string|int $id   Local identifier.
	 * @return array|null { type: string, id: string|int, title: string }
	 */
	public function describe_local( $type, $id ) {
		$pattern = $this->load_local( $type, $id );
		if ( is_wp_error( $pattern ) ) {
			return null;
		}

		return array(
			'type'  => $type,
			'id'    => $id,
			'title' => (string) $pattern->title,
		);
	}

	/**
	 * Import a PBP as a local pattern.
	 *
	 * @param array  $pbp         Package from the service.
	 * @param string $destination 'user' or 'theme'.
	 * @param array  $collection  The cloud collection it came from, as
	 *                            { owner, slug, title }, or empty. When
	 *                            given, the pattern lands under a local
	 *                            category named for the collection.
	 * @return array|WP_Error { type: string, id: string|int, title: string }
	 */
	public function import_pbp( $pbp, $destination, $collection = array() ) {
		if ( ! is_array( $pbp ) || empty( $pbp['content'] ) || empty( $pbp['title'] ) ) {
			return new WP_Error( 'pb_cloud_bad_package', __( 'The downloaded pattern package is malformed.', 'pattern-builder' ), array( 'status' => 502 ) );
		}

		$content = (string) $pbp['content'];

		$attachments = array();

		if ( ! empty( $pbp['assets'] ) && is_array( $pbp['assets'] ) ) {
			foreach ( $pbp['assets'] as $asset ) {
				if ( empty( $asset['key'] ) || empty( $asset['url'] ) ) {
					continue;
				}
				$imported = $this->import_asset( $asset );
				if ( is_wp_error( $imported ) ) {
					return $imported;
				}
				$local_url                 = $imported['url'];
				$attachments[ $local_url ] = $imported['id'];
				$content                   = str_replace(
					array( 'pbp-asset://' . $asset['key'], 'pbp-asset:\/\/' . $asset['key'] ),
					array( $local_url, str_replace( '/', '\/', $local_url ) ),
					$content
				);
			}
		}

		if ( false !== strpos( $content, 'pbp-asset:' ) ) {
			return new WP_Error( 'pb_cloud_missing_asset', __( 'The downloaded pattern references an asset that was not delivered.', 'pattern-builder' ), array( 'status' => 502 ) );
		}

		// Never trust the wire, even our own service.
		$content = $this->sanitize_content( $content );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$title       = sanitize_text_field( (string) $pbp['title'] );
		$slug        = sanitize_title( ! empty( $pbp['slug'] ) ? (string) $pbp['slug'] : $title );
		$description = sanitize_textarea_field( isset( $pbp['description'] ) ? (string) $pbp['description'] : '' );
		$categories  = array_map( 'sanitize_text_field', isset( $pbp['inserterCategories'] ) && is_array( $pbp['inserterCategories'] ) ? $pbp['inserterCategories'] : array() );
		$synced      = ! empty( $pbp['synced'] );

		/*
		 * The collection's footprint: a category slug `pbwp-{owner}-{slug}`
		 * on the installed pattern, and the collection's title remembered
		 * so the inserter shows "Starter Sections" rather than the slug.
		 */
		$collection = Pattern_Builder_Cloud::describe_collection( $collection );
		if ( $collection ) {
			$categories[] = Pattern_Builder_Cloud::collection_category_slug( $collection['owner'], $collection['slug'] );
			$categories   = array_values( array_unique( $categories ) );
			Pattern_Builder_Cloud::remember_collection_category( $collection );
		}

		if ( 'theme' === $destination ) {
			/*
			 * No attachments to name: a theme pattern's images are moved into
			 * the theme's own assets directory and referenced from there
			 * (Pattern_File_Store::update_theme_pattern), so the package's
			 * blocks stay as they arrived — an id would name nothing.
			 */
			return $this->import_as_theme_pattern( $pbp, $title, $this->install_name( $pbp, $slug ), $description, $categories, $synced, $content );
		}

		// A user pattern's images did land in the media library, so its blocks
		// can name them — the identity the export dropped, in local terms.
		$content = $this->attach_media_library_ids( $content, $attachments );

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
	 * The image types a package may carry — the service accepts these and
	 * nothing else (mirrors PBP::allowed_asset_mimes on the service).
	 *
	 * @return string[]
	 */
	private function bundleable_mimes() {
		return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	}

	/**
	 * Find every image the pattern points at and resolve it to a file on
	 * disk, so it can travel with the package as a `pbp-asset://` placeholder.
	 *
	 * The scan matches what the service checks — `src`, a block attribute's
	 * `"url"`, and CSS `url()` — because anything left pointing at this site
	 * is refused there ("Patterns may only reference images uploaded with
	 * them"), and refused without naming the culprit. A reference this site
	 * cannot bundle therefore fails here instead, where the URL and the
	 * reason can be named. Links (`href`) are not images: a local one is
	 * bundled when it resolves, and anything else is left alone.
	 *
	 * @param string $content Block markup.
	 * @return array|WP_Error url => { key, path, mime }
	 */
	private function collect_local_assets( $content ) {
		$images = '(?:jpg|jpeg|png|gif|webp)';

		// Media the browser fetches to render the pattern: the service refuses
		// any of it that it can't reach, so it has to travel along.
		preg_match_all( '/(?:src="|url\(\s*[\'"]?)(https?:(?:\\?\/|\/)[^"\')]+)/i', $content, $required );

		/*
		 * A block attribute named `url` only sometimes holds media: a social
		 * link, an embed and a button keep their destination there, and a
		 * pattern that links to wordpress.org references no image at all.
		 * Only an attribute naming a media file counts — whatever really
		 * renders as media appears in the markup's src or url() as well.
		 */
		preg_match_all( '/"url"\s*:\s*"(https?:(?:\\?\/|\/)[^"]+)"/i', $content, $attributes );
		foreach ( $attributes[1] as $attribute_url ) {
			if ( $this->is_media_url( str_replace( '\\/', '/', $attribute_url ) ) ) {
				$required[1][] = $attribute_url;
			}
		}

		// Links to a local image (a lightbox, say): bundled when resolvable.
		preg_match_all( '/href="(https?:\/\/[^"]+\.' . $images . '(?:\?[^"]*)?)"/i', $content, $links );

		$assets = array();

		foreach ( array_unique( $required[1] ) as $raw ) {
			$url = str_replace( '\/', '/', $raw );

			$path = $this->url_to_path( $url );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$mime = wp_check_filetype( basename( strtok( $path, '?' ) ) )['type'];
			if ( ! in_array( $mime, $this->bundleable_mimes(), true ) ) {
				return new WP_Error(
					'pb_cloud_unsupported_asset',
					sprintf(
						/* translators: %s: image URL. */
						__( 'Patterns can only carry JPEG, PNG, GIF, and WebP images, and this one references something else: %s', 'pattern-builder' ),
						$url
					),
					array( 'status' => 400 )
				);
			}

			$assets[ $url ] = $this->describe_asset( $url, $path, $mime );
		}

		foreach ( array_unique( $links[1] ) as $url ) {
			if ( isset( $assets[ $url ] ) ) {
				continue;
			}

			$path = $this->url_to_path( $url );
			if ( is_wp_error( $path ) ) {
				continue;
			}

			$mime = wp_check_filetype( basename( strtok( $path, '?' ) ) )['type'];
			if ( in_array( $mime, $this->bundleable_mimes(), true ) ) {
				$assets[ $url ] = $this->describe_asset( $url, $path, $mime );
			}
		}

		return $assets;
	}

	/**
	 * Media blocks and the attributes that name a local attachment.
	 *
	 * @return array Block name => attribute keys.
	 */
	private function attachment_attributes() {
		return array(
			'core/image'      => array( 'id' ),
			'core/cover'      => array( 'id' ),
			'core/media-text' => array( 'mediaId' ),
			'core/video'      => array( 'id' ),
			'core/audio'      => array( 'id' ),
			'core/gallery'    => array( 'ids' ),
		);
	}

	/**
	 * Forget which attachment an image was here.
	 *
	 * An attachment id means nothing on another site: id 57 is this site's
	 * avatar and somebody else's letterhead. The image itself travels in the
	 * package, so the ids and the `wp-image-57` classes that name it are
	 * dropped on the way out, leaving blocks that render from their src —
	 * which is how a theme's own pattern files are written anyway.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	private function strip_attachment_identity( $content ) {
		$blocks = implode( '|', array_map( 'preg_quote', array_keys( $this->attachment_attributes() ) ) );
		$blocks = str_replace( 'core/', '', $blocks );

		$content = preg_replace_callback(
			'/<!--\s+wp:(' . $blocks . ')\s+(\{.*?\})\s+(\/?)-->/s',
			function ( $matches ) {
				$attributes = json_decode( $matches[2], true );
				if ( ! is_array( $attributes ) ) {
					return $matches[0];
				}

				foreach ( $this->attachment_attributes()[ 'core/' . $matches[1] ] as $key ) {
					unset( $attributes[ $key ] );
				}

				// Core's own serializer: the escaping rules that keep a block
				// comment a block comment are its business, not ours.
				return $attributes
					? '<!-- wp:' . $matches[1] . ' ' . serialize_block_attributes( $attributes ) . ' ' . $matches[3] . '-->'
					: '<!-- wp:' . $matches[1] . ' ' . $matches[3] . '-->';
			},
			$content
		);

		// The same id, spelled as a class or a data attribute in the markup.
		$content = preg_replace( '/\s*\bwp-image-\d+\b/', '', $content );
		$content = preg_replace( '/\s+class="\s*"/', '', $content );
		$content = preg_replace( '/\s+data-id="\d+"/', '', $content );

		return $content;
	}

	/**
	 * Name the attachments a downloaded pattern's images just became.
	 *
	 * The mirror of strip_attachment_identity(): the package carries no ids,
	 * because the sender's ids meant nothing here — but the images have now
	 * been sideloaded, so the blocks can point at the local attachments and
	 * the editor sees an image it knows rather than a bare URL.
	 *
	 * @param string $content     Block markup, placeholders already resolved.
	 * @param array  $attachments Local URL => attachment id.
	 * @return string
	 */
	private function attach_media_library_ids( $content, $attachments ) {
		if ( ! $attachments ) {
			return $content;
		}

		$blocks  = $this->name_attachments_in_blocks( parse_blocks( $content ), $attachments );
		$content = serialize_blocks( $blocks );

		// The same id, as the class core writes on the image itself.
		$images = new \WP_HTML_Tag_Processor( $content );
		while ( $images->next_tag( 'img' ) ) {
			$src = $images->get_attribute( 'src' );
			if ( is_string( $src ) && isset( $attachments[ $src ] ) ) {
				$images->add_class( 'wp-image-' . $attachments[ $src ] );
			}
		}

		return $images->get_updated_html();
	}

	/**
	 * Set the attachment attribute on every media block that shows one of
	 * these images, innermost blocks included.
	 *
	 * @param array $blocks      Parsed blocks.
	 * @param array $attachments Local URL => attachment id.
	 * @return array
	 */
	private function name_attachments_in_blocks( $blocks, $attachments ) {
		foreach ( $blocks as &$block ) {
			$keys = isset( $this->attachment_attributes()[ $block['blockName'] ] )
				? $this->attachment_attributes()[ $block['blockName'] ]
				: array();

			foreach ( $keys as $key ) {
				// `ids` is the legacy gallery's list, not one image's identity.
				if ( 'ids' === $key ) {
					continue;
				}

				$id = $this->attachment_shown_by( $block, $attachments );
				if ( $id ) {
					$block['attrs'][ $key ] = $id;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->name_attachments_in_blocks( $block['innerBlocks'], $attachments );
			}
		}

		return $blocks;
	}

	/**
	 * The attachment a media block shows, by the URL in its own markup or
	 * in its `url` attribute — never one an inner block brought with it.
	 *
	 * @param array $block       One parsed block.
	 * @param array $attachments Local URL => attachment id.
	 * @return int 0 when the block shows none of them.
	 */
	private function attachment_shown_by( $block, $attachments ) {
		if ( ! empty( $block['attrs']['url'] ) && isset( $attachments[ $block['attrs']['url'] ] ) ) {
			return $attachments[ $block['attrs']['url'] ];
		}

		$own_markup = implode(
			'',
			array_filter(
				$block['innerContent'],
				static function ( $chunk ) {
					return is_string( $chunk );
				}
			)
		);

		$images = new \WP_HTML_Tag_Processor( $own_markup );
		while ( $images->next_tag( 'img' ) ) {
			$src = $images->get_attribute( 'src' );
			if ( is_string( $src ) && isset( $attachments[ $src ] ) ) {
				return $attachments[ $src ];
			}
		}

		return 0;
	}

	/**
	 * Whether a URL names a media file, by its extension. Mirrors the rule
	 * the service applies, so the two agree on what counts as an image.
	 *
	 * @param string $url The URL.
	 * @return bool
	 */
	private function is_media_url( $url ) {
		$path = (string) wp_parse_url( strtok( $url, '?' ), PHP_URL_PATH );

		return (bool) preg_match( '/\.(?:jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?|mp4|m4v|webm|ogv|ogg|mp3|wav|m4a|mov)$/i', $path );
	}

	/**
	 * The package entry for one resolved asset.
	 *
	 * @param string $url  The URL as it appears in the markup.
	 * @param string $path The file on disk.
	 * @param string $mime The file's MIME type.
	 * @return array
	 */
	private function describe_asset( $url, $path, $mime ) {
		$name = sanitize_key( preg_replace( '/\.[^.]+$/', '', basename( strtok( $path, '?' ) ) ) );
		$name = preg_replace( '/[^a-z0-9_\-]/', '', str_replace( '.', '-', $name ) );

		return array(
			'key'  => substr( $name, 0, 60 ) . '-' . substr( md5( $url ), 0, 6 ),
			'path' => $path,
			'mime' => $mime,
		);
	}

	/**
	 * Map a URL to the file it names on this site.
	 *
	 * Matching is by host and path, not by string prefix: the same image is
	 * written http:// in one pattern and https:// in another, with or without
	 * www, and a prefix comparison would call a perfectly local image
	 * foreign. An image that really is on another site gets its own error —
	 * it can't travel with the package, and saying so here is the only place
	 * the URL can be named.
	 *
	 * @param string $url Image URL.
	 * @return string|WP_Error
	 */
	private function url_to_path( $url ) {
		$parts = wp_parse_url( strtok( $url, '?' ) );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';

		if ( $host && ! in_array( $host, $this->local_hosts(), true ) ) {
			return new WP_Error(
				'pb_cloud_foreign_asset_source',
				sprintf(
					/* translators: %s: image URL. */
					__( 'The pattern points at an image hosted on another site, which cannot be uploaded with it: %s. Add the image to this site’s media library and use it in the pattern.', 'pattern-builder' ),
					$url
				),
				array( 'status' => 400 )
			);
		}

		$uploads = wp_get_upload_dir();
		$roots   = array(
			$uploads['baseurl']            => $uploads['basedir'],
			get_stylesheet_directory_uri() => get_stylesheet_directory(),
			get_template_directory_uri()   => get_template_directory(),
			content_url()                  => WP_CONTENT_DIR,
		);

		foreach ( $roots as $base_url => $dir ) {
			$base = untrailingslashit( (string) wp_parse_url( $base_url, PHP_URL_PATH ) );

			if ( '' === $base || 0 !== strpos( $path, $base . '/' ) ) {
				continue;
			}

			// realpath, and inside the root: a path is not a promise (../).
			$candidate = realpath( untrailingslashit( $dir ) . substr( $path, strlen( $base ) ) );
			$root      = realpath( $dir );

			if ( $candidate && $root && 0 === strpos( $candidate, $root ) && is_file( $candidate ) ) {
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
	 * The hostnames that mean "this site".
	 *
	 * @return string[]
	 */
	private function local_hosts() {
		$hosts = array_map(
			static function ( $base ) {
				return strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
			},
			array(
				home_url(),
				site_url(),
				content_url(),
				get_stylesheet_directory_uri(),
				get_template_directory_uri(),
				wp_get_upload_dir()['baseurl'],
			)
		);

		return array_values( array_unique( array_filter( $hosts ) ) );
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
			/*
			 * Always fetch from the configured service origin: packages name
			 * the origin the service self-identifies as, which can differ
			 * (dev setups, proxies). A foreign URL 404s at the service
			 * instead of being followed.
			 */
			$service = wp_parse_url( Pattern_Builder_Cloud::service_url() );
			$parts   = wp_parse_url( $url );
			if ( empty( $service['host'] ) || empty( $parts['path'] ) || 0 !== strpos( $parts['path'], '/' ) ) {
				return new WP_Error( 'pb_cloud_foreign_asset', __( 'The pattern package referenced an asset outside the pattern service.', 'pattern-builder' ), array( 'status' => 502 ) );
			}

			$fetch = ( isset( $service['scheme'] ) ? $service['scheme'] : 'http' ) . '://' . $service['host']
				. ( isset( $service['port'] ) ? ':' . $service['port'] : '' )
				. $parts['path']
				. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temp = download_url( $fetch, 60 );
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

		return array(
			'id'  => (int) $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
		);
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
			$term_ids = array();
			foreach ( $categories as $category ) {
				$term_ids[] = $this->pattern_category_term( $category );
			}
			wp_set_object_terms( $post_id, array_filter( $term_ids ), 'wp_pattern_category', false );
		}

		return array(
			'type'  => 'user',
			'id'    => $post_id,
			'title' => $title,
		);
	}

	/**
	 * The wp_pattern_category term for a category name or slug, created
	 * when missing. A collection's category is created by slug with the
	 * collection's title as its name, so the editor's own lists show the
	 * title too.
	 *
	 * @param string $category A category name or slug.
	 * @return int Term ID, or 0.
	 */
	private function pattern_category_term( $category ) {
		$existing = get_term_by( 'slug', sanitize_title( $category ), 'wp_pattern_category' );
		if ( ! $existing ) {
			$existing = get_term_by( 'name', $category, 'wp_pattern_category' );
		}
		if ( $existing instanceof \WP_Term ) {
			return (int) $existing->term_id;
		}

		$labels = Pattern_Builder_Cloud::collection_categories();
		$name   = isset( $labels[ $category ] ) ? $labels[ $category ] : $category;

		$created = wp_insert_term( $name, 'wp_pattern_category', array( 'slug' => sanitize_title( $category ) ) );
		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}

	/**
	 * Which local pattern (if any) a cloud pattern is installed as; a
	 * deleted local copy reads as not installed.
	 *
	 * @param int $cloud_id Cloud pattern ID.
	 * @return array|null { type, id, title, collection: array }
	 */
	public function find_installed( $cloud_id ) {
		foreach ( Pattern_Builder_Cloud::links() as $key => $link ) {
			if ( (int) $link['cloudId'] !== (int) $cloud_id ) {
				continue;
			}

			$parts = explode( ':', (string) $key, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$type  = 'user' === $parts[0] ? 'user' : 'theme';
			$id    = 'user' === $type ? (int) $parts[1] : $parts[1];
			$local = $this->describe_local( $type, $id );
			if ( $local ) {
				$local['collection'] = isset( $link['collection'] ) && is_array( $link['collection'] ) ? $link['collection'] : array();
				return $local;
			}
		}

		return null;
	}

	/**
	 * Install a whole cloud collection onto this site.
	 *
	 * Fetches the collection, then imports each pattern in turn through the
	 * single-pattern path: one the link map says is already installed from
	 * this collection is skipped, a failure is recorded and the rest carry
	 * on, and every pattern lands under the collection's local category.
	 * The browser drives the same steps itself through /cloud/download so it
	 * can show progress; the agent ability calls this.
	 *
	 * @param int    $owner       The collection owner's account id.
	 * @param string $slug        The collection's plain slug.
	 * @param string $destination 'user' or 'theme'.
	 * @param string $tokens      'add' to write the design tokens this site
	 *                            lacks into the destination, 'skip' to leave
	 *                            them.
	 * @return array|WP_Error { collection, results: array, installed, skipped, failed }
	 */
	public function install_collection( $owner, $slug, $destination, $tokens = 'add' ) {
		$destination = 'theme' === $destination ? 'theme' : 'user';
		$owner       = (int) $owner;
		$slug        = sanitize_title( (string) $slug );

		$collection = Pattern_Builder_Cloud::request( 'GET', "/directory/collections/{$owner}/{$slug}" );
		if ( is_wp_error( $collection ) ) {
			return $collection;
		}
		if ( empty( $collection['patterns'] ) || ! is_array( $collection['patterns'] ) ) {
			$collection['patterns'] = array();
		}

		$described = Pattern_Builder_Cloud::describe_collection( $collection );
		$results   = array();
		$counts    = array(
			'installed' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);

		foreach ( $collection['patterns'] as $summary ) {
			$cloud_id = isset( $summary['id'] ) ? (int) $summary['id'] : 0;
			$title    = isset( $summary['title'] ) ? (string) $summary['title'] : '';
			$result   = array(
				'cloudId' => $cloud_id,
				'title'   => $title,
			);

			$installed = $cloud_id ? $this->find_installed( $cloud_id ) : null;
			if ( $installed && ! empty( $installed['collection']['slug'] )
				&& $installed['collection']['slug'] === $described['slug']
				&& (int) $installed['collection']['owner'] === $described['owner'] ) {
				$result['status'] = 'skipped';
				$result['type']   = $installed['type'];
				$result['id']     = $installed['id'];
				++$counts['skipped'];
				$results[] = $result;
				continue;
			}

			$outcome = $this->install_cloud_pattern( $cloud_id, $destination, 'add' === $tokens, $described, ! empty( $summary['mine'] ) );
			if ( is_wp_error( $outcome ) ) {
				$result['status']  = 'failed';
				$result['message'] = $outcome->get_error_message();
				++$counts['failed'];
			} else {
				$result['status'] = 'installed';
				$result['type']   = $outcome['type'];
				$result['id']     = $outcome['id'];
				++$counts['installed'];
			}
			$results[] = $result;
		}

		unset( $collection['patterns'] );

		return array_merge(
			array(
				'collection' => $collection,
				'results'    => $results,
			),
			$counts
		);
	}

	/**
	 * Download one directory pattern and land it here: the single-pattern
	 * path the REST route and install_collection() share. Missing design
	 * tokens go to the same destination as the pattern, and the link map
	 * records the cloud copy and its collection.
	 *
	 * @param int    $cloud_id   Cloud pattern ID.
	 * @param string $destination 'user' or 'theme'.
	 * @param bool   $add_tokens Whether to write the design tokens this site lacks.
	 * @param array  $collection { owner, slug, title } or empty.
	 * @param bool   $mine       Whether the cloud copy is this account's, as the service said.
	 * @param string $source     'directory' or 'library'.
	 * @return array|WP_Error { type, id, title, tokensWritten }
	 */
	public function install_cloud_pattern( $cloud_id, $destination, $add_tokens, $collection = array(), $mine = false, $source = 'directory' ) {
		$cloud_id    = (int) $cloud_id;
		$destination = 'theme' === $destination ? 'theme' : 'user';
		$source      = 'library' === $source ? 'library' : 'directory';

		if ( ! $cloud_id ) {
			return new WP_Error( 'pb_cloud_bad_request', __( 'Which pattern?', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		$pbp = Pattern_Builder_Cloud::request( 'POST', "/{$source}/patterns/{$cloud_id}/download" );
		if ( is_wp_error( $pbp ) ) {
			return $pbp;
		}

		/*
		 * Which collection the pattern is in: a caller that already knows
		 * (a whole-collection install, the browser with the summary in
		 * hand) says so; otherwise the directory is asked, so a pattern an
		 * agent names by id alone still lands under its collection's
		 * category. A pattern from the account's own library carries no
		 * footprint — it is the account's own work, not something installed.
		 */
		$collection = Pattern_Builder_Cloud::describe_collection( $collection );
		if ( ! $collection && 'directory' === $source ) {
			$summary = Pattern_Builder_Cloud::request( 'GET', "/directory/patterns/{$cloud_id}" );
			if ( ! is_wp_error( $summary ) && ! empty( $summary['collection'] ) ) {
				$collection = Pattern_Builder_Cloud::describe_collection( $summary['collection'] );
			}
		}

		$tokens_written = array();
		if ( $add_tokens && ! empty( $pbp['tokens'] ) ) {
			$tokens_written = Pattern_Builder_Cloud_Tokens::apply( $pbp['tokens'], $destination );
			if ( is_wp_error( $tokens_written ) ) {
				return $tokens_written;
			}
		}

		$result = $this->import_pbp( $pbp, $destination, $collection );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$hash = $this->content_hash( $result['type'], $result['id'] );

		/*
		 * Whether the cloud copy is this account's to update later. One from
		 * the account's own library always is; one from the directory only if
		 * the service said so when it listed it.
		 */
		Pattern_Builder_Cloud::set_link(
			self::local_key( $result['type'], $result['id'] ),
			$cloud_id,
			is_wp_error( $hash ) ? '' : $hash,
			'library' === $source || $mine,
			$collection
		);

		$result['tokensWritten'] = $tokens_written;
		return $result;
	}

	/**
	 * The name a downloaded pattern is installed under.
	 *
	 * The package carries the name the pattern has on the service —
	 * `{handle}/{collection}/{slug}` — and that is the name it keeps here,
	 * because it is the name anything referring to this pattern uses. It
	 * is also why two accounts can both publish a `hero` and this site can
	 * hold both: they are different names, in different directories.
	 *
	 * A package from before namespacing carries no name, and falls back to
	 * the theme's own namespace, which is where such a download landed.
	 *
	 * @param array  $pbp  Package.
	 * @param string $slug Sanitized slug.
	 * @return string
	 */
	private function install_name( $pbp, $slug ) {
		$namespace = isset( $pbp['namespace'] ) ? (string) $pbp['namespace'] : '';

		$segments = array_values( array_filter( explode( '/', $namespace ), 'strlen' ) );
		$segments = array_map(
			static function ( $segment ) {
				return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $segment ) );
			},
			$segments
		);
		$segments = array_values( array_filter( $segments, 'strlen' ) );

		// Two segments and a slug, or the package is not naming a namespace
		// this site should file anything under.
		if ( count( $segments ) < 2 ) {
			return get_stylesheet() . '/' . $slug;
		}

		return implode( '/', $segments );
	}

	/**
	 * Land a package as a theme pattern file.
	 *
	 * @param array    $pbp         Package (for viewport/keywords extras).
	 * @param string   $title       Title.
	 * @param string   $name        Namespaced pattern name to install under.
	 * @param string   $description Description.
	 * @param string[] $categories  Category names.
	 * @param bool     $synced      Synced flag.
	 * @param string   $content     Sanitized markup with local URLs.
	 * @return array|WP_Error
	 */
	private function import_as_theme_pattern( $pbp, $title, $name, $description, $categories, $synced, $content ) {
		$pattern = new Abstract_Pattern(
			array(
				'id'            => $name,
				'name'          => $name,
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
