<?php
/**
 * Composes patterns for everything the editor loads.
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Block_Patterns_Registry;
use WP_Block_Template;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Template_Autosaves_Controller;
use WP_REST_Template_Revisions_Controller;
use WP_REST_Templates_Controller;

/**
 * Runs `Pattern_Resolver` over the markup the block editor loads.
 */
class Editor_Support {
	/**
	 * REST route the block editor loads patterns from.
	 */
	const PATTERNS_ROUTE = '/wp/v2/block-patterns/patterns';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Content of the templates this request has loaded for the editor that place a pattern,
	 * by template ID, as the last filter left it.
	 *
	 * @var array<string, string>
	 */
	private $template_content = array();

	/**
	 * Constructor.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	public function __construct( string $file ) {
		$this->file = $file;
	}

	/**
	 * Registers the editor hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_request_after_callbacks', array( $this, 'resolve_patterns_response' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'forget_templates' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'resolve_templates_response' ), 10, 3 );
		add_filter( 'get_block_templates', array( $this, 'resolve_templates' ) );
		add_filter( 'get_block_template', array( $this, 'resolve_template' ) );
		add_filter( 'get_block_file_template', array( $this, 'resolve_template' ) );
		add_filter( 'get_block_templates', array( $this, 'remember_templates' ), PHP_INT_MAX );
		add_filter( 'get_block_template', array( $this, 'remember_template' ), PHP_INT_MAX );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Composes the patterns the editor lists, previews and inserts.
	 *
	 * @param WP_REST_Response|mixed $response Result to send to the client.
	 * @param array|mixed            $handler Route handler used for the request.
	 * @param WP_REST_Request|mixed  $request Request used to generate the response.
	 * @return WP_REST_Response|mixed The response.
	 */
	public function resolve_patterns_response( $response, $handler, $request ) {
		if ( ! $response instanceof WP_REST_Response
			|| ! $request instanceof WP_REST_Request
			|| self::PATTERNS_ROUTE !== $request->get_route()
		) {
			return $response;
		}

		$patterns = $response->get_data();

		if ( ! is_array( $patterns ) ) {
			return $response;
		}

		$registry   = WP_Block_Patterns_Registry::get_instance();
		$companions = array();
		$changed    = false;

		foreach ( $patterns as $index => $pattern ) {
			if ( ! isset( $pattern['name'], $pattern['content'] ) || ! $registry->is_registered( $pattern['name'] ) ) {
				continue;
			}

			$registered = $registry->get_registered( $pattern['name'] );
			$markup     = $registered['content'] ?? '';

			if ( Pattern_Resolver::contains_pattern_block( $markup ) ) {
				$patterns[ $index ]['content'] = Pattern_Resolver::compose( $markup );

				$changed = true;
			}

			$companion = $this->build_companion_pattern( $patterns[ $index ] );

			if ( null !== $companion ) {
				$patterns[ $index ]['inserter'] = false;
				$companions[]                   = $companion;
				$changed                        = true;
			}
		}

		if ( $changed ) {
			$response->set_data( array_merge( $patterns, $companions ) );
		}

		return $response;
	}

	/**
	 * Builds the entry that offers a synced pattern to the inserter.
	 *
	 * @param array $pattern A prepared pattern from the REST response.
	 * @return array|null The companion entry, or null if the pattern needs none.
	 */
	private function build_companion_pattern( array $pattern ): ?array {
		if ( ! Synced_Patterns::is_synced( $pattern['name'] ) ) {
			return null;
		}
		if ( isset( $pattern['inserter'] ) && ! $pattern['inserter'] ) {
			return null;
		}

		$companion             = $pattern;
		$companion['name']     = Synced_Patterns::get_inserter_slug( $pattern['name'] );
		$companion['content']  = Synced_Patterns::get_reference_markup( $pattern['name'] );
		$companion['inserter'] = true;

		return $companion;
	}

	/**
	 * Composes the patterns used by a list of templates.
	 *
	 * @param WP_Block_Template[]|mixed $templates Templates being returned.
	 * @return WP_Block_Template[]|mixed The templates.
	 */
	public function resolve_templates( $templates ) {
		if ( ! is_array( $templates ) ) {
			return $templates;
		}

		foreach ( $templates as $index => $template ) {
			$templates[ $index ] = $this->resolve_template( $template );
		}

		return $templates;
	}

	/**
	 * Composes the patterns used by a single template or template part.
	 *
	 * @param WP_Block_Template|mixed $template Template being returned.
	 * @return WP_Block_Template|mixed The template.
	 */
	public function resolve_template( $template ) {
		if ( ! $template instanceof WP_Block_Template || ! self::is_editor_request() ) {
			return $template;
		}

		$template->content = Pattern_Resolver::resolve( $template->content );

		return $template;
	}

	/**
	 * Remembers a list of templates for the response the editor is sent.
	 *
	 * @param WP_Block_Template[]|mixed $templates Templates being returned.
	 * @return WP_Block_Template[]|mixed The templates, unchanged.
	 */
	public function remember_templates( $templates ) {
		if ( is_array( $templates ) ) {
			array_map( array( $this, 'remember_template' ), $templates );
		}

		return $templates;
	}

	/**
	 * Remembers a template's content, as every other filter left it, for the response the
	 * editor is sent.
	 *
	 * @param WP_Block_Template|mixed $template Template being returned.
	 * @return WP_Block_Template|mixed The template, unchanged.
	 */
	public function remember_template( $template ) {
		// Only the first load counts: the controller writes the flattened content into a
		// plugin template's shared object, and a later load hands that back.
		if ( $template instanceof WP_Block_Template
			&& is_string( $template->id )
			&& ! isset( $this->template_content[ $template->id ] )
			&& Pattern_Resolver::contains_pattern_block( $template->content )
			&& self::is_editor_request()
		) {
			$this->template_content[ $template->id ] = $template->content;
		}

		return $template;
	}

	/**
	 * Forgets the templates an earlier request remembered.
	 *
	 * @param WP_REST_Response|mixed $response Result to send to the client, if a filter has already decided it.
	 * @return WP_REST_Response|mixed The same value, unchanged.
	 */
	public function forget_templates( $response ) {
		$this->template_content = array();

		return $response;
	}

	/**
	 * Recomposes the templates the editor is sent after core's templates controller has
	 * flattened them, so synced references survive with their content.
	 *
	 * @param WP_REST_Response|mixed $response Result to send to the client.
	 * @param array|mixed            $handler Route handler used for the request.
	 * @param WP_REST_Request|mixed  $request Request used to generate the response.
	 * @return WP_REST_Response|mixed The response.
	 */
	public function resolve_templates_response( $response, $handler, $request ) {
		$controller = self::get_controller( $handler );

		if ( ! $response instanceof WP_REST_Response
			|| ! $request instanceof WP_REST_Request
			|| ! ( $controller instanceof WP_REST_Templates_Controller
				|| $controller instanceof WP_REST_Template_Revisions_Controller
				|| $controller instanceof WP_REST_Template_Autosaves_Controller )
			|| ! self::is_editor_request()
		) {
			return $response;
		}

		$data = $response->get_data();

		// A lookup that finds nothing answers with an empty object.
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$is_list   = wp_is_numeric_array( $data );
		$templates = $is_list ? $data : array( $data );
		$changed   = false;

		foreach ( $templates as $index => $template ) {
			$content = $this->recompose_template( $template );

			if ( null !== $content ) {
				$templates[ $index ]['content']['raw'] = $content;

				$changed = true;
			}
		}

		if ( $changed ) {
			$response->set_data( $is_list ? $templates : $templates[0] );
		}

		return $response;
	}

	/**
	 * Recomposes one template from a response, if it places a synced pattern or, stored as a
	 * post, a pattern that carries content.
	 *
	 * @param array|mixed $template A prepared template from the response.
	 * @return string|null The content for the editor, or null to leave the template as core prepared it.
	 */
	private function recompose_template( $template ): ?string {
		if ( ! is_array( $template ) || ! isset( $template['content']['raw'] ) ) {
			return null;
		}

		// Core builds a template stored as a post without running get_block_template, so
		// nothing was remembered for it.
		$content = empty( $template['wp_id'] )
			? ( $this->template_content[ $template['id'] ?? '' ] ?? '' )
			: self::get_post_template_content( $template['wp_id'] );

		if ( ! Pattern_Resolver::contains_pattern_block( $content ) ) {
			return null;
		}

		$composed = Pattern_Resolver::compose( $content );

		if ( Pattern_Resolver::resolve( $content ) === $content && ! self::has_synced_reference( parse_blocks( $composed ) ) ) {
			return null;
		}

		return $composed;
	}

	/**
	 * Builds a template's content from its post the way the controllers do before flattening
	 * it, hooked blocks and all.
	 *
	 * @param int|mixed $post_id The template's, revision's or autosave's post ID.
	 * @return string The content, or an empty string if there's no such post.
	 */
	private static function get_post_template_content( $post_id ): string {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return '';
		}

		$template = _build_block_template_result_from_post( $post );

		return $template instanceof WP_Block_Template ? (string) $template->content : '';
	}

	/**
	 * Finds the controller a REST route is handled by.
	 *
	 * @param array|mixed $handler Route handler used for the request.
	 * @return object|null The controller, or null if a controller doesn't handle the route.
	 */
	private static function get_controller( $handler ): ?object {
		$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;

		return is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ? $callback[0] : null;
	}

	/**
	 * Determines whether parsed blocks reference a synced pattern anywhere.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return bool Whether one of them, at any depth, is a synced reference.
	 */
	private static function has_synced_reference( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( 'core/pattern' === ( $block['blockName'] ?? null )
				&& Synced_Patterns::is_synced( $block['attrs']['slug'] ?? null )
			) {
				return true;
			}

			if ( ! empty( $block['innerBlocks'] ) && self::has_synced_reference( $block['innerBlocks'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines whether the current request is loading content for the editor.
	 *
	 * @return bool Whether template content should be composed for this request.
	 */
	public static function is_editor_request(): bool {
		/**
		 * Filters whether template content is composed for the current request.
		 *
		 * @since 2.0.0
		 * @param bool $is_editor_request Whether this request loads content for the editor.
		 */
		return (bool) apply_filters(
			'pattern_builder_is_editor_request',
			wp_is_serving_rest_request()
		);
	}

	/**
	 * Enqueues the editor script.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$asset_file = plugin_dir_path( $this->file ) . 'build/PatternBuilder_Runtime.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'pattern-builder-runtime',
			plugins_url( 'build/PatternBuilder_Runtime.js', $this->file ),
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);
		wp_set_script_translations(
			'pattern-builder-runtime',
			'pattern-builder'
		);

		wp_add_inline_script(
			'pattern-builder-runtime',
			sprintf(
				'window.patternBuilder = Object.assign( window.patternBuilder || {}, %s );',
				wp_json_encode( array( 'syncedPatterns' => Synced_Patterns::get_slugs() ) )
			),
			'before'
		);
	}
}
