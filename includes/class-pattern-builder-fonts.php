<?php
/**
 * Self-hosted webfonts, installed by name.
 *
 * `add-design-tokens` can register a `fontFamily` preset, but a preset is a
 * stack of names: it selects between fonts the visitor's device already has.
 * A typeface the site does not own needs files, and files are the one thing
 * an agent cannot author — so this asks WordPress's own font collection for
 * them by name. Core registers Google Fonts as a collection whose stated job
 * is that the files are "copied to and served from your site", which is both
 * the only licence-safe default available and the right privacy answer, since
 * nothing is fetched from Google at render time.
 *
 * What actually makes a font render is worth stating, because it is not the
 * Font Library: `wp_print_font_faces()` builds its `@font-face` rules from
 * `WP_Font_Face_Resolver::get_fonts_from_theme_json()`, so the thing that
 * counts is a `fontFamily` preset carrying `fontFace` descriptors in the
 * merged theme.json. The `wp_font_family` and `wp_font_face` posts are a
 * registry for the Manage Fonts screen. A complete install therefore writes
 * the preset in both destinations, and additionally creates the library posts
 * for the `user` one so the font can be seen and removed where a person would
 * look for it.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

/**
 * Installs a font family from a registered font collection.
 */
class Pattern_Builder_Fonts {

	/**
	 * The collection installed from.
	 *
	 * Core registers this one, pinned to the release, and it is the only
	 * source whose licensing is safe by construction — every family in
	 * Google Fonts is open licence. An arbitrary URL is a licensing decision
	 * that is not this plugin's to make on somebody's behalf, so
	 * `install_from_url()` exists separately and says whose call it is.
	 */
	const COLLECTION = 'google-fonts';

	/**
	 * A trimmed list of what the collection offers, cached.
	 *
	 * The collection JSON is a megabyte or so — every family, every variant,
	 * and a preview string for each — which is worth fetching to install
	 * from, and not worth fetching to answer "is Fraunces available?". The
	 * index keeps the name, the slug and the categories, and nothing else.
	 */
	const INDEX_TRANSIENT = 'pattern_builder_font_index';

	/**
	 * Font file types a family may install, in order of preference.
	 *
	 * WOFF2 comes first because every browser this plugin's WordPress floor
	 * supports reads it, and it is roughly half the size of WOFF.
	 */
	const FILE_TYPES = array( 'woff2', 'woff', 'ttf', 'otf' );

	/**
	 * What a family installs when the caller does not say.
	 */
	const DEFAULT_WEIGHTS = array( '400', '700' );

	/**
	 * The collection, fetched in full.
	 *
	 * @return array|\WP_Error
	 */
	public static function collection() {
		if ( ! class_exists( '\WP_Font_Library' ) ) {
			return new \WP_Error(
				'pb_fonts_unavailable',
				__( 'This WordPress has no font library. Fonts can be installed from WordPress 6.5.', 'pattern-builder' ),
				array( 'status' => 501 )
			);
		}

		$collection = \WP_Font_Library::get_instance()->get_font_collection( self::COLLECTION );

		if ( ! $collection ) {
			return new \WP_Error(
				'pb_fonts_no_collection',
				__( 'The Google Fonts collection is not registered on this site.', 'pattern-builder' ),
				array( 'status' => 501 )
			);
		}

		$data = $collection->get_data();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['font_families'] ) || ! is_array( $data['font_families'] ) ) {
			return new \WP_Error(
				'pb_fonts_empty_collection',
				__( 'The font collection came back empty — the site could not reach it.', 'pattern-builder' ),
				array( 'status' => 502 )
			);
		}

		return $data;
	}

	/**
	 * Name, slug and categories for every family available.
	 *
	 * @return array|\WP_Error
	 */
	public static function index() {
		$cached = get_transient( self::INDEX_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = self::collection();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$index = array();

		foreach ( $data['font_families'] as $entry ) {
			$settings = isset( $entry['font_family_settings'] ) ? $entry['font_family_settings'] : array();

			if ( empty( $settings['name'] ) ) {
				continue;
			}

			$index[] = array(
				'name'       => (string) $settings['name'],
				'slug'       => isset( $settings['slug'] ) ? (string) $settings['slug'] : sanitize_title( $settings['name'] ),
				'categories' => isset( $entry['categories'] ) ? array_values( (array) $entry['categories'] ) : array(),
			);
		}

		set_transient( self::INDEX_TRANSIENT, $index, DAY_IN_SECONDS );

		return $index;
	}

	/**
	 * Families whose name matches, with what each offers.
	 *
	 * @param string $search   Substring of the family name.
	 * @param string $category Optional category slug, e.g. 'serif'.
	 * @param int    $limit    How many to return.
	 * @return array|\WP_Error
	 */
	public static function search( $search = '', $category = '', $limit = 20 ) {
		$index = self::index();

		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$search   = trim( (string) $search );
		$category = trim( (string) $category );
		$limit    = max( 1, min( 100, (int) $limit ) );
		$matches  = array();

		foreach ( $index as $family ) {
			if ( '' !== $search && false === stripos( $family['name'], $search ) ) {
				continue;
			}

			if ( '' !== $category && ! in_array( $category, $family['categories'], true ) ) {
				continue;
			}

			$matches[] = $family;

			if ( count( $matches ) >= $limit ) {
				break;
			}
		}

		return $matches;
	}

	/**
	 * One family from the collection, by name or slug.
	 *
	 * @param string $name Family name or slug.
	 * @return array|\WP_Error The `font_family_settings` array.
	 */
	public static function family( $name ) {
		$data = self::collection();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$wanted = strtolower( trim( (string) $name ) );
		$slug   = sanitize_title( $wanted );

		foreach ( $data['font_families'] as $entry ) {
			$settings = isset( $entry['font_family_settings'] ) ? $entry['font_family_settings'] : array();

			if ( empty( $settings['name'] ) ) {
				continue;
			}

			$entry_slug = isset( $settings['slug'] ) ? strtolower( (string) $settings['slug'] ) : sanitize_title( $settings['name'] );

			if ( strtolower( (string) $settings['name'] ) === $wanted || $entry_slug === $slug ) {
				return $settings;
			}
		}

		return new \WP_Error(
			'pb_font_not_found',
			sprintf(
				/* translators: %s: the font family name asked for. */
				__( 'No font family named "%s" in the Google Fonts collection. Call list-fonts to see what is available.', 'pattern-builder' ),
				$name
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * Install a family and register it so a pattern can reference its slug.
	 *
	 * @param string $name        Family name, as the collection lists it.
	 * @param array  $weights     Weights wanted, e.g. array( '400', '700' ).
	 * @param array  $styles      Styles wanted: 'normal', 'italic'.
	 * @param string $destination 'theme' or 'user'.
	 * @return array|\WP_Error
	 */
	public static function install( $name, $weights = array(), $styles = array(), $destination = 'theme' ) {
		$family = self::family( $name );

		if ( is_wp_error( $family ) ) {
			return $family;
		}

		$weights = $weights ? array_map( 'strval', (array) $weights ) : self::DEFAULT_WEIGHTS;
		$styles  = $styles ? array_map( 'strtolower', array_map( 'strval', (array) $styles ) ) : array( 'normal' );

		$faces = self::faces_for( $family, $weights, $styles );

		if ( is_wp_error( $faces ) ) {
			return $faces;
		}

		$slug  = isset( $family['slug'] ) ? sanitize_title( (string) $family['slug'] ) : sanitize_title( (string) $family['name'] );
		$stack = isset( $family['fontFamily'] ) ? (string) $family['fontFamily'] : '"' . $family['name'] . '", sans-serif';

		$installed = 'user' === $destination
			? self::install_into_library( $slug, (string) $family['name'], $stack, $faces )
			: self::install_into_theme( $slug, (string) $family['name'], $stack, $faces );

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		return array(
			'family'      => (string) $family['name'],
			'slug'        => $slug,
			'fontFamily'  => $stack,
			'destination' => 'user' === $destination ? 'user' : 'theme',
			'faces'       => $installed['faces'],
			'preset'      => $installed['preset'],
			// The whole point of installing it: what a pattern writes to use it.
			'reference'   => array(
				'attribute' => '"fontFamily":"' . $slug . '"',
				'class'     => 'has-' . $slug . '-font-family',
				'css'       => 'var(--wp--preset--font-family--' . $slug . ')',
			),
		);
	}

	/**
	 * Pick the collection's font faces matching the weights and styles asked
	 * for, tolerating a variable font that covers a weight as a range.
	 *
	 * @param array $family  Family settings from the collection.
	 * @param array $weights Weights wanted.
	 * @param array $styles  Styles wanted.
	 * @return array|\WP_Error
	 */
	private static function faces_for( $family, $weights, $styles ) {
		$available = isset( $family['fontFace'] ) && is_array( $family['fontFace'] ) ? $family['fontFace'] : array();

		if ( ! $available ) {
			return new \WP_Error(
				'pb_font_no_faces',
				sprintf(
					/* translators: %s: the font family name. */
					__( '"%s" lists no font files to install.', 'pattern-builder' ),
					isset( $family['name'] ) ? $family['name'] : ''
				),
				array( 'status' => 502 )
			);
		}

		$chosen = array();

		foreach ( $weights as $weight ) {
			foreach ( $styles as $style ) {
				$face = self::match_face( $available, $weight, $style );

				if ( ! $face ) {
					continue;
				}

				$src = self::preferred_src( $face );

				if ( '' === $src ) {
					continue;
				}

				// A variable font answers several weights with one file; keep
				// it once rather than downloading it per weight asked for.
				$key = $src . '|' . $style;

				if ( isset( $chosen[ $key ] ) ) {
					continue;
				}

				$chosen[ $key ] = array(
					'weight' => isset( $face['fontWeight'] ) ? (string) $face['fontWeight'] : (string) $weight,
					'style'  => $style,
					'src'    => $src,
				);
			}
		}

		if ( ! $chosen ) {
			return new \WP_Error(
				'pb_font_no_match',
				sprintf(
					/* translators: 1: the font family name, 2: the weights asked for, 3: the styles asked for. */
					__( '"%1$s" has no files for weight %2$s in %3$s. Call get-font to see which weights it offers.', 'pattern-builder' ),
					isset( $family['name'] ) ? $family['name'] : '',
					implode( ', ', $weights ),
					implode( ', ', $styles )
				),
				array( 'status' => 400 )
			);
		}

		return array_values( $chosen );
	}

	/**
	 * The face in a list that serves a given weight and style.
	 *
	 * @param array  $faces  Font face descriptors.
	 * @param string $weight Weight wanted.
	 * @param string $style  Style wanted.
	 * @return array|null
	 */
	private static function match_face( $faces, $weight, $style ) {
		$weight = (int) $weight;

		foreach ( $faces as $face ) {
			$face_style = isset( $face['fontStyle'] ) ? strtolower( (string) $face['fontStyle'] ) : 'normal';

			if ( $face_style !== $style ) {
				continue;
			}

			$face_weight = isset( $face['fontWeight'] ) ? trim( (string) $face['fontWeight'] ) : '400';

			// A variable font states its range as "100 900".
			if ( preg_match( '/^(\d+)\s+(\d+)$/', $face_weight, $range ) ) {
				if ( $weight >= (int) $range[1] && $weight <= (int) $range[2] ) {
					return $face;
				}
				continue;
			}

			if ( (int) $face_weight === $weight ) {
				return $face;
			}
		}

		return null;
	}

	/**
	 * The best file a face offers.
	 *
	 * @param array $face Font face descriptor.
	 * @return string
	 */
	private static function preferred_src( $face ) {
		$srcs = isset( $face['src'] ) ? (array) $face['src'] : array();

		foreach ( self::FILE_TYPES as $type ) {
			foreach ( $srcs as $src ) {
				$src  = (string) $src;
				$path = (string) wp_parse_url( $src, PHP_URL_PATH );

				if ( strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) === $type ) {
					return $src;
				}
			}
		}

		return $srcs ? (string) reset( $srcs ) : '';
	}

	/**
	 * Put the files in the theme and the preset in its theme.json.
	 *
	 * `file:./` is the placeholder `WP_Font_Face_Resolver` rewrites into a
	 * theme URI, which is what lets the font travel with the theme rather
	 * than depending on this site's uploads directory.
	 *
	 * @param string $slug   Family slug.
	 * @param string $name   Family name.
	 * @param string $stack  The font-family CSS value.
	 * @param array  $faces  Faces to install.
	 * @return array|\WP_Error
	 */
	private static function install_into_theme( $slug, $name, $stack, $faces ) {
		$directory = get_stylesheet_directory() . Pattern_Builder_Assets::THEME_FONT_DIR;

		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		$descriptors = array();
		$installed   = array();

		foreach ( $faces as $face ) {
			$filename = self::filename_for( $slug, $face );
			$file     = self::fetch( $face['src'] );

			if ( is_wp_error( $file ) ) {
				return $file;
			}

			$written = Pattern_Builder_Security::safe_file_write(
				$directory . $filename,
				$file,
				array(
					get_stylesheet_directory() . '/assets',
					get_template_directory() . '/assets',
				)
			);

			if ( is_wp_error( $written ) ) {
				return $written;
			}

			$descriptors[] = array(
				'fontFamily' => $name,
				'fontWeight' => $face['weight'],
				'fontStyle'  => $face['style'],
				'src'        => array( 'file:.' . Pattern_Builder_Assets::THEME_FONT_DIR . $filename ),
			);

			$installed[] = array(
				'weight' => $face['weight'],
				'style'  => $face['style'],
				'path'   => 'assets/fonts/' . $filename,
			);
		}

		$preset = self::write_preset( $slug, $name, $stack, $descriptors, 'theme' );

		if ( is_wp_error( $preset ) ) {
			return $preset;
		}

		return array(
			'faces'  => $installed,
			'preset' => $preset,
		);
	}

	/**
	 * Put the files in the uploads font directory, the preset in Global
	 * Styles, and register the family in the Font Library.
	 *
	 * The library posts are what the Manage Fonts screen lists, so without
	 * them the font renders but cannot be seen or removed by a person. Core's
	 * own REST controllers create them, which is why they are driven here
	 * rather than reimplemented — the duplicate checks and the post shape
	 * stay core's. Only the file placement is ours, because core's controller
	 * uses `wp_handle_upload()` and would reject a file this site downloaded
	 * rather than received from a browser.
	 *
	 * @param string $slug  Family slug.
	 * @param string $name  Family name.
	 * @param string $stack The font-family CSS value.
	 * @param array  $faces Faces to install.
	 * @return array|\WP_Error
	 */
	private static function install_into_library( $slug, $name, $stack, $faces ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$descriptors = array();
		$installed   = array();
		$uploads     = array();

		foreach ( $faces as $face ) {
			$file = self::fetch( $face['src'] );

			if ( is_wp_error( $file ) ) {
				return $file;
			}

			$sideloaded = self::sideload_font( self::filename_for( $slug, $face ), $file );

			if ( is_wp_error( $sideloaded ) ) {
				return $sideloaded;
			}

			$descriptors[] = array(
				'fontFamily' => $name,
				'fontWeight' => $face['weight'],
				'fontStyle'  => $face['style'],
				'src'        => array( $sideloaded['url'] ),
			);

			$uploads[] = array_merge( $face, $sideloaded );

			$installed[] = array(
				'weight' => $face['weight'],
				'style'  => $face['style'],
				'url'    => $sideloaded['url'],
			);
		}

		$preset = self::write_preset( $slug, $name, $stack, $descriptors, 'user' );

		if ( is_wp_error( $preset ) ) {
			return $preset;
		}

		$library = self::register_in_library( $slug, $name, $stack, $uploads );

		return array(
			'faces'   => $installed,
			'preset'  => $preset,
			'library' => is_wp_error( $library ) ? $library->get_error_message() : $library,
		);
	}

	/**
	 * Write the `fontFamily` preset that makes the font render.
	 *
	 * Goes through `Pattern_Builder_Cloud_Tokens::apply()` so that the two
	 * destinations, the never-overwrite rule and the value grammar stay one
	 * implementation shared with `add-design-tokens` and the cloud download.
	 *
	 * @param string $slug        Family slug.
	 * @param string $name        Family name.
	 * @param string $stack       The font-family CSS value.
	 * @param array  $descriptors `fontFace` entries.
	 * @param string $destination 'theme' or 'user'.
	 * @return array|\WP_Error
	 */
	private static function write_preset( $slug, $name, $stack, $descriptors, $destination ) {
		return Pattern_Builder_Cloud_Tokens::apply(
			array(
				array(
					'type'  => 'fontFamily',
					'slug'  => $slug,
					'name'  => $name,
					'value' => $stack,
					'extra' => array( 'fontFace' => $descriptors ),
				),
			),
			$destination
		);
	}

	/**
	 * Create the Font Library's own record of the family.
	 *
	 * @param string $slug    Family slug.
	 * @param string $name    Family name.
	 * @param string $stack   The font-family CSS value.
	 * @param array  $uploads Sideloaded files, each with url and relative path.
	 * @return array|\WP_Error
	 */
	private static function register_in_library( $slug, $name, $stack, $uploads ) {
		$family_id = self::existing_family_id( $slug );

		if ( ! $family_id ) {
			$request = new \WP_REST_Request( 'POST', '/wp/v2/font-families' );
			$request->set_param(
				'font_family_settings',
				wp_json_encode(
					array(
						'name'       => $name,
						'slug'       => $slug,
						'fontFamily' => $stack,
					)
				)
			);

			$response = rest_do_request( $request );

			if ( $response->is_error() ) {
				return $response->as_error();
			}

			$data      = $response->get_data();
			$family_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		}

		if ( ! $family_id ) {
			return new \WP_Error( 'pb_font_library_family', __( 'The font family could not be recorded in the font library.', 'pattern-builder' ) );
		}

		$faces = array();

		foreach ( $uploads as $upload ) {
			$request = new \WP_REST_Request( 'POST', '/wp/v2/font-families/' . $family_id . '/font-faces' );
			$request->set_url_params( array( 'font_family_id' => $family_id ) );
			$request->set_param(
				'font_face_settings',
				wp_json_encode(
					array(
						'fontFamily' => $name,
						'fontWeight' => $upload['weight'],
						'fontStyle'  => $upload['style'],
						// Already a URL on this site, so core stores it as
						// given and does not try to move a file.
						'src'        => $upload['url'],
					)
				)
			);

			$response = rest_do_request( $request );

			if ( $response->is_error() ) {
				// A duplicate face is the expected case on a re-install and
				// is not a failure: the file and the preset are both in place.
				continue;
			}

			$data    = $response->get_data();
			$face_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

			if ( $face_id && isset( $upload['relative'] ) ) {
				// The meta core uses to know the file is the site's own, and
				// so should be deleted with the face.
				add_post_meta( $face_id, '_wp_font_face_file', $upload['relative'] );
				$faces[] = $face_id;
			}
		}

		return array(
			'familyId' => $family_id,
			'faceIds'  => $faces,
		);
	}

	/**
	 * The id of a font family already in the library under this slug.
	 *
	 * @param string $slug Family slug.
	 * @return int
	 */
	private static function existing_family_id( $slug ) {
		$posts = get_posts(
			array(
				'post_type'      => 'wp_font_family',
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Move a font file into the uploads font directory.
	 *
	 * The two filters are the ones core's own controller installs: one
	 * redirects the upload into `wp-content/uploads/fonts`, the other allows
	 * font mime types for the duration. `wp_handle_sideload()` rather than
	 * `wp_handle_upload()` because the file was downloaded, not posted, and
	 * core only skips `is_uploaded_file()` for a sideload.
	 *
	 * @param string $filename Filename to store under.
	 * @param string $bytes    File contents.
	 * @return array|\WP_Error
	 */
	private static function sideload_font( $filename, $bytes ) {
		$temp = wp_tempnam( $filename );

		if ( ! $temp ) {
			return new \WP_Error( 'pb_font_no_temp', __( 'Could not open a temporary file for the font.', 'pattern-builder' ), array( 'status' => 500 ) );
		}

		$written = Pattern_Builder_Security::safe_file_write( $temp, $bytes, array( dirname( $temp ) ) );

		if ( is_wp_error( $written ) ) {
			wp_delete_file( $temp );
			return $written;
		}

		$mimes = \WP_Font_Utils::get_allowed_font_mime_types();

		add_filter( 'upload_mimes', array( '\WP_Font_Utils', 'get_allowed_font_mime_types' ) );
		add_filter( 'upload_dir', '_wp_filter_font_directory' );

		// Taken by reference, so it has to be a variable.
		$file = array(
			'name'     => $filename,
			'tmp_name' => $temp,
		);

		$uploaded = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);

		remove_filter( 'upload_dir', '_wp_filter_font_directory' );
		remove_filter( 'upload_mimes', array( '\WP_Font_Utils', 'get_allowed_font_mime_types' ) );

		if ( file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}

		if ( isset( $uploaded['error'] ) ) {
			return new \WP_Error( 'pb_font_upload_failed', (string) $uploaded['error'], array( 'status' => 500 ) );
		}

		$directory = wp_get_font_dir();
		$relative  = isset( $uploaded['file'] ) ? (string) $uploaded['file'] : '';

		if ( isset( $directory['basedir'] ) && 0 === strpos( $relative, $directory['basedir'] ) ) {
			$relative = ltrim( str_replace( $directory['basedir'], '', $relative ), '/' );
		}

		return array(
			'url'      => isset( $uploaded['url'] ) ? (string) $uploaded['url'] : '',
			'relative' => $relative,
		);
	}

	/**
	 * Fetch a font file.
	 *
	 * @param string $url Source URL.
	 * @return string|\WP_Error
	 */
	private static function fetch( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error(
				'pb_font_fetch_failed',
				sprintf(
					/* translators: 1: the URL, 2: the HTTP status. */
					__( 'Fetching the font file %1$s answered %2$d.', 'pattern-builder' ),
					$url,
					(int) wp_remote_retrieve_response_code( $response )
				),
				array( 'status' => 502 )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new \WP_Error( 'pb_font_fetch_empty', __( 'The font file came back empty.', 'pattern-builder' ), array( 'status' => 502 ) );
		}

		return $body;
	}

	/**
	 * A predictable filename for one face of a family.
	 *
	 * @param string $slug Family slug.
	 * @param array  $face Face, with weight, style and src.
	 * @return string
	 */
	private static function filename_for( $slug, $face ) {
		$path      = (string) wp_parse_url( $face['src'], PHP_URL_PATH );
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::FILE_TYPES, true ) ) {
			$extension = 'woff2';
		}

		// A variable font's weight is a range; the space would be escaped in
		// every URL that named the file.
		$weight = str_replace( ' ', '-', (string) $face['weight'] );

		return sanitize_file_name( $slug . '-' . $weight . '-' . $face['style'] . '.' . $extension );
	}
}
