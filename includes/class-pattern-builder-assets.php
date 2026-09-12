<?php
/**
 * Media and static assets a pattern draws on.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

/**
 * Finds, receives and stores the files a pattern references.
 */
class Pattern_Builder_Assets {
	/**
	 * The REST namespace the binary route lives in, shared with the rest of the plugin's
	 * routes.
	 */
	const REST_NAMESPACE = 'pattern-builder/v1';

	/**
	 * Where a theme's static images live, relative to the stylesheet directory.
	 */
	const THEME_IMAGE_DIR = '/assets/images/';

	/**
	 * Where a theme's self-hosted font files live.
	 */
	const THEME_FONT_DIR = '/assets/fonts/';

	/**
	 * The longest edge a stored image keeps, in pixels.
	 */
	const MAX_DIMENSION = 2400;

	/**
	 * Image types a pattern may carry, by extension.
	 */
	const IMAGE_TYPES = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' );

	/**
	 * Hook the component into WordPress.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * The one route that takes bytes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/assets',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive_asset' ),
				'permission_callback' => array( $this, 'can_write' ),
				'args'                => array(
					'destination' => array(
						'type'        => 'string',
						'enum'        => array( 'theme', 'media' ),
						'default'     => 'theme',
						'description' => __( 'Where to put the file: the active theme\'s assets directory, or the media library.', 'pattern-builder' ),
					),
					'filename'    => array(
						'type'        => 'string',
						'description' => __( 'Overrides the filename from the Content-Disposition header.', 'pattern-builder' ),
					),
					'alt'         => array(
						'type'        => 'string',
						'description' => __( 'Alternative text, recorded on a media library attachment.', 'pattern-builder' ),
					),
				),
			)
		);
	}

	/**
	 * Writing into the theme is the same authority the pattern routes ask for, and a media
	 * upload additionally needs core's own capability.
	 *
	 * @param \WP_REST_Request|null $request Request, when called as a permission callback.
	 * @return bool
	 */
	public function can_write( $request = null ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}

		if ( $request && 'media' === $request->get_param( 'destination' ) ) {
			return current_user_can( 'upload_files' );
		}

		return true;
	}

	/**
	 * Store an uploaded file and answer with the reference a pattern uses.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function receive_asset( $request ) {
		$destination = 'media' === $request->get_param( 'destination' ) ? 'media' : 'theme';
		$files       = $request->get_file_params();
		$filename    = (string) $request->get_param( 'filename' );

		if ( ! empty( $files ) ) {
			$file = reset( $files );

			if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				return new \WP_Error(
					'pb_asset_upload_failed',
					__( 'The multipart upload did not arrive. Check the request is not larger than this server\'s upload_max_filesize.', 'pattern-builder' ),
					array( 'status' => 400 )
				);
			}

			if ( '' === $filename ) {
				$filename = isset( $file['name'] ) ? (string) $file['name'] : '';
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading an upload PHP just wrote to its own temp directory.
			$bytes = file_get_contents( $file['tmp_name'] );
		} else {
			$bytes = $request->get_body();

			if ( '' === $filename ) {
				$filename = self::filename_from_disposition( (array) $request->get_header( 'content_disposition' ) );
			}
		}

		if ( '' === (string) $bytes ) {
			return new \WP_Error(
				'pb_asset_empty',
				__( 'No file arrived. Send the bytes as the request body with a Content-Disposition header naming the file, or as a multipart form.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $filename ) {
			return new \WP_Error(
				'pb_asset_no_filename',
				__( 'The file needs a name. Send a Content-Disposition header — attachment; filename="hero.webp" — or a filename parameter.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		$stored = self::store( $filename, $bytes, $destination );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return self::apply_alt( $stored, $request->get_param( 'alt' ) );
	}

	/**
	 * Record alternative text on a stored attachment.
	 *
	 * @param array|\WP_Error $stored What store() returned.
	 * @param string          $alt Alternative text, if any was given.
	 * @return array|\WP_Error The stored array, with alt recorded where it could be.
	 */
	public static function apply_alt( $stored, $alt ) {
		$alt = sanitize_text_field( (string) $alt );

		if ( '' === $alt || ! is_array( $stored ) || empty( $stored['id'] ) ) {
			return $stored;
		}

		update_post_meta( $stored['id'], '_wp_attachment_image_alt', $alt );
		$stored['alt'] = $alt;

		return $stored;
	}

	/**
	 * Put bytes on the site and describe what a pattern should point at.
	 *
	 * @param string $filename Proposed filename.
	 * @param string $bytes File contents.
	 * @param string $destination 'theme' or 'media'.
	 * @return array|\WP_Error
	 */
	public static function store( $filename, $bytes, $destination = 'theme' ) {
		$filename  = sanitize_file_name( basename( $filename ) );
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::IMAGE_TYPES, true ) ) {
			return new \WP_Error(
				'pb_asset_bad_type',
				sprintf(
					/* translators: 1: the extension given, 2: the accepted extensions. */
					__( 'A pattern cannot carry a .%1$s file. Use one of: %2$s.', 'pattern-builder' ),
					$extension,
					implode( ', ', self::IMAGE_TYPES )
				),
				array( 'status' => 400 )
			);
		}
		if ( 'svg' === $extension ) {
			if ( 'media' === $destination ) {
				return new \WP_Error(
					'pb_asset_svg_not_media',
					__( 'WordPress does not accept SVG in the media library. Store it in the theme instead — destination "theme".', 'pattern-builder' ),
					array( 'status' => 400 )
				);
			}

			$bytes = self::sanitize_svg( $bytes );

			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
		}

		$temp = wp_tempnam( $filename );

		if ( ! $temp ) {
			return new \WP_Error( 'pb_asset_no_temp', __( 'Could not open a temporary file to receive the upload.', 'pattern-builder' ), array( 'status' => 500 ) );
		}

		$written = Pattern_Builder_Security::safe_file_write( $temp, $bytes, array( dirname( $temp ) ) );

		if ( is_wp_error( $written ) ) {
			wp_delete_file( $temp );
			return $written;
		}
		if ( 'svg' !== $extension ) {
			$checked = wp_check_filetype_and_ext( $temp, $filename, self::image_mimes() );

			if ( empty( $checked['type'] ) ) {
				wp_delete_file( $temp );
				return new \WP_Error(
					'pb_asset_type_mismatch',
					sprintf(
						/* translators: %s: the filename given. */
						__( 'The contents of %s are not an image of the type its name claims.', 'pattern-builder' ),
						$filename
					),
					array( 'status' => 400 )
				);
			}
			if ( ! empty( $checked['proper_filename'] ) ) {
				$filename = $checked['proper_filename'];
			}

			$resized = self::constrain( $temp, $checked['type'] );

			if ( is_wp_error( $resized ) ) {
				wp_delete_file( $temp );
				return $resized;
			}
			if ( $resized !== $temp ) {
				wp_delete_file( $temp );
				$temp = $resized;
			}
		}

		$stored = 'media' === $destination
			? self::store_in_media_library( $temp, $filename )
			: self::store_in_theme( $temp, $filename );

		if ( file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}

		return $stored;
	}

	/**
	 * Move a prepared file into the theme's images directory.
	 *
	 * @param string $temp Path to the prepared file.
	 * @param string $filename Filename to store it under.
	 * @return array|\WP_Error
	 */
	private static function store_in_theme( $temp, $filename ) {
		$directory = get_stylesheet_directory() . self::THEME_IMAGE_DIR;

		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		$filename    = self::unique_filename( $directory, $filename );
		$destination = $directory . $filename;

		$moved = Pattern_Builder_Security::safe_file_move(
			$temp,
			$destination,
			array(
				dirname( $temp ),
				get_stylesheet_directory() . '/assets',
				get_template_directory() . '/assets',
			)
		);

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$relative = self::THEME_IMAGE_DIR . $filename;

		return array_merge(
			array(
				'destination' => 'theme',
				'filename'    => $filename,
				'path'        => 'assets/images/' . $filename,
				'url'         => get_stylesheet_directory_uri() . $relative,
				'reference'   => self::theme_reference( $relative ),
			),
			self::dimensions_of( $destination )
		);
	}

	/**
	 * Sideload a prepared file into the media library.
	 *
	 * @param string $temp Path to the prepared file.
	 * @param string $filename Filename to store it under.
	 * @return array|\WP_Error
	 */
	private static function store_in_media_library( $temp, $filename ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $temp,
			),
			0
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$url = wp_get_attachment_url( $id );

		return array_merge(
			array(
				'destination' => 'media',
				'filename'    => $filename,
				'id'          => (int) $id,
				'url'         => $url,
				'reference'   => $url,
			),
			self::dimensions_of( get_attached_file( $id ) )
		);
	}

	/**
	 * Fetch a URL and store what comes back.
	 *
	 * @param string $url Source URL.
	 * @param string $destination 'theme' or 'media'.
	 * @param string $filename Optional filename override.
	 * @return array|\WP_Error
	 */
	public static function store_from_url( $url, $destination = 'theme', $filename = '' ) {
		$url = esc_url_raw( $url );

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error(
				'pb_asset_bad_url',
				__( 'That is not a URL this site can fetch.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp = download_url( $url );

		if ( is_wp_error( $temp ) ) {
			return new \WP_Error(
				'pb_asset_fetch_failed',
				sprintf(
					/* translators: 1: the URL, 2: the underlying error. */
					__( 'Could not fetch %1$s: %2$s', 'pattern-builder' ),
					$url,
					$temp->get_error_message()
				),
				array( 'status' => 400 )
			);
		}

		if ( '' === $filename ) {
			$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
			$filename = basename( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file download_url() just wrote locally.
		$bytes = file_get_contents( $temp );
		wp_delete_file( $temp );

		if ( false === $bytes ) {
			return new \WP_Error( 'pb_asset_fetch_failed', __( 'The download could not be read back.', 'pattern-builder' ), array( 'status' => 400 ) );
		}

		return self::store( $filename, $bytes, $destination );
	}

	/**
	 * What the site already has to draw on.
	 *
	 * @param array $args Arguments as described above.
	 * @return array
	 */
	public static function find( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'type'     => 'image',
				'per_page' => 20,
				'source'   => 'all',
			)
		);

		$found = array(
			'media' => array(),
			'theme' => array(),
		);

		if ( 'theme' !== $args['source'] ) {
			$found['media'] = self::find_in_media_library( $args );
		}

		if ( 'media' !== $args['source'] ) {
			$found['theme'] = self::find_in_theme( (string) $args['search'] );
		}

		return $found;
	}

	/**
	 * Query the media library.
	 *
	 * @param array $args Arguments as passed to `find()`.
	 * @return array
	 */
	private static function find_in_media_library( $args ) {
		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$search   = (string) $args['search'];

		$query = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$type = (string) $args['type'];

		if ( '' !== $type && 'any' !== $type ) {
			$query['post_mime_type'] = $type;
		}

		$found = array();

		if ( '' !== $search ) {
			$query['s'] = $search;
			add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
			foreach ( get_posts( $query ) as $attachment ) {
				$found[ $attachment->ID ] = $attachment;
			}
			$by_alt = get_posts(
				array_merge(
					$query,
					array(
						's'          => '',
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A media search over a bounded result set; the alternative is not answering the question.
							array(
								'key'     => '_wp_attachment_image_alt',
								'value'   => $search,
								'compare' => 'LIKE',
							),
						),
					)
				)
			);

			foreach ( $by_alt as $attachment ) {
				$found[ $attachment->ID ] = $attachment;
			}

			$found = array_slice( array_values( $found ), 0, $per_page );
		} else {
			$found = get_posts( $query );
		}

		$items = array();

		foreach ( $found as $attachment ) {
			$file = get_attached_file( $attachment->ID );

			$items[] = array_merge(
				array(
					'id'        => (int) $attachment->ID,
					'title'     => $attachment->post_title,
					'filename'  => $file ? basename( $file ) : '',
					'mime'      => $attachment->post_mime_type,
					'url'       => wp_get_attachment_url( $attachment->ID ),
					'alt'       => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
					'reference' => wp_get_attachment_url( $attachment->ID ),
				),
				$file ? self::dimensions_of( $file ) : array()
			);
		}

		return $items;
	}

	/**
	 * List the image files in the theme's assets directory.
	 *
	 * @param string $search Substring to match against the filename.
	 * @return array
	 */
	private static function find_in_theme( $search = '' ) {
		$directories = array( get_template_directory() => get_template_directory_uri() );
		if ( get_stylesheet_directory() !== get_template_directory() ) {
			$directories[ get_stylesheet_directory() ] = get_stylesheet_directory_uri();
		}

		$items = array();

		foreach ( $directories as $directory => $uri ) {
			$path = $directory . self::THEME_IMAGE_DIR;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			foreach ( (array) glob( $path . '*' ) as $file ) {
				if ( ! is_file( $file ) ) {
					continue;
				}

				$filename  = basename( $file );
				$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

				if ( ! in_array( $extension, self::IMAGE_TYPES, true ) ) {
					continue;
				}

				if ( '' !== $search && false === stripos( $filename, $search ) ) {
					continue;
				}

				$relative = self::THEME_IMAGE_DIR . $filename;

				$items[ $filename ] = array_merge(
					array(
						'filename'  => $filename,
						'path'      => 'assets/images/' . $filename,
						'url'       => $uri . $relative,
						'reference' => self::theme_reference( $relative ),
					),
					self::dimensions_of( $file )
				);
			}
		}

		return array_values( $items );
	}

	/**
	 * Draw a placeholder image.
	 *
	 * @param array $args Arguments as described above.
	 * @return string SVG markup.
	 */
	public static function placeholder_svg( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'width'  => 1200,
				'height' => 800,
				'label'  => '',
			)
		);

		$width     = max( 16, min( 8000, (int) $args['width'] ) );
		$height    = max( 16, min( 8000, (int) $args['height'] ) );
		$label     = '' !== (string) $args['label']
			? (string) $args['label']
			: $width . ' × ' . $height;
		$type_size = max( 12, (int) round( min( $width, $height ) / 14 ) );
		$stroke    = max( 1, (int) round( min( $width, $height ) / 400 ) );

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img" aria-label="%3$s">' .
			'<rect width="%1$d" height="%2$d" fill="#e8e8e6"/>' .
			'<path d="M0 0 L%1$d %2$d M%1$d 0 L0 %2$d" stroke="#d0d0cd" stroke-width="%4$d" fill="none"/>' .
			'<text x="50%%" y="50%%" dominant-baseline="middle" text-anchor="middle" font-family="system-ui, sans-serif" font-size="%5$d" fill="#77776f">%3$s</text>' .
			'</svg>',
			$width,
			$height,
			esc_html( $label ),
			$stroke,
			$type_size
		);
	}

	/**
	 * The PHP a theme pattern file uses to point at one of its own assets.
	 *
	 * @param string $relative Path from the stylesheet directory, leading slash.
	 * @return string
	 */
	public static function theme_reference( $relative ) {
		return '<?php echo get_stylesheet_directory_uri() . \'' . $relative . '\'; ?>';
	}

	/**
	 * Shrink an image whose longest edge is over the cap.
	 *
	 * @param string $file Path to the image, modified in place.
	 * @param string $mime The image's real mime type, as sniffed from the file.
	 * @return string|\WP_Error Path holding the image to go on with, which is not
	 * necessarily the path passed in.
	 */
	private static function constrain( $file, $mime ) {
		$max = (int) apply_filters( 'pattern_builder_max_asset_dimension', self::MAX_DIMENSION );

		if ( $max <= 0 ) {
			return $file;
		}

		$size = self::dimensions_of( $file );

		if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
			return $file;
		}

		if ( $size['width'] <= $max && $size['height'] <= $max ) {
			return $file;
		}

		$editor = wp_get_image_editor( $file, array( 'mime_type' => $mime ) );

		if ( is_wp_error( $editor ) ) {
			return $file;
		}

		$resized = $editor->resize( $max, $max, false );

		if ( is_wp_error( $resized ) ) {
			return $file;
		}

		$saved = $editor->save( $file, $mime );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return ! empty( $saved['path'] ) ? (string) $saved['path'] : $file;
	}

	/**
	 * Measure an image, tolerating one this server cannot read.
	 *
	 * @param string $file Path to the image.
	 * @return array Width and height, or an empty array.
	 */
	private static function dimensions_of( $file ) {
		if ( ! $file || ! file_exists( $file ) ) {
			return array();
		}

		if ( 'svg' === strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			return array();
		}

		$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file this server cannot measure is reported without dimensions rather than fatal.

		if ( ! is_array( $size ) || empty( $size[0] ) ) {
			return array();
		}

		return array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		);
	}

	/**
	 * The mime map `wp_check_filetype_and_ext` is held to.
	 *
	 * @return array
	 */
	private static function image_mimes() {
		$mimes = array();

		foreach ( wp_get_mime_types() as $extensions => $mime ) {
			if ( 0 !== strpos( $mime, 'image/' ) ) {
				continue;
			}

			$mimes[ $extensions ] = $mime;
		}

		return $mimes;
	}

	/**
	 * An unused filename in a directory, numbering a collision rather than overwriting it —
	 * two patterns may each bring a `hero.webp`.
	 *
	 * @param string $directory Directory to check, with trailing slash.
	 * @param string $filename Proposed filename.
	 * @return string
	 */
	private static function unique_filename( $directory, $filename ) {
		$extension = (string) pathinfo( $filename, PATHINFO_EXTENSION );
		$base      = (string) pathinfo( $filename, PATHINFO_FILENAME );
		$candidate = $filename;
		$suffix    = 1;

		while ( file_exists( $directory . $candidate ) ) {
			++$suffix;
			$candidate = $base . '-' . $suffix . ( '' !== $extension ? '.' . $extension : '' );
		}

		return $candidate;
	}

	/**
	 * Strip what an SVG must not carry.
	 *
	 * @param string $svg SVG markup.
	 * @return string|\WP_Error
	 */
	public static function sanitize_svg( $svg ) {
		$svg = (string) $svg;

		if ( ! preg_match( '/<svg[\s>]/i', $svg ) ) {
			return new \WP_Error(
				'pb_asset_not_svg',
				__( 'That does not contain an <svg> element.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}
		$svg = preg_replace( '#<\s*(script|foreignObject|iframe|embed|object|use|image|audio|video|animate|set|handler)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg );
		$svg = preg_replace( '#<\s*(script|foreignObject|iframe|embed|object|use|image|audio|video|animate|set|handler)\b[^>]*/?>#i', '', $svg );
		$svg = preg_replace( '#<!DOCTYPE.*?>#is', '', $svg );
		$svg = preg_replace( '#<!ENTITY.*?>#is', '', $svg );
		$svg = preg_replace( '#<\?xml-stylesheet.*?\?>#is', '', $svg );
		$svg = preg_replace( '#\son[a-z-]+\s*=\s*"[^"]*"#i', '', $svg );
		$svg = preg_replace( '#\son[a-z-]+\s*=\s*\'[^\']*\'#i', '', $svg );
		$svg = preg_replace( '#\son[a-z-]+\s*=\s*[^\s>]+#i', '', $svg );
		$svg = preg_replace( '#(href|xlink:href|src|from|to|values)\s*=\s*"\s*(javascript|data|vbscript):[^"]*"#i', '', $svg );
		$svg = preg_replace( '#(href|xlink:href|src|from|to|values)\s*=\s*\'\s*(javascript|data|vbscript):[^\']*\'#i', '', $svg );

		if ( ! preg_match( '/<svg[\s>]/i', $svg ) ) {
			return new \WP_Error(
				'pb_asset_not_svg',
				__( 'Nothing was left of that SVG once its scripts and external references were removed.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		return $svg;
	}

	/**
	 * Read a filename out of a Content-Disposition header.
	 *
	 * @param array $headers Content-Disposition header values.
	 * @return string
	 */
	private static function filename_from_disposition( $headers ) {
		foreach ( $headers as $value ) {
			foreach ( array_map( 'trim', explode( ';', (string) $value ) ) as $part ) {
				if ( 0 !== stripos( $part, 'filename' ) ) {
					continue;
				}

				$pair = explode( '=', $part, 2 );

				if ( 2 !== count( $pair ) ) {
					continue;
				}

				$filename = trim( $pair[1], " \t\n\r\0\x0B\"'" );

				if ( '' !== $filename ) {
					return $filename;
				}
			}
		}

		return '';
	}
}
