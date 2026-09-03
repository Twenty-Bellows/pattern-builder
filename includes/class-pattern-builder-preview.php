<?php
/**
 * Serve a pattern as a page, so its design can be looked at rather than inferred.
 *
 * `render-pattern` answers with HTML, which settles whether the right classes
 * are on the right elements and nothing else. It cannot show that a band meant
 * to span the viewport is rendering at the content width, because that is
 * decided by stylesheets the answer does not carry — and an agent working over
 * HTTP has no other way to see the result.
 *
 * So this route returns a whole document with the site's own styles in it, at a
 * URL a browser can open. Two contexts, because they answer different questions:
 *
 *   standalone  The pattern by itself. Is its own design right?
 *   page        The pattern where a page would put it, inside the template's
 *               own wrappers. Does the theme let it be what it is? An
 *               `alignfull` band only escapes the content width if the chain
 *               above it allows one to, and nothing in a pattern says whether
 *               it does.
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
	 *
	 * Well above any real auto-increment, and never written anywhere: the post
	 * is primed into the object cache for one request and discarded with it.
	 */
	const STAND_IN_ID = 999000001;

	/**
	 * The document to serve, held between the route callback and the filter
	 * that writes it, because a REST response would otherwise be JSON.
	 *
	 * @var string|null
	 */
	private $document = null;

	/**
	 * Hook the route.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
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
				),
			)
		);
	}

	/**
	 * Previewing shows the site's own content, so it takes the same capability
	 * as reading a pattern does.
	 *
	 * @return bool
	 */
	public function can_preview() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * The URL that renders a pattern.
	 *
	 * @param string $id      Pattern id.
	 * @param string $context 'standalone' or 'page'.
	 * @return string
	 */
	public static function url_for( $id, $context = 'standalone' ) {
		return add_query_arg(
			array(
				'pattern' => rawurlencode( $id ),
				'context' => $context,
			),
			rest_url( self::REST_NAMESPACE . '/preview' )
		);
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

		$this->document = 'page' === $request->get_param( 'context' )
			? $this->page_document( $pattern )
			: $this->standalone_document( $pattern );

		add_filter( 'rest_pre_serve_request', array( $this, 'write_document' ) );

		return new \WP_REST_Response( null, 200 );
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

		// The document is assembled from rendered blocks, which are already
		// escaped by the blocks that produced them.
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
	 * The point of this context is the wrappers, so the pattern goes in as a
	 * page's content and `core/post-content` renders it — which means there has
	 * to be a post. Rather than write one, a stand-in is primed into the object
	 * cache for this request and handed to the blocks through their context.
	 *
	 * @param Abstract_Pattern $pattern The pattern.
	 * @return string
	 */
	private function page_document( $pattern ) {
		$template = $this->page_template();

		if ( '' === $template ) {
			// No block template to render into. Saying so beats silently
			// answering a different question than the one that was asked.
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

		// Deliberate and paired with stop_posing(): core/post-content reads the
		// global rather than its own context, so this is the only way to tell it
		// what to render. The previous value is put back before the request ends.
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

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
			// Restoring what pose_as_a_page() displaced.
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
	 * `wp_head()` is what makes this worth serving at all: it brings the theme's
	 * global styles, the block library's stylesheets and the layout rules that
	 * decide what an alignment does.
	 *
	 * @param string           $html    Rendered blocks.
	 * @param Abstract_Pattern $pattern The pattern.
	 * @return string
	 */
	private function document_around( $html, $pattern ) {
		ob_start();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html( $pattern->title ); ?></title>
		<?php wp_head(); ?>
</head>
<body class="pattern-builder-preview wp-embed-responsive">
		<?php
		// Rendered block output, escaped by the blocks that produced it.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
