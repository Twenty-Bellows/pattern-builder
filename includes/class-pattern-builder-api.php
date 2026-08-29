<?php
namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_REST_Response;

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-file-store.php';

/**
 * First-party REST endpoints that are not part of the patterns controller.
 *
 * Pattern CRUD lives in `Pattern_Builder_REST_Patterns_Controller` (registered
 * through the `pb_pattern` post type). What remains here is the bulk
 * "process theme" action used by the configuration panel.
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
	 * Re-writes every theme pattern file, applying localization and image
	 * import options across the whole theme at once.
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
