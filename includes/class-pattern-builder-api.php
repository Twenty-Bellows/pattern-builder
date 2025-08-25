<?php

namespace TwentyBellows\PatternBuilder;

use WP_Block_Patterns_Registry;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Blocks_Controller;

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-builder-controller.php';
require_once __DIR__ . '/class-pattern-builder-security.php';

class Pattern_Builder_API {

	private static $synced_theme_patterns = array();
	private static $base_route            = 'pattern-builder/v1';
	private $controller;

	public function __construct() {
		$this->controller = new Pattern_Builder_Controller();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'register_patterns' ), 9 );

		add_filter( 'rest_request_after_callbacks', array( $this, 'inject_theme_patterns' ), 10, 3 );

		add_filter( 'rest_pre_dispatch', array( $this, 'handle_hijack_block_update' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( $this, 'handle_hijack_block_delete' ), 10, 3 );

		add_filter( 'rest_request_before_callbacks', array( $this, 'handle_block_to_pattern_conversion' ), 10, 3 );

		add_filter( 'pre_render_block', array( $this, 'filter_pattern_block_attributes' ), 10, 2 );
	}


	/**
	 * Registers REST API routes for the Pattern Builder.
	 */
	public function register_routes(): void {

		register_rest_route(
			self::$base_route,
			'/patterns',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_patterns' ),
				'permission_callback' => array( $this, 'read_permission_callback' ),
			)
		);

		register_rest_route(
			self::$base_route,
			'/process-theme',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_theme_patterns' ),
				'permission_callback' => array( $this, 'write_permission_callback' ),
			)
		);
	}

	/**
	 * Permission callback for read operations.
	 * Allows access to all logged-in users who can edit posts.
	 *
	 * @return bool True if the user can read patterns, false otherwise.
	 */
	public function read_permission_callback() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission callback for write operations (PUT, POST, DELETE).
	 * Restricts access to administrators and editors only.
	 * Also verifies the REST API nonce for additional security.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error True if the user can modify patterns, WP_Error otherwise.
	 */
	public function write_permission_callback( $request ) {
		// First check if user has the required capability
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to modify patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		// Verify the REST API nonce
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Cookie nonce is invalid', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// Callback functions //////////

	/**
	 * Processes all theme patterns with current configuration settings.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function process_theme_patterns( WP_REST_Request $request ): WP_REST_Response {

		$localize      = sanitize_text_field( $request->get_param( 'localize' ) ) === 'true';
		$import_images = sanitize_text_field( $request->get_param( 'importImages' ) ) !== 'false';

		$options = array(
			'localize'      => $localize,
			'import_images' => $import_images,
		);

		// Get all theme patterns
		$theme_patterns = $this->controller->get_block_patterns_from_theme_files();

		$processed_count = 0;
		$error_count     = 0;
		$errors          = array();

		foreach ( $theme_patterns as $pattern ) {
			try {
				$this->controller->update_theme_pattern( $pattern, $options );
				++$processed_count;
			} catch ( Exception $e ) {
				++$error_count;
				$errors[] = array(
					'pattern' => $pattern->name,
					'error'   => $e->getMessage(),
				);
			}
		}

		$total_patterns = count( $theme_patterns );
		$success        = $error_count === 0;

		$response_data = array(
			'success'  => $success,
			'message'  => sprintf(
				/* translators: 1: Number of patterns processed, 2: Total number of patterns */
				__( 'Processed %1$d of %2$d theme patterns successfully.', 'pattern-builder' ),
				$processed_count,
				$total_patterns
			),
			'stats'    => array(
				'total'     => $total_patterns,
				'processed' => $processed_count,
				'errors'    => $error_count,
			),
			'settings' => $options,
		);

		if ( ! empty( $errors ) ) {
			$response_data['errors'] = $errors;
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * Retrieves all block patterns.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function get_patterns( WP_REST_Request $request ): WP_REST_Response {
		$theme_patterns = $this->controller->get_block_patterns_from_theme_files();
		$theme_patterns = array_map(
			function ( $pattern ) {
				$pattern_post      = $this->controller->get_tbell_pattern_block_post_for_pattern( $pattern );
				$pattern_from_post = Abstract_Pattern::from_post( $pattern_post );
				// TODO: The slug doesn't survive the trip to post and back since it has to be normalized.
				// so we just pull it form the original pattern and reset it here.  Not sure if that is the best way to do this.
				$pattern_from_post->name = $pattern->name;
				return $pattern_from_post;
			},
			$theme_patterns
		);

		$user_patterns = $this->controller->get_block_patterns_from_database();

		// TODO: We also need to get patterns from other potential sources such as plugins and core.
		// However, these are not editable.

		$all_patterns = array_merge( $theme_patterns, $user_patterns );

		return rest_ensure_response( $all_patterns );
	}

	public function inject_theme_patterns( $response, $server, $request ) {
		// Requesting a single pattern.  Inject the synced theme pattern.
		if ( preg_match( '#/wp/v2/blocks/(?P<id>\d+)#', $request->get_route(), $matches ) ) {
			$block_id            = intval( $matches['id'] );
			$tbell_pattern_block = get_post( $block_id );
			if ( $tbell_pattern_block && $tbell_pattern_block->post_type === 'tbell_pattern_block' ) {
				// make sure the pattern has a pattern file
				$pattern_file_path = $this->controller->get_pattern_filepath( Abstract_Pattern::from_post( $tbell_pattern_block ) );
				if ( ! $pattern_file_path ) {
					return $response; // No pattern file found, return the original response
				}
				$tbell_pattern_block->post_name = $this->controller->format_pattern_slug_from_post( $tbell_pattern_block->post_name );
				$data                           = $this->format_tbell_pattern_block_response( $tbell_pattern_block, $request );
				$response                       = new WP_REST_Response( $data );
			}
		}

		// Requesting all patterns.  Inject all of the synced theme patterns.
		elseif ( $request->get_route() === '/wp/v2/blocks' && $request->get_method() === 'GET' ) {

			$data     = $response->get_data();
			$patterns = $this->controller->get_block_patterns_from_theme_files();

			// filter out patterns that should be exluded from the inserter
			$patterns = array_filter(
				$patterns,
				function ( $pattern ) {
					return $pattern->inserter;
				}
			);

			foreach ( $patterns as $pattern ) {
				$post   = $this->controller->get_tbell_pattern_block_post_for_pattern( $pattern );
				$data[] = $this->format_tbell_pattern_block_response( $post, $request );
			}

			$response->set_data( $data );
		}

		return $response;
	}

	public function format_tbell_pattern_block_response( $post, $request ) {
		$post->post_type = 'wp_block';

		// Create a mock request to pass to the controller
		$mock_request = new WP_REST_Request( 'GET', '/wp/v2/blocks/' . $post->ID );
		$mock_request->set_param( 'context', 'edit' );

		$controller = new WP_REST_Blocks_Controller( 'wp_block' );
		$response   = $controller->prepare_item_for_response( $post, $mock_request );

		$data = $controller->prepare_response_for_collection( $response );

		$meta = get_post_meta( $post->ID );

		if ( isset( $meta ) ) {
			if ( isset( $meta['wp_pattern_block_types'] ) ) {
				$data['wp_pattern_block_types'] = array_map( 'trim', explode( ',', $meta['wp_pattern_block_types'][0] ) );
			}
			if ( isset( $meta['wp_pattern_post_types'] ) ) {
				$data['wp_pattern_post_types'] = array_map( 'trim', explode( ',', $meta['wp_pattern_post_types'][0] ) );
			}
			if ( isset( $meta['wp_pattern_template_types'] ) ) {
				$data['wp_pattern_template_types'] = array_map( 'trim', explode( ',', $meta['wp_pattern_template_types'][0] ) );
			}
			if ( isset( $meta['wp_pattern_inserter'] ) ) {
				$data['wp_pattern_inserter'] = $meta['wp_pattern_inserter'][0];
			}
		}

		$data['source'] = 'theme';

		return $data;
	}

	/**
	 * Registers block patterns for the theme.
	 *
	 * If the patterns are ALREADY registered, unregister them first.
	 * Synced patterns are registered with a reference to the post ID of their pattern.
	 * Unsynced patterns are registered with the content from the tbell_pattern_block post.
	 */
	public function register_patterns() {

		$pattern_registry = WP_Block_Patterns_Registry::get_instance();

		$patterns = $this->controller->get_block_patterns_from_theme_files();

		foreach ( $patterns as $pattern ) {

			$post = $this->controller->create_tbell_pattern_block_post_for_pattern( $pattern );

			if ( $pattern_registry->is_registered( $pattern->name ) ) {
				$pattern_registry->unregister( $pattern->name );
			}

			$pattern_content = $pattern->content;
			if ( $pattern->synced ) {
				self::$synced_theme_patterns[ $pattern->name ] = $post->ID;
				$pattern_content                               = '<!-- wp:block {"ref":' . $post->ID . '} /-->';
			}

			$pattern_data = array(
				'title'         => $pattern->title,
				'inserter'      => false,
				'content'       => $pattern_content,
				'source'        => 'theme',
				'blockTypes'    => $pattern->blockTypes,
				'templateTypes' => $pattern->templateTypes,
			);

			// NOTE: Setting the postTypes to an empty array will cause the pattern to not be available
			// or perhaps a registration error... or some strange behavior.  So it only optionally set here.
			if ( $pattern->postTypes ) {
				$pattern_data['postTypes'] = $pattern->postTypes;
			}

			$pattern_registry->register(
				$pattern->name,
				$pattern_data
			);
		}
	}


	/**
	 *
	 * Filters delete calls and if the item being deleted is a 'tbell_pattern_block' (theme pattern)
	 * delete the related pattern php file as well.
	 */
	function handle_hijack_block_delete( $response, $server, $request ) {

		$route = $request->get_route();

		if ( preg_match( '#^/wp/v2/blocks/(\d+)$#', $route, $matches ) ) {

			$id   = intval( $matches[1] );
			$post = get_post( $id );

			if ( $post && $post->post_type === 'tbell_pattern_block' && $request->get_method() === 'DELETE' ) {

				$deleted = wp_delete_post( $id, true );

				if ( ! $deleted ) {
					return new WP_Error( 'pattern_delete_failed', 'Failed to delete pattern', array( 'status' => 500 ) );
				}

				$abstract_pattern = Abstract_Pattern::from_post( $post );

				$path = $this->controller->get_pattern_filepath( $abstract_pattern );

				if ( ! $path ) {
					return new WP_Error( 'pattern_not_found', 'Pattern not found', array( 'status' => 404 ) );
				}

				// Validate that the path is within the patterns directory
				$validation = \Pattern_Builder_Security::validate_pattern_path( $path );
				if ( is_wp_error( $validation ) ) {
					return $validation;
				}

				// Use secure file delete operation
				$allowed_dirs = array(
					get_stylesheet_directory() . '/patterns',
					get_template_directory() . '/patterns',
				);
				$deleted      = \Pattern_Builder_Security::safe_file_delete( $path, $allowed_dirs );

				if ( is_wp_error( $deleted ) ) {
					return $deleted;
				}

				return new WP_REST_Response( array( 'message' => 'Pattern deleted successfully' ), 200 );

			}
		}

		return $response;
	}

	/**
	 *
	 * This filter handles additional logic when a tbell_pattern_block (theme pattern) is updated.
	 * It updates the pattern file as well as associated metadata for the pattern.
	 * Additionally it will optionally localize the content as well as import any media
	 * referenced by the pattern into the theme.
	 */
	function handle_hijack_block_update( $response, $handler, $request ) {
		$route = $request->get_route();

		if ( preg_match( '#^/wp/v2/blocks/(\d+)$#', $route, $matches ) ) {

			$id   = intval( $matches[1] );
			$post = get_post( $id );

			if ( $post && $request->get_method() === 'PUT' ) {

				$updated_pattern = json_decode( $request->get_body(), true );

				// Validate JSON decode was successful
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error(
						'invalid_json',
						__( 'Invalid JSON in request body.', 'pattern-builder' ),
						array( 'status' => 400 )
					);
				}

				// Sanitize the input data
				$updated_pattern = $this->sanitize_pattern_input( $updated_pattern );

				$convert_user_pattern_to_theme_pattern = false;

				if ( $post->post_type === 'wp_block' ) {

					if ( isset( $updated_pattern['source'] ) && $updated_pattern['source'] === 'theme' ) {
						// we are attempting to convert a USER pattern to a THEME pattern.
						$convert_user_pattern_to_theme_pattern = true;
					}
				}

				if ( $post->post_type === 'tbell_pattern_block' || $convert_user_pattern_to_theme_pattern ) {

					// Check write permissions before allowing update
					if ( ! current_user_can( 'edit_others_posts' ) ) {
						return new WP_Error(
							'rest_forbidden',
							__( 'You do not have permission to edit patterns.', 'pattern-builder' ),
							array( 'status' => 403 )
						);
					}

					// Verify the REST API nonce
					$nonce = $request->get_header( 'X-WP-Nonce' );
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
						return new WP_Error(
							'rest_cookie_invalid_nonce',
							__( 'Cookie nonce is invalid', 'pattern-builder' ),
							array( 'status' => 403 )
						);
					}

					$pattern = Abstract_Pattern::from_post( $post );

					if ( isset( $updated_pattern['content'] ) ) {
						// remap tbell_pattern_blocks to patterns
						$blocks           = parse_blocks( $updated_pattern['content'] );
						$blocks           = $this->convert_blocks_to_patterns( $blocks );
						$pattern->content = serialize_blocks( $blocks );
						// TODO: Format the content to be easy on the eyes.
					}

					if ( isset( $updated_pattern['title'] ) ) {
						$pattern->title = $updated_pattern['title'];
					}

					if ( isset( $updated_pattern['excerpt'] ) ) {
						$pattern->description = $updated_pattern['excerpt'];
					}

					if ( isset( $updated_pattern['wp_pattern_sync_status'] ) ) {
						$pattern->synced = $updated_pattern['wp_pattern_sync_status'] !== 'unsynced';
					}

					if ( isset( $updated_pattern['wp_pattern_block_types'] ) ) {
						$pattern->blockTypes = $updated_pattern['wp_pattern_block_types'];
					}

					if ( isset( $updated_pattern['wp_pattern_post_types'] ) ) {
						$pattern->postTypes = $updated_pattern['wp_pattern_post_types'];
					}

					if ( isset( $updated_pattern['wp_pattern_template_types'] ) ) {
						$pattern->templateTypes = $updated_pattern['wp_pattern_template_types'];
					}

					if ( isset( $updated_pattern['wp_pattern_inserter'] ) ) {
						$pattern->inserter = $updated_pattern['wp_pattern_inserter'] === 'no' ? false : true;
					}

					if ( isset( $updated_pattern['source'] ) && $updated_pattern['source'] === 'user' ) {
						// we are attempting to convert a THEME pattern to a USER pattern.
						$this->controller->update_user_pattern( $pattern );
					} else {
						// Check configuration options via query parameters
						$options = array();

						$localize_param = sanitize_text_field( $request->get_param( 'patternBuilderLocalize' ) );
						if ( $localize_param === 'true' ) {
							$options['localize'] = true;
						}

						$import_images_param = sanitize_text_field( $request->get_param( 'patternBuilderImportImages' ) );
						if ( $import_images_param === 'false' ) {
							$options['import_images'] = false;
						} else {
							// Default to true if not explicitly disabled
							$options['import_images'] = true;
						}

						$this->controller->update_theme_pattern( $pattern, $options );
					}

					$post               = get_post( $pattern->id );
					$formatted_response = $this->format_tbell_pattern_block_response( $post, $request );
					$response           = new WP_REST_Response( $formatted_response, 200 );
				}
			}
		}
		return $response;
	}

	/**
	 * Sanitizes pattern input data to prevent XSS and ensure data integrity.
	 *
	 * @param array $input The input data to sanitize.
	 * @return array Sanitized input data.
	 */
	private function sanitize_pattern_input( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();

		// Sanitize text fields
		if ( isset( $input['title'] ) ) {
			$sanitized['title'] = sanitize_text_field( $input['title'] );
		}

		if ( isset( $input['excerpt'] ) ) {
			$sanitized['excerpt'] = sanitize_textarea_field( $input['excerpt'] );
		}

		// Sanitize content - allow HTML but sanitize it
		if ( isset( $input['content'] ) ) {
			$sanitized['content'] = wp_kses_post( $input['content'] );
		}

		// Sanitize source field
		if ( isset( $input['source'] ) ) {
			$sanitized['source'] = in_array( $input['source'], array( 'theme', 'user' ), true ) ? $input['source'] : 'user';
		}

		// Sanitize sync status
		if ( isset( $input['wp_pattern_sync_status'] ) ) {
			$sanitized['wp_pattern_sync_status'] = in_array( $input['wp_pattern_sync_status'], array( 'synced', 'unsynced' ), true ) ? $input['wp_pattern_sync_status'] : 'unsynced';
		}

		// Sanitize inserter setting
		if ( isset( $input['wp_pattern_inserter'] ) ) {
			$sanitized['wp_pattern_inserter'] = in_array( $input['wp_pattern_inserter'], array( 'yes', 'no' ), true ) ? $input['wp_pattern_inserter'] : 'yes';
		}

		// Sanitize array fields
		$array_fields = array( 'wp_pattern_block_types', 'wp_pattern_post_types', 'wp_pattern_template_types' );
		foreach ( $array_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				if ( is_array( $input[ $field ] ) ) {
					$sanitized[ $field ] = array_map( 'sanitize_text_field', $input[ $field ] );
				} elseif ( is_string( $input[ $field ] ) ) {
					// Handle comma-separated strings
					$values              = explode( ',', $input[ $field ] );
					$sanitized[ $field ] = array_map( 'sanitize_text_field', $values );
				}
			}
		}

		// Pass through other fields that don't need sanitization but need to be preserved
		$passthrough_fields = array( 'id', 'date', 'date_gmt', 'modified', 'modified_gmt', 'status', 'type' );
		foreach ( $passthrough_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = $input[ $field ];
			}
		}

		return $sanitized;
	}

	/**
	 * When anything is saved any wp:block that references a theme pattern is converted to a wp:pattern block instead.
	 */
	public function handle_block_to_pattern_conversion( $response, $handler, $request ) {
		if ( $request->get_method() === 'PUT' || $request->get_method() === 'POST' ) {
			$body = json_decode( $request->get_body(), true );

			// Validate JSON decode was successful
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return $response; // Return original response if JSON is invalid
			}

			// Sanitize the input data
			$body = $this->sanitize_pattern_input( $body );
			if ( isset( $body['content'] ) ) {
				// parse the content string into blocks
				$blocks = parse_blocks( $body['content'] );
				$blocks = $this->convert_blocks_to_patterns( $blocks );
				// convert the blocks back to a string
				$body['content'] = serialize_blocks( $blocks );
				$request->set_body( wp_json_encode( $body ) );
			}
		}
		return $response;
	}

	private function convert_blocks_to_patterns( $blocks ) {
		foreach ( $blocks as &$block ) {
			if ( isset( $block['blockName'] ) && $block['blockName'] === 'core/block' ) {
				$post = get_post( $block['attrs']['ref'] );
				if ( $post->post_type === 'tbell_pattern_block' ) {
					$slug                   = Pattern_Builder_Controller::format_pattern_slug_from_post( $post->post_name );
					$block['blockName']     = 'core/pattern';
					$block['attrs']         = isset( $block['attrs'] ) ? $block['attrs'] : array();
					$block['attrs']['slug'] = $slug;
					if ( ! empty( $post->post_title ) ) {
						$block['attrs']['title'] = $post->post_title;
					}
					unset( $block['attrs']['ref'] );
				}
			} elseif ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->convert_blocks_to_patterns( $block['innerBlocks'] );
			}
		}
		return $blocks;
	}

	/**
	 * Filters pattern block data to apply attributes to nested wp:block.
	 *
	 * @param array $parsed_block The parsed block data.
	 * @param array $source_block The original block data.
	 * @return array Modified block data.
	 */
	public function filter_pattern_block_attributes( $pre_render, $parsed_block ) {
		// Only process wp:pattern blocks
		if ( $parsed_block['blockName'] !== 'core/pattern' ) {
			return $pre_render;
		}

		// Extract attributes from the pattern block
		$pattern_attrs = isset( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();

		$slug = $pattern_attrs['slug'] ?? '';

		// Remove attributes we don't want to pass down
		unset( $pattern_attrs['slug'] );

		// If no attributes to apply, return as-is
		if ( empty( $pattern_attrs ) ) {
			return $pre_render;
		}

		$synced_pattern_id = self::$synced_theme_patterns[ $slug ] ?? null;

		// if there is a synced_pattern_id then contruct the block with a reference to the synced pattern that also has the rest of the pattern's attributes and render it.
		if ( $synced_pattern_id ) {
			$block_attributes = array_merge(
				array( 'ref' => $synced_pattern_id ),
				$pattern_attrs
			);
			$block_attributes = wp_json_encode( $block_attributes );
			$block_string     = "<!-- wp:block $block_attributes /-->";
			return do_blocks( $block_string );
		}

		return $pre_render;
	}
}
