<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- camelCase fields intentionally mirror the JS AbstractPattern class.

namespace TwentyBellows\PatternBuilder;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for block patterns.
 *
 * Follows the model of core's `WP_REST_Templates_Controller`: theme patterns
 * are file-backed entities addressed by string IDs (their namespaced pattern
 * name, e.g. `theme-slug/pattern-name`) with no database row behind them.
 * Reads come from the pattern files; writes go back to the pattern files.
 *
 * The collection also lists user patterns (`wp_block` posts, numeric IDs) so
 * one request paints the whole pattern library, but single-item routes address
 * theme patterns only — user patterns remain managed by core's own
 * `/wp/v2/blocks` endpoints.
 *
 * Registered as the REST controller of the rowless `pb_pattern` post type, so
 * the block editor auto-registers a matching client-side entity from
 * `/wp/v2/types`.
 */
class Pattern_Builder_REST_Patterns_Controller extends WP_REST_Controller {

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type;

	/**
	 * Pattern file store.
	 *
	 * @var Pattern_File_Store
	 */
	protected $store;

	/**
	 * Constructor.
	 *
	 * @param string $post_type Post type.
	 */
	public function __construct( $post_type = 'pb_pattern' ) {
		$this->post_type = $post_type;
		$obj             = get_post_type_object( $post_type );
		$this->rest_base = ! empty( $obj->rest_base ) ? $obj->rest_base : 'patterns';
		$this->namespace = ! empty( $obj->rest_namespace ) ? $obj->rest_namespace : 'pattern-builder/v1';
		$this->store     = new Pattern_File_Store();
	}

	/**
	 * Registers the controller's routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'update_items_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\/\w%-]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'The namespaced name of the theme pattern.', 'pattern-builder' ),
						'type'        => 'string',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_items_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'update_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks whether the user can read patterns.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_cannot_read_patterns',
			__( 'Sorry, you are not allowed to view patterns.', 'pattern-builder' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Checks whether the user can create, update, or delete theme patterns.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function update_items_permissions_check( $request ) {
		if ( current_user_can( 'edit_theme_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_cannot_manage_patterns',
			__( 'Sorry, you are not allowed to manage theme patterns.', 'pattern-builder' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Lists every pattern: theme file patterns and user patterns.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$items = array();

		foreach ( $this->store->get_theme_patterns() as $pattern ) {
			$items[] = $this->prepare_pattern_for_response( $pattern, $request );
		}

		foreach ( $this->store->get_user_patterns() as $pattern ) {
			$items[] = $this->prepare_pattern_for_response( $pattern, $request );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Returns a single theme pattern.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$pattern = $this->find_pattern( $request['id'] );

		if ( null === $pattern ) {
			return $this->not_found_error();
		}

		return rest_ensure_response( $this->prepare_pattern_for_response( $pattern, $request ) );
	}

	/**
	 * Creates a theme pattern.
	 *
	 * When `fromWpBlock` carries a wp_block post ID, that user pattern is
	 * converted: its content (with theme edits from the request applied) is
	 * written to a pattern file and the post is deleted.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$from_post = null;

		if ( ! empty( $request['fromWpBlock'] ) ) {
			$from_post = get_post( (int) $request['fromWpBlock'] );

			if ( ! $from_post || 'wp_block' !== $from_post->post_type ) {
				return new WP_Error(
					'pattern_builder_wp_block_not_found',
					__( 'No user pattern was found to convert.', 'pattern-builder' ),
					array( 'status' => 404 )
				);
			}

			$pattern = Abstract_Pattern::from_post( $from_post );
		} else {
			if ( ! is_string( $request['title'] ) || '' === trim( $request['title'] ) ) {
				return new WP_Error(
					'pattern_builder_missing_title',
					__( 'A pattern needs a title.', 'pattern-builder' ),
					array( 'status' => 400 )
				);
			}

			$pattern = new Abstract_Pattern( array( 'title' => $request['title'] ) );
		}

		$pattern = $this->apply_request_to_pattern( $pattern, $request );

		if ( false === strpos( $pattern->name, '/' ) ) {
			$pattern->name = get_stylesheet() . '/' . $pattern->name;
		}

		$pattern->source   = 'theme';
		$pattern->id       = $pattern->name;
		$pattern->filePath = null;

		if ( null !== $this->store->find_theme_pattern( $pattern->name ) ) {
			return new WP_Error(
				'pattern_builder_pattern_exists',
				__( 'A theme pattern with this name already exists.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( $from_post ) {
			$saved = $this->store->convert_user_pattern_to_theme( $from_post, $pattern, $this->get_save_options( $request ) );
		} else {
			$saved = $this->store->update_theme_pattern( $pattern, $this->get_save_options( $request ) );
		}

		if ( is_wp_error( $saved ) ) {
			return $this->as_rest_error( $saved );
		}

		$response = rest_ensure_response( $this->prepare_pattern_for_response( $saved, $request ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Updates a theme pattern, writing its file.
	 *
	 * A request whose `source` is `user` converts the theme pattern into a
	 * user pattern instead: the file is deleted and a wp_block post created.
	 * The response then describes the new user pattern (numeric `id`).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$pattern = $this->find_pattern( $request['id'] );

		if ( null === $pattern ) {
			return $this->not_found_error();
		}

		$original = clone $pattern;
		$pattern  = $this->apply_request_to_pattern( $pattern, $request );

		/*
		 * A renamed pattern belongs in a file named after its new slug, so
		 * write a fresh file and drop the old one once that succeeds.
		 */
		$renamed = $pattern->name !== $original->name;
		if ( $renamed ) {
			$pattern->filePath = null;
			$pattern->id       = $pattern->name;
		}

		if ( 'user' === $request['source'] ) {
			$converted = $this->store->convert_theme_pattern_to_user( $pattern );

			if ( is_wp_error( $converted ) ) {
				return $this->as_rest_error( $converted );
			}

			return rest_ensure_response( $this->prepare_pattern_for_response( $converted, $request ) );
		}

		$saved = $this->store->update_theme_pattern( $pattern, $this->get_save_options( $request ) );

		if ( is_wp_error( $saved ) ) {
			return $this->as_rest_error( $saved );
		}

		if ( $renamed ) {
			$this->store->delete_theme_pattern( $original );
		}

		return rest_ensure_response( $this->prepare_pattern_for_response( $saved, $request ) );
	}

	/**
	 * Deletes a theme pattern's file.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$pattern = $this->find_pattern( $request['id'] );

		if ( null === $pattern ) {
			return $this->not_found_error();
		}

		$previous = $this->prepare_pattern_for_response( $pattern, $request );
		$deleted  = $this->store->delete_theme_pattern( $pattern );

		if ( is_wp_error( $deleted ) ) {
			return $this->as_rest_error( $deleted );
		}

		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Finds a theme pattern for a route's `id` parameter.
	 *
	 * @param string $id The namespaced pattern name.
	 * @return Abstract_Pattern|null
	 */
	protected function find_pattern( $id ) {
		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}

		return $this->store->find_theme_pattern( rawurldecode( $id ) );
	}

	/**
	 * Overlays a request's parameters onto a pattern.
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @param WP_REST_Request  $request The request.
	 * @return Abstract_Pattern
	 */
	protected function apply_request_to_pattern( Abstract_Pattern $pattern, WP_REST_Request $request ) {
		$title = $request['title'];
		if ( is_array( $title ) && isset( $title['raw'] ) ) {
			$title = $title['raw'];
		}
		if ( is_string( $title ) && '' !== trim( $title ) ) {
			$pattern->title = $title;
		}

		$content = $request['content'];
		if ( is_array( $content ) && isset( $content['raw'] ) ) {
			$content = $content['raw'];
		}
		if ( is_string( $content ) ) {
			$pattern->content = $content;
		}

		if ( is_string( $request['name'] ) && '' !== $request['name'] ) {
			$pattern->name = $request['name'];
		}

		if ( is_string( $request['description'] ) ) {
			$pattern->description = $request['description'];
		}

		foreach ( array( 'categories', 'keywords', 'blockTypes', 'postTypes', 'templateTypes' ) as $list_field ) {
			if ( is_array( $request[ $list_field ] ) ) {
				$pattern->{$list_field} = array_values( array_filter( array_map( 'strval', $request[ $list_field ] ), 'strlen' ) );
			}
		}

		if ( null !== $request['inserter'] ) {
			$pattern->inserter = rest_sanitize_boolean( $request['inserter'] );
		}

		if ( null !== $request['synced'] ) {
			$pattern->synced = rest_sanitize_boolean( $request['synced'] );
		}

		if ( null !== $request['viewportWidth'] ) {
			$width                  = (int) $request['viewportWidth'];
			$pattern->viewportWidth = $width > 0 ? $width : null;
		}

		return $pattern;
	}

	/**
	 * Reads the save options this plugin's editor tools append to requests.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array Options for Pattern_File_Store::update_theme_pattern().
	 */
	protected function get_save_options( WP_REST_Request $request ) {
		$options = array();

		if ( 'true' === $request->get_param( 'patternBuilderLocalize' ) ) {
			$options['localize'] = true;
		}

		if ( 'false' === $request->get_param( 'patternBuilderImportImages' ) ) {
			$options['import_images'] = false;
		}

		return $options;
	}

	/**
	 * Shapes a pattern the way the editor's entity layer expects.
	 *
	 * @param Abstract_Pattern $pattern The pattern.
	 * @param WP_REST_Request  $request The request.
	 * @return array The response data.
	 */
	protected function prepare_pattern_for_response( Abstract_Pattern $pattern, $request ) {
		$is_theme = 'theme' === $pattern->source;

		$data = array(
			'id'            => $is_theme ? $pattern->name : $pattern->id,
			'name'          => $pattern->name,
			'slug'          => $is_theme ? $pattern->name : basename( (string) $pattern->name ),
			'type'          => $is_theme ? $this->post_type : 'wp_block',
			'status'        => 'publish',
			'title'         => array(
				'raw'      => $pattern->title,
				'rendered' => $pattern->title,
			),
			'content'       => array(
				'raw'           => $pattern->content,
				'block_version' => block_version( $pattern->content ),
			),
			'description'   => $pattern->description,
			'categories'    => array_values( $pattern->categories ),
			'keywords'      => array_values( $pattern->keywords ),
			'blockTypes'    => array_values( $pattern->blockTypes ),
			'postTypes'     => array_values( $pattern->postTypes ),
			'templateTypes' => array_values( $pattern->templateTypes ),
			'inserter'      => (bool) $pattern->inserter,
			'synced'        => (bool) $pattern->synced,
			'viewportWidth' => $pattern->viewportWidth,
			'source'        => $pattern->source,
			// Where the pattern was first copied from, when it is somebody
			// else's work; empty when it originated here (D38). Read-only:
			// it is written on install and carried, never edited.
			'origin'        => (string) $pattern->origin,
		);

		if ( $is_theme && current_user_can( 'edit_theme_options' ) ) {
			$data['filePath'] = $pattern->filePath;
		}

		if ( $is_theme ) {
			$self = rest_url( $this->namespace . '/' . $this->rest_base . '/' . $pattern->name );

			$data['_links'] = array(
				'self'       => array(
					array( 'href' => $self ),
				),
				'collection' => array(
					array( 'href' => rest_url( $this->namespace . '/' . $this->rest_base ) ),
				),
			);

			/*
			 * Action links, as core's posts controller advertises them. The
			 * editor's save button reads `wp:action-publish` off the record;
			 * without it, it assumes the user can only "Submit for Review".
			 */
			if ( current_user_can( 'edit_theme_options' ) ) {
				$data['_links']['wp:action-publish'] = array(
					array( 'href' => $self ),
				);
			}

			if ( current_user_can( 'unfiltered_html' ) ) {
				$data['_links']['wp:action-unfiltered-html'] = array(
					array( 'href' => $self ),
				);
			}
		}

		return $data;
	}

	/**
	 * Turns a store error into a proper REST error response.
	 *
	 * @param WP_Error $error The error.
	 * @return WP_Error The error, with a status the REST server understands.
	 */
	protected function as_rest_error( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$error->add_data( array( 'status' => 500 ) );
		}

		return $error;
	}

	/**
	 * The error for a pattern the theme does not have.
	 *
	 * @return WP_Error
	 */
	protected function not_found_error() {
		return new WP_Error(
			'pattern_builder_pattern_not_found',
			__( 'No theme pattern with that name exists.', 'pattern-builder' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Retrieves the pattern schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $this->post_type,
			'type'       => 'object',
			'properties' => array(
				'id'            => array(
					'description' => __( 'Pattern identity: the namespaced name for theme patterns, the post ID for user patterns.', 'pattern-builder' ),
					'type'        => array( 'string', 'integer' ),
					'readonly'    => true,
				),
				'name'          => array(
					'description' => __( 'Namespaced pattern name.', 'pattern-builder' ),
					'type'        => 'string',
				),
				'slug'          => array(
					'description' => __( 'Pattern slug.', 'pattern-builder' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'type'          => array(
					'description' => __( 'Entity type of the pattern.', 'pattern-builder' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'status'        => array(
					'description' => __( 'Pattern status.', 'pattern-builder' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'title'         => array(
					'description' => __( 'Pattern title.', 'pattern-builder' ),
					'type'        => array( 'string', 'object' ),
					'properties'  => array(
						'raw'      => array( 'type' => 'string' ),
						'rendered' => array(
							'type'     => 'string',
							'readonly' => true,
						),
					),
				),
				'content'       => array(
					'description' => __( 'Pattern block markup.', 'pattern-builder' ),
					'type'        => array( 'string', 'object' ),
					'properties'  => array(
						'raw'           => array( 'type' => 'string' ),
						'block_version' => array(
							'type'     => 'integer',
							'readonly' => true,
						),
					),
				),
				'description'   => array(
					'description' => __( 'Pattern description.', 'pattern-builder' ),
					'type'        => 'string',
				),
				'categories'    => array(
					'description' => __( 'Pattern category slugs.', 'pattern-builder' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
				'keywords'      => array(
					'description' => __( 'Pattern keywords.', 'pattern-builder' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
				'blockTypes'    => array(
					'description' => __( 'Block types this pattern is offered for.', 'pattern-builder' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
				'postTypes'     => array(
					'description' => __( 'Post types this pattern is limited to.', 'pattern-builder' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
				'templateTypes' => array(
					'description' => __( 'Template types this pattern is offered for.', 'pattern-builder' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
				'inserter'      => array(
					'description' => __( 'Whether the pattern is offered by the block inserter.', 'pattern-builder' ),
					'type'        => 'boolean',
				),
				'synced'        => array(
					'description' => __( 'Whether inserted copies of the pattern stay linked to it.', 'pattern-builder' ),
					'type'        => 'boolean',
				),
				'viewportWidth' => array(
					'description' => __( 'Intended viewport width when previewing the pattern, in pixels.', 'pattern-builder' ),
					'type'        => array( 'integer', 'null' ),
				),
				'source'        => array(
					'description' => __( 'Where the pattern lives: a theme file or the database.', 'pattern-builder' ),
					'type'        => 'string',
					'enum'        => array( 'theme', 'user' ),
				),
				'origin'        => array(
					'description' => __( 'The cloud pattern this one was first copied from, or empty when it is original work here.', 'pattern-builder' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'fromWpBlock'   => array(
					'description' => __( 'On creation, the ID of a wp_block post to convert into this theme pattern.', 'pattern-builder' ),
					'type'        => 'integer',
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
