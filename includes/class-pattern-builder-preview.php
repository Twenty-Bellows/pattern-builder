<?php
/**
 * Serve a pattern as a page, so its design can be looked at rather than inferred.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The preview document.
 */
class Pattern_Builder_Preview {
	const REST_NAMESPACE = 'pattern-builder/v1';

	/**
	 * A post id no site will have, for the page a preview poses as.
	 */
	const STAND_IN_ID = 999000001;

	/**
	 * The query argument that asks the front end for a pattern's tile.
	 */
	const TILE_QUERY_VAR = 'pattern_builder_tile';

	/**
	 * The document to serve, held between the route callback and the filter that writes it,
	 * because a REST response would otherwise be JSON.
	 *
	 * @var string|null
	 */
	private $document = null;

	/**
	 * The theme this request is rendering against, when it is not the active one.
	 *
	 * @var array
	 */
	private $worn = array();

	/**
	 * Presets the pattern brought with it, for a render against another theme.
	 *
	 * @var array
	 */
	private $carried = array();

	/**
	 * Hook the route, and the tile ahead of the admin bar's own setup (which runs at 0) so
	 * a tile is never drawn with one.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'serve_tile' ), -1 );
	}

	/**
	 * Register the preview route.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve' ),
				'permission_callback' => array( $this, 'can_preview' ),
				'args'                => array(
					'pattern' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'The pattern to render: a theme pattern\'s name, or a user pattern\'s post ID.', 'pattern-builder' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'standalone', 'page' ),
						'default'     => 'standalone',
						'description' => __( 'How to render it: by itself, or inside the page template.', 'pattern-builder' ),
					),
					'tokens'  => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Carry the presets this pattern references, at this site\'s values, into the theme being rendered against — the same ones an upload would ship, filling only what that theme lacks, as a download does. On by default: against a theme with no design system this is the difference between seeing the pattern as designed and seeing it with every reference resolving to nothing.', 'pattern-builder' ),
					),
					'theme'   => array(
						'type'        => 'string',
						'default'     => '',
						'description' => __( 'Render against this theme instead of the active one — "blank-theme" for a design system of nothing, "opinionated-theme" for one that is not yours, or any installed theme\'s slug. The site\'s active theme is not changed; the swap lasts for this request only.', 'pattern-builder' ),
					),
				),
			)
		);
	}

	/**
	 * Previewing shows the site's own content, so it takes the same capability as reading a
	 * pattern does.
	 *
	 * @return bool
	 */
	public function can_preview() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * The URL that renders a pattern.
	 *
	 * @param string $id Pattern id.
	 * @param string $context 'standalone' or 'page'.
	 * @param string $theme Optional theme slug to render against instead of the active one.
	 * @return string
	 */
	public static function url_for( $id, $context = 'standalone', $theme = '' ) {
		$args = array(
			'pattern' => rawurlencode( $id ),
			'context' => $context,
		);

		if ( '' !== $theme ) {
			$args['theme'] = $theme;
		}

		return add_query_arg( $args, rest_url( self::REST_NAMESPACE . '/preview' ) );
	}

	/**
	 * Where the browse grid asks for tiles: the front end, which the grid adds the pattern
	 * and its cache key to.
	 *
	 * @return string
	 */
	public static function tile_base() {
		return home_url( '/' );
	}

	/**
	 * What every tile's render depends on besides the patterns in it: the theme and its
	 * design system (theme.json, its style partials — block style variations among them —
	 * its stylesheet and functions.php), the site's Global Styles, and the software drawing
	 * it all.
	 *
	 * @return string
	 */
	public static function design_version() {
		$parts = array(
			get_bloginfo( 'version' ),
			PATTERN_BUILDER_VERSION,
			get_stylesheet(),
			get_template(),
			wp_json_encode( (array) get_option( 'active_plugins', array() ) ),
			is_multisite() ? wp_json_encode( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : '',
		);

		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $directory ) {
			$files = array_merge(
				array( $directory . '/theme.json', $directory . '/style.css', $directory . '/functions.php' ),
				(array) glob( $directory . '/styles/*.json' ),
				(array) glob( $directory . '/styles/*/*.json' )
			);
			foreach ( $files as $file ) {
				$parts[] = $file . '@' . ( is_file( $file ) ? filemtime( $file ) : 0 );
			}
		}

		$global_styles = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$parts[]       = isset( $global_styles['post_modified_gmt'] ) ? $global_styles['post_modified_gmt'] : '';

		return substr( md5( implode( '|', $parts ) ), 0, 12 );
	}

	/**
	 * Serve a tile, when the front-end request asks for one.
	 */
	public function serve_tile() {
		if ( ! isset( $_GET[ self::TILE_QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; see above.
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$tile = $this->tile(
			sanitize_text_field( wp_unslash( $_GET[ self::TILE_QUERY_VAR ] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			! empty( $_GET['v'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);

		if ( ! headers_sent() ) {
			header_remove( 'Expires' );
			header_remove( 'Pragma' );
			status_header( $tile['status'] );
			foreach ( $tile['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		echo $tile['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered blocks, escaped by the blocks that produced them.
		exit;
	}

	/**
	 * A pattern's tile: the response serve_tile() sends, built without sending it.
	 *
	 * @param string $id Pattern id.
	 * @param bool   $versioned Whether the request carries a cache key.
	 * @return array { status: int, headers: array, body: string }
	 */
	public function tile( $id, $versioned ) {
		$headers = array(
			'Content-Type'            => 'text/html; charset=' . get_option( 'blog_charset' ),
			'X-Robots-Tag'            => 'noindex',
			'Content-Security-Policy' => 'frame-ancestors ' . self::frame_ancestors(),
			'Cache-Control'           => 'no-store',
		);

		if ( ! $this->can_preview() ) {
			return array(
				'status'  => is_user_logged_in() ? 403 : 401,
				'headers' => $headers,
				'body'    => '',
			);
		}

		$pattern = $this->find( $id );
		if ( is_wp_error( $pattern ) ) {
			return array(
				'status'  => 404,
				'headers' => $headers,
				'body'    => '',
			);
		}

		show_admin_bar( false );

		if ( $versioned ) {
			$headers['Cache-Control'] = 'private, max-age=31536000, immutable';
		}

		return array(
			'status'  => 200,
			'headers' => $headers,
			'body'    => self::without_scripts( $this->document_around( do_blocks( $pattern->content ), $pattern, true ) ),
		);
	}

	/**
	 * Who may frame a tile: this site, and the admin when it lives elsewhere.
	 *
	 * @return string
	 */
	private static function frame_ancestors() {
		$sources = array( "'self'" );
		$admin   = wp_parse_url( admin_url() );

		if ( ! empty( $admin['scheme'] ) && ! empty( $admin['host'] ) ) {
			$sources[] = $admin['scheme'] . '://' . $admin['host'] . ( isset( $admin['port'] ) ? ':' . $admin['port'] : '' );
		}

		return implode( ' ', $sources );
	}

	/**
	 * A tile is a picture of a pattern: the site's front-end scripts would run once per
	 * tile and do nothing a picture needs, so they are left out.
	 *
	 * @param string $html The document.
	 * @return string
	 */
	private static function without_scripts( $html ) {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>\s*#is', '', $html );

		return (string) preg_replace( '#<link\b[^>]*\brel=(["\'])modulepreload\1[^>]*>\s*#i', '', (string) $html );
	}

	/**
	 * The themes this plugin carries for pattern work.
	 *
	 * @return array Slug => absolute directory.
	 */
	public static function bundled_themes() {
		$dir    = plugin_dir_path( PATTERN_BUILDER_FILE ) . 'themes/';
		$themes = array();

		foreach ( array( 'blank-theme', 'opinionated-theme' ) as $slug ) {
			if ( is_dir( $dir . $slug ) ) {
				$themes[ $slug ] = $dir . $slug;
			}
		}

		return $themes;
	}

	/**
	 * Build the document and arrange for it to be written as HTML.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function serve( $request ) {
		$pattern = $this->find( (string) $request->get_param( 'pattern' ) );

		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}

		$theme = (string) $request->get_param( 'theme' );

		if ( '' !== $theme ) {
			$carry = $request->get_param( 'tokens' );
			$carry = null === $carry ? true : (bool) $carry;
			$bring = $carry ? Pattern_Builder_Cloud_Tokens::collect_tree( (string) $pattern->content ) : array();

			$wearing = $this->wear_theme( $theme );

			if ( is_wp_error( $wearing ) ) {
				return $wearing;
			}

			if ( $bring ) {
				$this->carry_tokens( $bring );
			}
		}

		$this->document = 'page' === $request->get_param( 'context' )
			? $this->page_document( $pattern )
			: $this->standalone_document( $pattern );

		if ( '' !== $theme ) {
			$this->take_theme_off();
		}

		add_filter( 'rest_pre_serve_request', array( $this, 'write_document' ) );

		return new \WP_REST_Response( null, 200 );
	}

	/**
	 * Render as though a different theme were active, for this request only.
	 *
	 * @param string $slug Theme slug.
	 * @return true|\WP_Error
	 */
	private function wear_theme( $slug ) {
		$slug = sanitize_key( $slug );

		$bundled = self::bundled_themes();

		if ( isset( $bundled[ $slug ] ) ) {
			$this->worn = array(
				'slug'      => $slug,
				'directory' => untrailingslashit( $bundled[ $slug ] ),
			);
		} else {
			$theme = wp_get_theme( $slug );

			if ( ! $theme->exists() ) {
				return new \WP_Error(
					'pb_preview_no_theme',
					sprintf(
						/* translators: 1: the theme slug asked for, 2: the slugs this plugin carries. */
						__( 'No theme named "%1$s". This plugin carries %2$s; any theme installed on this site can be named as well.', 'pattern-builder' ),
						$slug,
						implode( ', ', array_keys( $bundled ) )
					),
					array( 'status' => 404 )
				);
			}

			$this->worn = array(
				'slug'      => $slug,
				'directory' => untrailingslashit( $theme->get_stylesheet_directory() ),
			);
		}
		if ( isset( $bundled[ $slug ] ) ) {
			register_theme_directory( plugin_dir_path( PATTERN_BUILDER_FILE ) . 'themes' );
		}

		foreach ( array( 'stylesheet', 'template' ) as $which ) {
			add_filter( $which, array( $this, 'worn_slug' ), 99 );
			add_filter( $which . '_directory', array( $this, 'worn_directory' ), 99 );
		}
		delete_site_transient( 'theme_roots' );

		$functions = $this->worn['directory'] . '/functions.php';

		if ( isset( $bundled[ $slug ] ) && file_exists( $functions ) ) {
			require_once $functions;
			$this->worn['boot'] = str_replace( '-', '_', $slug );

			if ( function_exists( $this->worn['boot'] . '_boot' ) ) {
				call_user_func( $this->worn['boot'] . '_boot' );
			}
		}

		wp_clean_theme_json_cache();

		return true;
	}

	/**
	 * Give the worn theme the presets the pattern brought, where it has none.
	 *
	 * @param array $tokens Tokens collected from the authoring site.
	 */
	private function carry_tokens( $tokens ) {
		$this->carried = Pattern_Builder_Cloud_Tokens::missing( $tokens );

		if ( ! $this->carried ) {
			return;
		}

		add_filter( 'wp_theme_json_data_theme', array( $this, 'add_carried_tokens' ) );

		wp_clean_theme_json_cache();
	}

	/**
	 * Merge the carried presets into the worn theme's data.
	 *
	 * @param \WP_Theme_JSON_Data $theme_json The worn theme's data.
	 * @return \WP_Theme_JSON_Data
	 */
	public function add_carried_tokens( $theme_json ) {
		$types    = Pattern_Builder_Cloud_Tokens::types();
		$settings = array();

		foreach ( $this->carried as $token ) {
			if ( ! isset( $types[ $token['type'] ] ) ) {
				continue;
			}

			list( $group, $key ) = $types[ $token['type'] ]['path'];

			$settings[ $group ][ $key ][] = array(
				'slug'                                => $token['slug'],
				'name'                                => isset( $token['name'] ) ? $token['name'] : $token['slug'],
				$types[ $token['type'] ]['value_key'] => $token['value'],
			);
		}

		if ( ! $settings ) {
			return $theme_json;
		}

		return $theme_json->update_with(
			array(
				'version'  => 3,
				'settings' => $settings,
			)
		);
	}

	/**
	 * Stop wearing it, and leave the caches as they were found.
	 */
	private function take_theme_off() {
		remove_filter( 'wp_theme_json_data_theme', array( $this, 'add_carried_tokens' ) );
		$this->carried = array();

		if ( isset( $this->worn['boot'] ) && function_exists( $this->worn['boot'] . '_unboot' ) ) {
			call_user_func( $this->worn['boot'] . '_unboot' );
		}

		foreach ( array( 'stylesheet', 'template' ) as $which ) {
			remove_filter( $which, array( $this, 'worn_slug' ), 99 );
			remove_filter( $which . '_directory', array( $this, 'worn_directory' ), 99 );
		}

		$this->worn = array();

		wp_clean_theme_json_cache();
	}

	/**
	 * The slug of the theme being worn.
	 *
	 * @return string
	 */
	public function worn_slug() {
		return isset( $this->worn['slug'] ) ? $this->worn['slug'] : '';
	}

	/**
	 * The directory of the theme being worn.
	 *
	 * @return string
	 */
	public function worn_directory() {
		return isset( $this->worn['directory'] ) ? $this->worn['directory'] : '';
	}

	/**
	 * Write the document instead of a JSON body.
	 *
	 * @param bool $served Whether the request has already been served.
	 * @return bool
	 */
	public function write_document( $served ) {
		if ( null === $this->document ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			header( 'X-Robots-Tag: noindex' );
		}
		echo $this->document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return true;
	}

	/**
	 * The pattern alone, with the site's stylesheets.
	 *
	 * @param Abstract_Pattern $pattern The pattern.
	 * @return string
	 */
	private function standalone_document( $pattern ) {
		return $this->document_around( do_blocks( $pattern->content ), $pattern );
	}

	/**
	 * The pattern inside the page template, wrappers and all.
	 *
	 * @param Abstract_Pattern $pattern The pattern.
	 * @return string
	 */
	private function page_document( $pattern ) {
		$template = $this->page_template();

		if ( '' === $template ) {
			return $this->document_around(
				'<!-- pattern-builder: this theme has no block template for a page; rendered standalone -->'
					. do_blocks( $pattern->content ),
				$pattern
			);
		}

		$this->pose_as_a_page( $pattern->content );

		$html = do_blocks( $template );

		$this->stop_posing();

		return $this->document_around( $html, $pattern );
	}

	/**
	 * The block template a page would be rendered with.
	 *
	 * @return string Template content, or an empty string.
	 */
	private function page_template() {
		if ( ! function_exists( 'resolve_block_template' ) ) {
			require_once ABSPATH . WPINC . '/block-template.php';
		}

		$template = resolve_block_template( 'page', array( 'page', 'singular', 'index' ), '' );

		return ( $template && ! empty( $template->content ) ) ? $template->content : '';
	}

	/**
	 * The global post the stand-in displaced, to be put back.
	 *
	 * @var \WP_Post|null
	 */
	private $displaced_post = null;

	/**
	 * Put a stand-in page in front of the blocks that ask for one.
	 *
	 * Two things are needed, and the second is not what the first suggests.
	 * `core/post-content` checks `$block->context['postId']` and returns nothing
	 * without it — but having passed that guard it calls `get_the_content()`
	 * with *no arguments*, deliberately, so that a preview of the queried object
	 * can apply. That reads the global post and the `$pages` globals
	 * `setup_postdata()` fills in, not the context. So the context makes the
	 * block agree to render and the globals decide what it renders.
	 *
	 * @param string $content The pattern's markup, as the page's content.
	 */
	private function pose_as_a_page( $content ) {
		$post = new \WP_Post(
			(object) array(
				'ID'             => self::STAND_IN_ID,
				'post_author'    => 0,
				'post_date'      => current_time( 'mysql' ),
				'post_date_gmt'  => current_time( 'mysql', true ),
				'post_content'   => $content,
				'post_title'     => __( 'Preview', 'pattern-builder' ),
				'post_excerpt'   => '',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_name'      => 'pattern-builder-preview',
				'post_parent'    => 0,
				'guid'           => home_url( '/?p=' . self::STAND_IN_ID ),
				'menu_order'     => 0,
				'post_type'      => 'page',
				'post_mime_type' => '',
				'comment_count'  => 0,
				'filter'         => 'raw',
			)
		);

		wp_cache_add( self::STAND_IN_ID, $post, 'posts' );

		$this->displaced_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post']      = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$GLOBALS['pattern_builder_preview_post'] = $post;
		setup_postdata( $post );

		add_filter( 'render_block_context', array( $this, 'supply_the_page' ), 10, 1 );
	}

	/**
	 * Take the stand-in away again.
	 */
	private function stop_posing() {
		remove_filter( 'render_block_context', array( $this, 'supply_the_page' ), 10 );

		wp_reset_postdata();

		if ( null === $this->displaced_post ) {
			unset( $GLOBALS['post'] );
		} else {
			$GLOBALS['post'] = $this->displaced_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$this->displaced_post = null;

		wp_cache_delete( self::STAND_IN_ID, 'posts' );
		unset( $GLOBALS['pattern_builder_preview_post'] );
	}

	/**
	 * Tell every block which page it is being rendered about.
	 *
	 * @param array $context Block context.
	 * @return array
	 */
	public function supply_the_page( $context ) {
		$context['postId']   = self::STAND_IN_ID;
		$context['postType'] = 'page';

		return $context;
	}

	/**
	 * Wrap rendered blocks in a document carrying the site's styles.
	 *
	 * @param string           $html Rendered blocks.
	 * @param Abstract_Pattern $pattern The pattern.
	 * @param bool             $tile Whether this is a grid tile rather than a full preview.
	 * @return string
	 */
	private function document_around( $html, $pattern, $tile = false ) {
		ob_start();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $pattern->title ); ?></title>
		<?php wp_head(); ?>
		<?php if ( $tile ) : ?>
<style id="pattern-builder-tile">
	html, body { margin: 0; padding: 0; background: #fff; }
	body { pointer-events: none; }
	.pattern-builder-tile { min-height: 100vh; display: grid; align-content: safe center; }
	.pattern-builder-tile__content { display: flow-root; }
	.pattern-builder-tile__content > * { margin-top: 0 !important; }
</style>
		<?php endif; ?>
</head>
<body class="pattern-builder-preview wp-embed-responsive">
		<?php
		if ( $tile ) {
			echo '<div class="pattern-builder-tile"><div class="pattern-builder-tile__content">';
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $tile ) {
			echo '</div></div>';
		}
		wp_footer();
		?>
</body>
</html>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Find a pattern by name or post ID.
	 *
	 * @param string $id Pattern id.
	 * @return Abstract_Pattern|\WP_Error
	 */
	private function find( $id ) {
		if ( '' === $id ) {
			return new \WP_Error(
				'pb_preview_no_pattern',
				__( 'Name a pattern to preview.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( ctype_digit( $id ) ) {
			$post = get_post( (int) $id );

			if ( $post && 'wp_block' === $post->post_type ) {
				return Abstract_Pattern::from_post( $post );
			}
		}

		$store   = new Pattern_File_Store();
		$pattern = $store->find_theme_pattern( $id );

		if ( ! $pattern ) {
			return new \WP_Error(
				'pb_preview_not_found',
				sprintf(
					/* translators: %s: the pattern id that was asked for. */
					__( 'No pattern named "%s" on this site.', 'pattern-builder' ),
					$id
				),
				array( 'status' => 404 )
			);
		}

		return $pattern;
	}
}
