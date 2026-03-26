<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- camelCase properties mirror the JS AbstractPattern class.

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

	/**
	 * Cache of synced theme pattern name → post ID mappings.
	 *
	 * @var array
	 */
	private static $synced_theme_patterns = array();

	/**
	 * REST API namespace/base route.
	 *
	 * @var string
	 */
	private static $base_route = 'pattern-builder/v1';

	/**
	 * Pattern controller instance.
	 *
	 * @var Pattern_Builder_Controller
	 */
	private $controller;

	/**
	 * Constructor to initialize API hooks.
	 */
	public function __construct() {
		$this->controller = new Pattern_Builder_Controller();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'register_patterns' ), 9 );

		add_filter( 'rest_request_after_callbacks', array( $this, 'inject_theme_patterns' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'inject_faux_patterns' ), 10, 3 );

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
	 * Allows access to users who can read pattern blocks.
	 *
	 * @return bool True if the user can read patterns, false otherwise.
	 */
	public function read_permission_callback() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission callback for write operations (PUT, POST, DELETE).
	 * Restricts access to users with pattern editing capabilities.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error True if the user can modify patterns, WP_Error otherwise.
	 */
	public function write_permission_callback( $request ) {
		// Check if user has the required capability.
		if ( ! current_user_can( 'edit_tbell_pattern_blocks' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to modify patterns.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		// Verify the REST API nonce.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Cookie nonce is invalid.', 'pattern-builder' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Processes all theme patterns with current configuration settings.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function process_theme_patterns( WP_REST_Request $request ): WP_REST_Response {

		$localize      = 'true' === sanitize_text_field( $request->get_param( 'localize' ) );
		$import_images = 'false' !== sanitize_text_field( $request->get_param( 'importImages' ) );

		$options = array(
			'localize'      => $localize,
			'import_images' => $import_images,
		);

		$theme_patterns = $this->controller->get_block_patterns_from_theme_files();

		$processed_count = 0;
		$error_count     = 0;
		$errors          = array();

		foreach ( $theme_patterns as $pattern ) {
			try {
				$this->controller->update_theme_pattern( $pattern, $options );
				++$processed_count;
			} catch ( \Exception $e ) {
				++$error_count;
				$errors[] = array(
					'pattern' => $pattern->name,
					'error'   => $e->getMessage(),
				);
			}
		}

		$total_patterns = count( $theme_patterns );
		$success        = 0 === $error_count;

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
	public function get_patterns( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- required REST callback signature.
		$theme_patterns = $this->controller->get_block_patterns_from_theme_files();
		$theme_patterns = array_map(
			function ( $pattern ) {
				$pattern_post      = $this->controller->get_tbell_pattern_block_post_for_pattern( $pattern );
				$pattern_from_post = Abstract_Pattern::from_post( $pattern_post );
				// TODO: The slug doesn't survive the trip to post and back since it has to be normalized.
				// For now we pull it from the original pattern and reset it here.
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

	/**
	 * Injects theme patterns into individual /wp/v2/blocks/{id} REST responses.
	 *
	 * When the Site Editor or Pattern Builder sidebar requests a specific block by ID,
	 * and that ID belongs to a tbell_pattern_block post, this filter returns a correctly
	 * formatted response so the pattern can be opened and edited.
	 *
	 * Note: Theme patterns are intentionally NOT injected into the /wp/v2/blocks LIST
	 * response. The list is used by Gutenberg's getAllPatterns merge, and theme patterns
	 * are now served exclusively via the /wp/v2/block-patterns/patterns endpoint
	 * (see inject_faux_patterns()). Keeping them out of the blocks list prevents
	 * the same content from appearing in both "My Patterns" and "Theme" inserter tabs.
	 *
	 * @param WP_REST_Response $response The REST response.
	 * @param mixed            $server   The REST server.
	 * @param WP_REST_Request  $request  The REST request.
	 * @return WP_REST_Response
	 */
	public function inject_theme_patterns( $response, $server, $request ) {
		if ( ! preg_match( '#/wp/v2/blocks/(?P<id>\d+)#', $request->get_route(), $matches ) ) {
			return $response;
		}

		$block_id            = intval( $matches['id'] );
		$tbell_pattern_block = get_post( $block_id );

		if ( ! $tbell_pattern_block || 'tbell_pattern_block' !== $tbell_pattern_block->post_type ) {
			return $response;
		}

		// Only inject if the pattern still has a backing file.
		$pattern_file_path = $this->controller->get_pattern_filepath( Abstract_Pattern::from_post( $tbell_pattern_block ) );
		if ( is_wp_error( $pattern_file_path ) || ! $pattern_file_path ) {
			return $response;
		}

		$tbell_pattern_block->post_name = $this->controller->format_pattern_slug_from_post( $tbell_pattern_block->post_name );
		$data                           = $this->format_tbell_pattern_block_response( $tbell_pattern_block, $request );

		return new WP_REST_Response( $data );
	}

	/**
	 * Replaces Pattern Builder–managed entries in the /wp/v2/block-patterns/patterns response
	 * with "faux" versions that carry correct inserter and faux content.
	 *
	 * ### Why this is needed
	 *
	 * Pattern Builder registers theme patterns at priority 9 with `inserter: false` to prevent
	 * WordPress from registering real (locked) versions at priority 10. The registry entries
	 * therefore have `inserter: false` and hardcoded content, which is not what the editor
	 * should see.
	 *
	 * This filter intercepts the REST response and replaces each managed pattern entry with
	 * a faux version:
	 *   - `content` is set to `<!-- wp:block {"ref": ID} /-->` for synced patterns, or the
	 *     theme file content for unsynced patterns. This content goes through
	 *     syncedPatternFilter.js on the JS side when rendered in the editor.
	 *   - `inserter` is set to `true` for context-restricted patterns (those with `blockTypes`
	 *     or `postTypes` declarations, e.g. Starter Patterns candidates), and `false` for all
	 *     other theme patterns.
	 *   - All other metadata (title, description, categories, keywords, blockTypes, postTypes,
	 *     viewportWidth, source) is preserved from the existing registry entry.
	 *
	 * ### Deduplication
	 *
	 * Because theme patterns are no longer injected into the /wp/v2/blocks LIST, they do not
	 * appear in Gutenberg's "My Patterns" tab. They appear exactly once in the patterns
	 * endpoint response, as a faux entry.
	 *
	 * @param WP_REST_Response $response The REST response.
	 * @param mixed            $server   The REST server.
	 * @param WP_REST_Request  $request  The REST request.
	 * @return WP_REST_Response
	 */
	public function inject_faux_patterns( $response, $server, $request ) {
		if ( '/wp/v2/block-patterns/patterns' !== $request->get_route() || 'GET' !== $request->get_method() ) {
			return $response;
		}

		$managed_patterns = $this->controller->get_block_patterns_from_theme_files();

		if ( empty( $managed_patterns ) ) {
			return $response;
		}

		// Build a map of pattern name → [pattern, post] for quick lookup.
		$managed_map = array();
		foreach ( $managed_patterns as $pattern ) {
			$post = $this->controller->get_tbell_pattern_block_post_for_pattern( $pattern );
			if ( $post ) {
				$managed_map[ $pattern->name ] = array(
					'pattern' => $pattern,
					'post'    => $post,
				);
			}
		}

		if ( empty( $managed_map ) ) {
			return $response;
		}

		$data     = $response->get_data();
		$modified = false;

		foreach ( $data as $key => $entry ) {
			$name = $entry['name'] ?? '';

			if ( ! isset( $managed_map[ $name ] ) ) {
				continue;
			}

			$pattern = $managed_map[ $name ]['pattern'];
			$post    = $managed_map[ $name ]['post'];

			// Faux content: synced patterns expose a wp:block ref; unsynced expose the theme file content.
			$content = $pattern->synced
				? '<!-- wp:block {"ref":' . $post->ID . '} /-->'
				: $pattern->content;

			// Context-restricted patterns (blockTypes/postTypes) must be visible in the Starter Patterns
			// modal, which filters by inserter !== false before checking blockTypes. All other theme
			// patterns remain hidden from the general inserter panel.
			$has_context_restriction = ! empty( $pattern->blockTypes ) || ! empty( $pattern->postTypes );
			$inserter                = (bool) ( $has_context_restriction ? $pattern->inserter : false );

			// Start from the existing registry entry (preserves all metadata) and override only
			// the fields that Pattern Builder controls.
			//
			// source: 'user' — removes the lock icon; WordPress treats source:'theme' as read-only
			// in the "All patterns" Site Editor page.
			// id: post ID    — enables the "Edit" button to route to /wp/v2/blocks/{id}, which
			// Pattern Builder's inject_theme_patterns() hook already intercepts to serve
			// the correct editable data.
			$faux             = $entry;
			$faux['content']  = $content;
			$faux['inserter'] = $inserter;
			$faux['source']   = 'user';
			$faux['id']       = $post->ID;

			$data[ $key ] = $faux;
			$modified     = true;
		}

		if ( $modified ) {
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Formats a tbell_pattern_block post as a wp_block REST response.
	 *
	 * Temporarily sets post_type to 'wp_block' in memory so that WP_REST_Blocks_Controller
	 * can produce a correctly structured response without needing a custom serializer.
	 *
	 * @param \WP_Post        $post    The tbell_pattern_block post.
	 * @param WP_REST_Request $request The original REST request (used for context).
	 * @return array Formatted REST response data.
	 */
	public function format_tbell_pattern_block_response( $post, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $request is part of the public interface and may be used by callers or future extensions.
		$post->post_type = 'wp_block';

		// Create a mock request to pass to the controller.
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
	 * If the patterns are already registered, unregisters them first.
	 * Synced patterns are registered with a reference to the post ID of their pattern.
	 * Unsynced patterns are registered with the content from the theme file.
	 *
	 * ### Purpose: blocking WordPress's default registration
	 *
	 * This runs at priority 9 so it fires before WordPress's own pattern registration
	 * at priority 10. By registering first (with `inserter: false` and faux content),
	 * Pattern Builder prevents WordPress from creating real/locked versions that would
	 * bypass Plugin Builder's editing layer.
	 *
	 * What users actually see is controlled by inject_faux_patterns(), which replaces
	 * the /wp/v2/block-patterns/patterns REST response with faux entries that carry
	 * correct inserter values and the right content for each pattern type.
	 */
	public function register_patterns(): void {

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

			/*
			 * Always register with inserter: false in the pattern registry.
			 *
			 * The registry is used only as a blocking mechanism — registering at priority 9
			 * prevents WordPress from registering real/locked versions at priority 10.
			 * What users actually see is controlled by inject_faux_patterns(), which
			 * replaces the REST response with faux entries carrying correct inserter values.
			 */
			$inserter_value = false;

			$pattern_data = array(
				'title'         => $pattern->title,
				'description'   => $pattern->description,
				'inserter'      => $inserter_value,
				'content'       => $pattern_content,
				'source'        => 'theme',
				'categories'    => $pattern->categories,
				'keywords'      => $pattern->keywords,
				'blockTypes'    => $pattern->blockTypes,
				'templateTypes' => $pattern->templateTypes,
			);

			if ( $pattern->viewportWidth ) {
				$pattern_data['viewportWidth'] = $pattern->viewportWidth;
			}

			// Setting postTypes to an empty array causes registration issues; only include when non-empty.
			if ( ! empty( $pattern->postTypes ) ) {
				$pattern_data['postTypes'] = $pattern->postTypes;
			}

			$pattern_registry->register(
				$pattern->name,
				$pattern_data
			);
		}
	}


	/**
	 * Filters delete calls and, if the item being deleted is a tbell_pattern_block (theme pattern),
	 * deletes the related pattern PHP file as well.
	 *
	 * @param mixed           $response The response from the REST API.
	 * @param mixed           $server   The REST server instance.
	 * @param WP_REST_Request $request  The REST request object.
	 * @return mixed|WP_Error The response or WP_Error on failure.
	 */
	public function handle_hijack_block_delete( $response, $server, $request ) {

		$route = $request->get_route();

		if ( preg_match( '#^/wp/v2/blocks/(\d+)$#', $route, $matches ) ) {

			$id   = intval( $matches[1] );
			$post = get_post( $id );

			if ( $post && 'tbell_pattern_block' === $post->post_type && 'DELETE' === $request->get_method() ) {

				$deleted = wp_delete_post( $id, true );

				if ( ! $deleted ) {
					return new WP_Error( 'pattern_delete_failed', 'Failed to delete pattern.', array( 'status' => 500 ) );
				}

				$abstract_pattern = Abstract_Pattern::from_post( $post );

				$path = $this->controller->get_pattern_filepath( $abstract_pattern );

				if ( is_wp_error( $path ) ) {
					return $path;
				}

				if ( ! $path ) {
					return new WP_Error( 'pattern_not_found', 'Pattern not found.', array( 'status' => 404 ) );
				}

				// Use secure file delete operation.
				$allowed_dirs = array(
					get_stylesheet_directory() . '/patterns',
					get_template_directory() . '/patterns',
				);
				$deleted      = Pattern_Builder_Security::safe_file_delete( $path, $allowed_dirs );

				if ( is_wp_error( $deleted ) ) {
					return $deleted;
				}

				return new WP_REST_Response( array( 'message' => 'Pattern deleted successfully.' ), 200 );

			}
		}

		return $response;
	}

	/**
	 * Handles additional logic when a tbell_pattern_block (theme pattern) is updated via the REST API.
	 *
	 * Updates the pattern file and associated metadata. Optionally localizes the content and
	 * imports any media referenced by the pattern into the theme.
	 *
	 * @param mixed           $response The response from the REST API.
	 * @param mixed           $handler  The handler object.
	 * @param WP_REST_Request $request  The REST request object.
	 * @return mixed|WP_Error The response or WP_Error on failure.
	 */
	public function handle_hijack_block_update( $response, $handler, $request ) {
		$route = $request->get_route();

		if ( preg_match( '#^/wp/v2/blocks/(\d+)$#', $route, $matches ) ) {

			$id   = intval( $matches[1] );
			$post = get_post( $id );

			if ( $post && 'PUT' === $request->get_method() ) {

				$updated_pattern = json_decode( $request->get_body(), true );

				// Validate JSON decode was successful.
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return new WP_Error(
						'invalid_json',
						__( 'Invalid JSON in request body.', 'pattern-builder' ),
						array( 'status' => 400 )
					);
				}

				$convert_user_pattern_to_theme_pattern = false;

				if ( 'wp_block' === $post->post_type ) {
					if ( isset( $updated_pattern['source'] ) && 'theme' === $updated_pattern['source'] ) {
						// Attempting to convert a USER pattern to a THEME pattern.
						$convert_user_pattern_to_theme_pattern = true;
					}
				}

				if ( 'tbell_pattern_block' === $post->post_type || $convert_user_pattern_to_theme_pattern ) {

					// Check write permissions before allowing update.
					if ( ! current_user_can( 'edit_tbell_pattern_blocks' ) ) {
						return new WP_Error(
							'rest_forbidden',
							__( 'You do not have permission to edit patterns.', 'pattern-builder' ),
							array( 'status' => 403 )
						);
					}

					$pattern = Abstract_Pattern::from_post( $post );

					if ( isset( $updated_pattern['content'] ) ) {
						// Remap tbell_pattern_blocks to patterns.
						$blocks           = parse_blocks( $updated_pattern['content'] );
						$blocks           = $this->convert_blocks_to_patterns( $blocks );
						$pattern->content = serialize_blocks( $blocks );
					}

					if ( isset( $updated_pattern['title'] ) ) {
						$pattern->title = $updated_pattern['title'];
					}

					if ( isset( $updated_pattern['excerpt'] ) ) {
						$pattern->description = $updated_pattern['excerpt'];
					}

					if ( isset( $updated_pattern['wp_pattern_sync_status'] ) ) {
						$pattern->synced = 'unsynced' !== $updated_pattern['wp_pattern_sync_status'];
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
						$pattern->inserter = 'no' !== $updated_pattern['wp_pattern_inserter'];
					}

					if ( isset( $updated_pattern['source'] ) && 'user' === $updated_pattern['source'] ) {
						// Converting a THEME pattern to a USER pattern.
						$this->controller->update_user_pattern( $pattern );
					} else {
						// Check configuration options via query parameters.
						$options = array();

						$localize_param = sanitize_text_field( $request->get_param( 'patternBuilderLocalize' ) );
						if ( 'true' === $localize_param ) {
							$options['localize'] = true;
						}

						$import_images_param = sanitize_text_field( $request->get_param( 'patternBuilderImportImages' ) );
						if ( 'false' === $import_images_param ) {
							$options['import_images'] = false;
						} else {
							// Default to true if not explicitly disabled.
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
	 * When anything is saved, converts any wp:block blocks referencing a theme pattern to wp:pattern blocks instead.
	 *
	 * @param mixed           $response The response from the REST API.
	 * @param mixed           $handler  The handler object.
	 * @param WP_REST_Request $request  The REST request object.
	 * @return mixed The response, potentially modified.
	 */
	public function handle_block_to_pattern_conversion( $response, $handler, $request ) {
		if ( 'PUT' === $request->get_method() || 'POST' === $request->get_method() ) {

			$body = json_decode( $request->get_body(), true );

			// Return original response if JSON is invalid.
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return $response;
			}

			if ( isset( $body['content'] ) ) {
				$blocks          = parse_blocks( $body['content'] );
				$blocks          = $this->convert_blocks_to_patterns( $blocks );
				$body['content'] = serialize_blocks( $blocks );
				$request->set_body( wp_json_encode( $body ) );
			}
		}
		return $response;
	}

	/**
	 * Recursively converts wp:block references pointing to tbell_pattern_block posts into wp:pattern blocks.
	 *
	 * @param array $blocks Array of parsed blocks.
	 * @return array Modified blocks array.
	 */
	private function convert_blocks_to_patterns( $blocks ) {
		foreach ( $blocks as &$block ) {
			if ( isset( $block['blockName'] ) && 'core/block' === $block['blockName'] ) {
				$post = get_post( $block['attrs']['ref'] );
				if ( $post && 'tbell_pattern_block' === $post->post_type ) {
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
	 * Filters pattern block data to apply attributes to the nested wp:block reference.
	 *
	 * @param mixed $pre_render   The pre-render value (null to allow normal rendering).
	 * @param array $parsed_block The parsed block data.
	 * @return mixed Modified pre-render value or null.
	 */
	public function filter_pattern_block_attributes( $pre_render, $parsed_block ) {
		// Only process wp:pattern blocks.
		if ( 'core/pattern' !== $parsed_block['blockName'] ) {
			return $pre_render;
		}

		$pattern_attrs = isset( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
		$slug          = $pattern_attrs['slug'] ?? '';

		// Remove attributes we don't want to pass down.
		unset( $pattern_attrs['slug'] );

		// If no attributes to apply, return as-is.
		if ( empty( $pattern_attrs ) ) {
			return $pre_render;
		}

		$synced_pattern_id = self::$synced_theme_patterns[ $slug ] ?? null;

		// If there is a synced_pattern_id, construct the block with a reference to the synced pattern
		// that also carries the rest of the pattern's attributes, then render it.
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
