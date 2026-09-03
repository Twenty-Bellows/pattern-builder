<?php
/**
 * Media and static assets a pattern draws on.
 *
 * A pattern is markup plus the files it points at, and an agent writing one
 * over the wire has the markup covered and nothing else. This is the other
 * half: finding what the site already has, and getting a new file onto it.
 *
 * The one thing that cannot ride an ability is the file itself. Abilities are
 * JSON in and JSON out under an `input` key, so bytes would have to be
 * base64 inside that JSON — which for anything but a small SVG means the
 * agent reading the file into its own context and paying for it twice.
 * `POST /pattern-builder/v1/assets` exists so the bytes go from disk to the
 * site without passing through the agent at all, exactly as core's own
 * `/wp/v2/media` accepts them, and `Pattern_Builder_Abilities` documents the
 * route so it is discovered through the registry rather than rediscovered.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

/**
 * Finds, receives and stores the files a pattern references.
 */
class Pattern_Builder_Assets {

	/**
	 * The REST namespace the binary route lives in, shared with the rest of
	 * the plugin's routes.
	 */
	const REST_NAMESPACE = 'pattern-builder/v1';

	/**
	 * Where a theme's static images live, relative to the stylesheet
	 * directory. The same directory `Pattern_File_Store` localises into, so a
	 * file put here by an agent and one pulled in by saving a pattern land
	 * together.
	 */
	const THEME_IMAGE_DIR = '/assets/images/';

	/**
	 * Where a theme's self-hosted font files live.
	 */
	const THEME_FONT_DIR = '/assets/fonts/';

	/**
	 * The longest edge a stored image keeps, in pixels.
	 *
	 * Nothing resizes a file written straight into a theme — the media
	 * library's size set is generated on upload and a theme asset never goes
	 * through it — so without a cap here a pattern ships whatever came off
	 * the camera. 2400 is twice the width the pattern grid renders at, which
	 * covers a full-bleed hero on a 2x display and stops at that.
	 */
	const MAX_DIMENSION = 2400;

	/**
	 * Image types a pattern may carry, by extension.
	 *
	 * SVG is here and is deliberately not offered to the media library: core
	 * does not allow SVG uploads, and overriding that for the whole site to
	 * satisfy a pattern would be a poor trade. A theme asset is a file
	 * written by somebody with `edit_theme_options`, which is a different
	 * question, and `sanitize_svg()` still runs over it.
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
	 *
	 * Deliberately shaped like `POST /wp/v2/media`: a raw body plus a
	 * `Content-Disposition` naming the file, or a multipart form. An agent
	 * that already knows how to upload to WordPress knows how to call this,
	 * and `curl --data-binary @file` needs nothing encoded.
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
	 * Writing into the theme is the same authority the pattern routes ask
	 * for, and a media upload additionally needs core's own capability.
	 *
	 * @param \WP_REST_Request|null $request Request, when called as a
	 *                                       permission callback.
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
			// A multipart form: take the first file whatever it was named,
			// since an agent has no reason to guess our field name.
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
	 * Only the media library has somewhere to keep it: a file in the theme
	 * carries no metadata, so for a theme asset the pattern's own `alt`
	 * attribute is the only place alt text lives. Shared by the upload route
	 * and the add-asset ability so both behave the same way.
	 *
	 * @param array|\WP_Error $stored What store() returned.
	 * @param string          $alt    Alternative text, if any was given.
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
	 * The type is decided by sniffing the file rather than trusting its name,
	 * because the name came over the wire. Everything else — the size cap,
	 * the SVG scrub — follows from the destination.
	 *
	 * @param string $filename    Proposed filename.
	 * @param string $bytes       File contents.
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

		// An SVG is text, and the one image type whose contents can execute.
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

		/*
		 * The name says .webp; this asks the file. `wp_check_filetype_and_ext`
		 * is what core's own upload runs, and it catches both a mislabelled
		 * file and one whose real type this site cannot accept.
		 */
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

			// Core hands back a corrected name where the extension was wrong.
			if ( ! empty( $checked['proper_filename'] ) ) {
				$filename = $checked['proper_filename'];
			}

			$resized = self::constrain( $temp, $checked['type'] );

			if ( is_wp_error( $resized ) ) {
				wp_delete_file( $temp );
				return $resized;
			}

			/*
			 * The editor names its output from the mime type rather than
			 * from the path it was handed, so a resize writes a second file
			 * beside the temporary one instead of over it. Carry on with
			 * whichever file holds the image now.
			 */
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
	 * @param string $temp     Path to the prepared file.
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
	 * @param string $temp     Path to the prepared file.
	 * @param string $filename Filename to store it under.
	 * @return array|\WP_Error
	 */
	private static function store_in_media_library( $temp, $filename ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		/*
		 * `wp_handle_sideload` moves the file rather than copying it, and
		 * insists the file is not an HTTP upload — which is exactly our case,
		 * since the bytes arrived as a request body.
		 */
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
				// A media library URL is the reference: a pattern saved to a
				// theme file has its local URLs localised on save, so this
				// works for either kind of pattern.
				'reference'   => $url,
			),
			self::dimensions_of( get_attached_file( $id ) )
		);
	}

	/**
	 * Fetch a URL and store what comes back.
	 *
	 * @param string $url         Source URL.
	 * @param string $destination 'theme' or 'media'.
	 * @param string $filename    Optional filename override.
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
	 * Two sources, because a pattern can reference either: attachments in the
	 * media library, and the files already sitting in the theme's own assets
	 * directory — which is where every image a theme pattern references
	 * lives, and which no core route lists.
	 *
	 * `search` matches title, filename and alt text; `type` is a mime or
	 * prefix, defaulting to `image`; `per_page` bounds the media library
	 * query; `source` is `all`, `media` or `theme`.
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

			/*
			 * Core does not search an attachment's filename unless asked, and
			 * for media the filename is the likeliest thing to match — a
			 * search for "hero" should find hero.webp whatever its title
			 * says. The hook is read once and then cleared by core, so it is
			 * added immediately before the query rather than kept installed.
			 */
			add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );

			// Keyed by id so the second pass below merges rather than
			// appending the same attachment twice.
			foreach ( get_posts( $query ) as $attachment ) {
				$found[ $attachment->ID ] = $attachment;
			}

			/*
			 * Alt text lives in postmeta, which `s` never reaches, and it is
			 * where a site records what an image actually shows. `s` and
			 * `meta_query` are combined with AND, so this is a second pass
			 * merged by id rather than one cleverer query.
			 */
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
	 * Both themes, parent first, so a child theme's own file wins where the
	 * names collide — which is how the theme would resolve them.
	 *
	 * @param string $search Substring to match against the filename.
	 * @return array
	 */
	private static function find_in_theme( $search = '' ) {
		$directories = array( get_template_directory() => get_template_directory_uri() );

		// A child theme is listed second so its entry replaces the parent's.
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
	 * SVG because it is the one image an agent can author outright: no bytes
	 * to move, no library to have, and it scales to whatever the pattern
	 * asks of it. The drawing is deliberately plain — a muted ground, a
	 * diagonal, and its own dimensions as a label — because a placeholder
	 * that tries to look like a photograph reads as a broken photograph.
	 *
	 * `width` and `height` are pixels; `label` is the text drawn in the
	 * middle, defaulting to the dimensions.
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

		$width  = max( 16, min( 8000, (int) $args['width'] ) );
		$height = max( 16, min( 8000, (int) $args['height'] ) );
		$label  = '' !== (string) $args['label']
			? (string) $args['label']
			: $width . ' × ' . $height;

		// Sized off the shorter edge so the label stays proportionate whether
		// the placeholder is a wide banner or a narrow portrait.
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
	 * A theme pattern is a PHP file, and its images have to resolve wherever
	 * the theme is installed — so the path is composed at render rather than
	 * written as a URL. This is the same form `Pattern_File_Store` writes
	 * when it localises a pattern's images on save.
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
	 * @param string $mime The image's real mime type, as sniffed from the
	 *                     file. Passed explicitly because the file is still
	 *                     under its temporary `.tmp` name at this point, and
	 *                     the editor would otherwise derive the output format
	 *                     from that extension and fail to save.
	 * @return string|\WP_Error Path holding the image to go on with, which is
	 *                          not necessarily the path passed in.
	 */
	private static function constrain( $file, $mime ) {
		$max = (int) apply_filters( 'pattern_builder_max_asset_dimension', self::MAX_DIMENSION );

		if ( $max <= 0 ) {
			return $file;
		}

		$size = self::dimensions_of( $file );

		if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
			// Not something this server can measure — GD and Imagick both
			// decline some formats. Storing it unresized beats refusing it.
			return $file;
		}

		if ( $size['width'] <= $max && $size['height'] <= $max ) {
			return $file;
		}

		$editor = wp_get_image_editor( $file, array( 'mime_type' => $mime ) );

		if ( is_wp_error( $editor ) ) {
			// No image editor on this server. An unresized file is a worse
			// asset than a resized one, and a better outcome than a refusal.
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
	 * Built from core's own list so a site that has filtered its upload
	 * types — added AVIF, removed GIF — is respected, with SVG left out
	 * because it never reaches that check.
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
	 * An unused filename in a directory, numbering a collision rather than
	 * overwriting it — two patterns may each bring a `hero.webp`.
	 *
	 * @param string $directory Directory to check, with trailing slash.
	 * @param string $filename  Proposed filename.
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
	 * Somebody with `edit_theme_options` can generally already run code on
	 * the site, so this is hygiene rather than a security boundary: the file
	 * is served to every visitor, and a pattern has no use for a script, an
	 * external reference or an event handler. A file that is not SVG at all
	 * is refused outright.
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

		// Elements that execute, load, or reach off-site.
		$svg = preg_replace( '#<\s*(script|foreignObject|iframe|embed|object|use|image|audio|video|animate|set|handler)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg );
		$svg = preg_replace( '#<\s*(script|foreignObject|iframe|embed|object|use|image|audio|video|animate|set|handler)\b[^>]*/?>#i', '', $svg );

		// Doctype and entity declarations, which is how XXE arrives.
		$svg = preg_replace( '#<!DOCTYPE.*?>#is', '', $svg );
		$svg = preg_replace( '#<!ENTITY.*?>#is', '', $svg );
		$svg = preg_replace( '#<\?xml-stylesheet.*?\?>#is', '', $svg );

		// on* handlers, in either quoting style or none.
		$svg = preg_replace( '#\son[a-z-]+\s*=\s*"[^"]*"#i', '', $svg );
		$svg = preg_replace( '#\son[a-z-]+\s*=\s*\'[^\']*\'#i', '', $svg );
		$svg = preg_replace( '#\son[a-z-]+\s*=\s*[^\s>]+#i', '', $svg );

		// javascript: and data: in any attribute value.
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
	 * Core has this as a protected method on its attachments controller, so
	 * this is the same parse rather than a different one: the header may
	 * arrive quoted or bare, and only `filename` is of interest.
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
