<?php
namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_REST_Response;

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-file-store.php';

/**
 * First-party REST endpoints that are not part of the patterns controller.
 */
class Pattern_Builder_API {
	/**
	 * Pattern file store.
	 *
	 * @var Pattern_File_Store
	 */
	private $store;

	/**
	 * Constructor: hooks route registration.
	 */
	public function __construct() {
		$this->store = new Pattern_File_Store();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'pattern_builder_binding_fields', array( $this, 'add_post_data_fields' ), 10, 2 );
	}

	/**
	 * Registers the plugin's non-entity REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'pattern-builder/v1',
			'/process-theme',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_theme_patterns' ),
				'permission_callback' => array( $this, 'write_permission_callback' ),
			)
		);

		register_rest_route(
			'pattern-builder/v1',
			'/binding-fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_binding_fields' ),
				'permission_callback' => array( $this, 'read_permission_callback' ),
				'args'                => array(
					'post_type' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Permission callback for read-only endpoints.
	 *
	 * @return true|WP_Error
	 */
	public function read_permission_callback() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to read pattern data.', 'pattern-builder' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * The bindable fields each registered source offers for a post type.
	 *
	 * A binding source registered in PHP has no way to publish a field list:
	 * `register_block_bindings_source()` rejects any property beyond `label`,
	 * `get_value_callback` and `uses_context`, and only a JavaScript
	 * registration can carry `getFieldsList`. This endpoint gives those sources
	 * a way in, through the `pattern_builder_binding_fields` filter.
	 *
	 * With no `post_type`, answers the prior question instead: which post types
	 * have anything to bind to at all. That is asked for every post type at
	 * once, so it is answered here rather than in the editor, where enumerating
	 * a post type's meta costs a request apiece.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return WP_REST_Response Fields keyed by source name, or post type slugs.
	 */
	public function get_binding_fields( $request ) {
		$post_type = $request->get_param( 'post_type' );

		if ( ! $post_type ) {
			return new WP_REST_Response( $this->get_bindable_post_types() );
		}

		$declared = array();

		foreach ( get_all_registered_block_bindings_sources() as $source ) {
			/**
			 * Filters the bindable fields a source offers for a post type.
			 *
			 * Each field is an array of `label`, `args` and `type`, where
			 * `args` is written into the block's binding verbatim and `type`
			 * is matched against the block attribute's own type.
			 *
			 * @param array  $fields      The fields so far.
			 * @param string $source_name The binding source's name.
			 * @param string $post_type   The post type being listed.
			 */
			$fields = apply_filters( 'pattern_builder_binding_fields', array(), $source->name, $post_type );

			$fields = array_values( array_filter( array_map( array( $this, 'normalize_binding_field' ), (array) $fields ) ) );

			if ( $fields ) {
				$declared[ $source->name ] = $fields;
			}
		}

		return new WP_REST_Response( $declared );
	}

	/**
	 * The post types worth naming as a source of fields.
	 *
	 * Most of a site's post types are machinery — templates, navigation, font
	 * faces, patterns — and a pattern is never placed in one, so offering them
	 * promises fields that do not apply. Three rules, in order:
	 *
	 * A viewable post type is listed. These are the ones a pattern lands in, so
	 * the fields every post has apply to them even when they register no meta
	 * of their own, which on a stock site `post` and `page` do not.
	 *
	 * Any other post type is listed if the filter declared a field for it. That
	 * is a deliberate act, so it is honoured whatever the post type.
	 *
	 * Failing that, a post type a plugin registered is listed if it has meta
	 * worth binding to. The same courtesy is not extended to core's own
	 * internals, or `wp_block` would appear on the strength of the sync flag
	 * core registers against it.
	 *
	 * Only what this side can see is counted. A source that publishes its
	 * fields solely from JavaScript is invisible here, so answering the filter
	 * is how it puts a post type on this list.
	 *
	 * @return string[] Post type slugs.
	 */
	private function get_bindable_post_types(): array {
		$bindable = array();

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			$listed = is_post_type_viewable( $post_type )
				|| $this->has_declared_fields( $post_type->name )
				|| ( ! $post_type->_builtin && $this->has_bindable_meta( $post_type->name ) );

			if ( $listed ) {
				$bindable[] = $post_type->name;
			}
		}

		return $bindable;
	}

	/**
	 * Whether a post type registers meta that `core/post-meta` would list.
	 *
	 * Mirrors what the editor shows: the keys core exposes over REST, less the
	 * protected and internal ones its own field list drops.
	 *
	 * @param string $post_type The post type.
	 * @return bool Whether any key qualifies.
	 */
	private function has_bindable_meta( string $post_type ): bool {
		$keys = array_merge(
			get_registered_meta_keys( 'post' ),
			get_registered_meta_keys( 'post', $post_type )
		);

		foreach ( $keys as $key => $args ) {
			if ( empty( $args['show_in_rest'] ) || 'footnotes' === $key || str_starts_with( $key, '_' ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Whether the filter declares a field particular to a post type.
	 *
	 * A field offered whatever post type is named says nothing about any of
	 * them, and `add_post_data_fields()` below offers three such: every post
	 * has a date and a permalink. Those are worth showing once a post type is
	 * chosen, but they are not a reason to choose one, so what this plugin
	 * contributes is discounted here.
	 *
	 * @param string $post_type The post type.
	 * @return bool Whether any source declared one.
	 */
	private function has_declared_fields( string $post_type ): bool {
		foreach ( get_all_registered_block_bindings_sources() as $source ) {
			/** This filter is documented in includes/class-pattern-builder-api.php */
			$fields = apply_filters( 'pattern_builder_binding_fields', array(), $source->name, $post_type );

			$universal = $this->field_signatures( $this->add_post_data_fields( array(), $source->name ) );

			if ( array_diff( $this->field_signatures( $fields ), $universal ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Comparable strings for a set of declared fields, dropping malformed ones.
	 *
	 * @param mixed $fields The filtered value.
	 * @return string[] One signature per usable field.
	 */
	private function field_signatures( $fields ): array {
		$signatures = array();

		foreach ( (array) $fields as $field ) {
			$normalized = $this->normalize_binding_field( $field );

			if ( $normalized ) {
				$signatures[] = (string) wp_json_encode( $normalized );
			}
		}

		return $signatures;
	}

	/**
	 * One declared field, or null when it cannot be bound to.
	 *
	 * @param mixed $field The filtered value.
	 * @return array|null The field.
	 */
	private function normalize_binding_field( $field ) {
		if ( ! is_array( $field ) || empty( $field['args'] ) || ! is_array( $field['args'] ) ) {
			return null;
		}

		return array(
			'label' => (string) ( $field['label'] ?? reset( $field['args'] ) ),
			'args'  => array_map( 'strval', $field['args'] ),
			'type'  => (string) ( $field['type'] ?? 'string' ),
		);
	}

	/**
	 * Declares the fields `core/post-data` resolves but does not publish.
	 *
	 * Its own `getFieldsList` returns nothing unless a Post Date block is
	 * selected, while its callback resolves these for any block. `core/term-data`
	 * is deliberately absent: it needs `termId` and `taxonomy`, which a pattern
	 * placed in a post never has.
	 *
	 * @param array  $fields      The fields so far.
	 * @param string $source_name The binding source's name.
	 * @return array The fields.
	 */
	public function add_post_data_fields( $fields, $source_name ) {
		if ( 'core/post-data' !== $source_name ) {
			return $fields;
		}

		return array_merge(
			$fields,
			array(
				array(
					'label' => __( 'Post Date', 'pattern-builder' ),
					'args'  => array( 'field' => 'date' ),
					'type'  => 'string',
				),
				array(
					'label' => __( 'Post Modified Date', 'pattern-builder' ),
					'args'  => array( 'field' => 'modified' ),
					'type'  => 'string',
				),
				array(
					'label' => __( 'Post Link', 'pattern-builder' ),
					'args'  => array( 'field' => 'link' ),
					'type'  => 'string',
				),
			)
		);
	}

	/**
	 * Permission callback for state-changing endpoints.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function write_permission_callback( $request ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to manage theme patterns.', 'pattern-builder' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_invalid_nonce',
				__( 'Invalid or missing nonce.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Re-writes every theme pattern file, applying localization and image import options
	 * across the whole theme at once.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function process_theme_patterns( $request ) {
		$options = array();

		if ( 'true' === $request->get_param( 'localize' ) ) {
			$options['localize'] = true;
		}

		if ( 'false' === $request->get_param( 'importImages' ) ) {
			$options['import_images'] = false;
		}

		$patterns  = $this->store->get_theme_patterns();
		$processed = 0;
		$errors    = array();

		foreach ( $patterns as $pattern ) {
			try {
				$result = $this->store->update_theme_pattern( $pattern, $options );

				if ( is_wp_error( $result ) ) {
					$errors[] = array(
						'pattern' => $pattern->name,
						'error'   => $result->get_error_message(),
					);
					continue;
				}

				++$processed;
			} catch ( \Throwable $error ) {
				$errors[] = array(
					'pattern' => $pattern->name,
					'error'   => $error->getMessage(),
				);
			}
		}

		$response = array(
			'success'  => empty( $errors ),
			'message'  => sprintf(
				/* translators: 1: number of processed patterns, 2: total patterns. */
				__( 'Processed %1$d of %2$d theme patterns.', 'pattern-builder' ),
				$processed,
				count( $patterns )
			),
			'stats'    => array(
				'total'     => count( $patterns ),
				'processed' => $processed,
				'errors'    => count( $errors ),
			),
			'settings' => $options,
		);

		if ( $errors ) {
			$response['errors'] = $errors;
		}

		return new WP_REST_Response( $response, 200 );
	}
}
