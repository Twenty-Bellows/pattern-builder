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
						'required'          => true,
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
	 * @param \WP_REST_Request $request The request.
	 * @return WP_REST_Response Fields keyed by source name.
	 */
	public function get_binding_fields( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$declared  = array();

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
