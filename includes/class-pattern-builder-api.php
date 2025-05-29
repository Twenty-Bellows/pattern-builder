<?php

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-builder-controller.php';

class Pattern_Builder_API
{
	private static $base_route = 'pattern-builder/v1';
	private $controller;

	public function __construct()
	{
		$this->controller = new Pattern_Builder_Controller();

		add_action('rest_api_init', [$this, 'register_routes']);
		add_action('init', array($this, 'register_patterns'));
		add_filter('rest_request_after_callbacks', [$this, 'inject_theme_synced_patterns'], 10, 3);
		add_filter('rest_request_before_callbacks', [$this, 'handle_hijack_block_update'], 10, 3);
	}


	/**
	 * Registers REST API routes for the Pattern Builder.
	 */
	public function register_routes(): void
	{
		register_rest_route(self::$base_route, '/global-styles', [
			'methods'  => 'GET',
			'callback' => [$this, 'get_global_styles'],
			'permission_callback' => [$this, 'read_permission_callback'],
		]);

		register_rest_route(self::$base_route, '/patterns', [
			'methods'  => 'GET',
			'callback' => [$this, 'get_patterns'],
			'permission_callback' => [$this, 'read_permission_callback'],
		]);

		register_rest_route(self::$base_route, '/pattern', [
			'methods'  => ['PUT', 'POST'],
			'callback' => [$this, 'save_block_pattern'],
			'permission_callback' => [$this, 'write_permission_callback'],
		]);

		register_rest_route(self::$base_route, '/pattern', [
			'methods'  => 'DELETE',
			'callback' => [$this, 'delete_block_pattern'],
			'permission_callback' => [$this, 'write_permission_callback'],
		]);
	}

	/**
	 * Permission callback for read operations.
	 * Allows access to all logged-in users who can edit posts.
	 *
	 * @return bool True if the user can read patterns, false otherwise.
	 */
	public function read_permission_callback()
	{
		return current_user_can('edit_posts');
	}

	/**
	 * Permission callback for write operations (PUT, POST, DELETE).
	 * Restricts access to administrators and editors only.
	 * Also verifies the REST API nonce for additional security.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return bool|WP_Error True if the user can modify patterns, WP_Error otherwise.
	 */
	public function write_permission_callback($request)
	{
		// First check if user has the required capability
		if (!current_user_can('edit_others_posts')) {
			return new WP_Error(
				'rest_forbidden',
				__('You do not have permission to modify patterns.', 'pattern-builder'),
				['status' => 403]
			);
		}

		// Verify the REST API nonce
		$nonce = $request->get_header('X-WP-Nonce');
		if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__('Cookie nonce is invalid', 'pattern-builder'),
				['status' => 403]
			);
		}

		return true;
	}

	// Callback functions //////////

	/**
	 * Retrieves global styles.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function get_global_styles(WP_REST_Request $request): WP_REST_Response
	{
		$editor_context = new WP_Block_Editor_Context(['name' => 'core/edit-site']);
		$settings = get_block_editor_settings([], $editor_context);

		return rest_ensure_response($settings);
	}

	/**
	 * Retrieves all block patterns.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function get_patterns(WP_REST_Request $request): WP_REST_Response
	{
		$theme_patterns = $this->controller->get_block_patterns_from_theme_files();
		$theme_patterns = array_map(function ($pattern) {
			$pattern_post = $this->controller->get_pb_block_post_for_pattern($pattern);
			$pattern_from_post = Abstract_Pattern::from_post($pattern_post);
			// TODO: The slug doesn't survive the trip to post and back since it has to be normalized.
			// so we just pull it form the original pattern and reset it here.  Not sure if that is the best way to do this.
			$pattern_from_post->name = $pattern->name;
			return $pattern_from_post;
		}, $theme_patterns);

		$user_patterns = $this->controller->get_block_patterns_from_database();

		$all_patterns = array_merge($theme_patterns, $user_patterns);

		return rest_ensure_response($all_patterns);
	}

	public function inject_theme_synced_patterns($response, $server, $request)
	{
		// Requesting a single pattern.  Inject the synced theme pattern.
		if (preg_match('#/wp/v2/blocks/(?P<id>\d+)#', $request->get_route(), $matches)) {
			$block_id = intval($matches['id']);
			$pb_block = get_post($block_id);
			if ($pb_block && $pb_block->post_type === 'pb_block') {
				$data = $this->format_pb_block_response($pb_block);
				$response = new WP_REST_Response($data);
			}
		}

		// Requesting all patterns.  Inject all of the synced theme patterns.
		else if ($request->get_route() === '/wp/v2/blocks') {

			$data = $response->get_data();
			$patterns = $this->controller->get_block_patterns_from_theme_files();

			foreach ($patterns as $pattern) {
				if (! $pattern->synced) continue;
				$post = $this->controller->get_pb_block_post_for_pattern($pattern);
				$data[] = $this->format_pb_block_response($post);
			}

			$response->set_data($data);
		}

		return $response;
	}

	public function format_pb_block_response($post)
	{
		// Use WordPress core's REST controller for proper formatting
		$controller = new WP_REST_Blocks_Controller('wp_block');

		// Create a mock request to pass to the controller
		$request = new WP_REST_Request('GET', '/wp/v2/blocks/' . $post->ID);
		$request->set_param('context', 'edit');

		// Change the post type to wp_block for proper formatting
		$post->post_type = 'wp_block';

		// Use the controller's prepare_item_for_response method
		$response = $controller->prepare_item_for_response($post, $request);
		$data = $response->get_data();

		// Add pattern-specific fields
		$categories = wp_get_object_terms($post->ID, 'wp_pattern_category', array('fields' => 'ids'));
		$data['wp_pattern_category'] = $categories;
		$data['wp_pattern_sync_status'] = get_post_meta($post->ID, 'wp_pattern_sync_status', true);

		// Include the _links from the response
		$data['_links'] = $response->get_links();

		return $data;
	}

	/**
	 * Registers block patterns for the theme.
	 *
	 * If the patterns are ALREADY registered, unregister them first.
	 * Synced patterns are registered with a reference to the post ID of their pattern.
	 * Unsynced patterns are registered with the content from the pb_block post.
	 */
	public function register_patterns()
	{

		$pattern_registry = WP_Block_Patterns_Registry::get_instance();

		$patterns = $this->controller->get_block_patterns_from_theme_files();

		foreach ($patterns as $pattern) {

			$post = $this->controller->get_pb_block_post_for_pattern($pattern);

			// if the post content is out of date we need to update it
			// TODO: When users are able to edit these patterns and ONLY effect the database content we will have to enact some conflict resolution.
			// TODO: More than just the content may change, so we should check for other changes as well.
			if ($post->post_content !== $pattern->content) {
				$post->post_content = $pattern->content;
				wp_update_post($post);
			}

			if ($pattern_registry->is_registered($pattern->name)) {
				$pattern_registry->unregister($pattern->name);
			}

			if ($pattern->synced) {
				$pattern_registry->register(
					$pattern->name,
					array(
						'title'   => $pattern->title,
						'inserter' => false,
						'content' => '<!-- wp:block {"ref":' . $post->ID . '} /-->',
					)
				);
			} else {
				$pattern_registry->register(
					$pattern->name,
					array(
						'title'   => $pattern->title,
						'description' => $pattern->description,
						'slug'   => $pattern->name,
						'inserter' => $pattern->inserter,
						'categories' => $pattern->categories,
						'keywords' => $pattern->keywords,
						'blockTypes' => $pattern->blockTypes,
						// TODO: Why does this make the pattern not show up in the inserter?
						// 'postTypes' => $pattern->postTypes,
						'templateTypes' => $pattern->templateTypes,
						'content' => $pattern->content,
					)
				);
			}
		}
	}

	/**
	 * Saves a block pattern.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_block_pattern(WP_REST_Request $request)
	{
		$pattern_data = json_decode($request->get_body(), true);

		if (empty($pattern_data)) {
			return new WP_Error('no_patterns', 'No pattern to save', ['status' => 400]);
		}

		$pattern = new Abstract_Pattern($pattern_data);

		$pattern = $this->controller->remap_patterns($pattern);

		if ($pattern->source === 'user') {
			$response = $this->controller->update_user_pattern($pattern);
		} elseif ($pattern->source === 'theme') {
			$response = $this->controller->update_theme_pattern($pattern);
		} else {
			return new WP_Error('invalid_pattern', 'Pattern source is not valid', ['status' => 400]);
		}

		return rest_ensure_response($response);
	}


	public function delete_block_pattern(WP_REST_Request $request)
	{
		$pattern_data = json_decode($request->get_body(), true);

		if (empty($pattern_data)) {
			return new WP_Error('no_patterns', 'No pattern to save', ['status' => 400]);
		}

		$pattern = new Abstract_Pattern($pattern_data);

		if ($pattern->source === 'user') {
			$response = $this->controller->delete_user_pattern($pattern);
		} elseif ($pattern->source === 'theme') {
			$response = $this->controller->delete_theme_pattern($pattern);
		} else {
			return new WP_Error('invalid_pattern', 'Pattern source is not valid', ['status' => 400]);
		}

		return rest_ensure_response($response);
	}

	function handle_hijack_block_update($response, $handler, $request)
	{
		$route = $request->get_route();
		if (preg_match('#^/wp/v2/blocks/(\d+)$#', $route, $matches) && $request->get_method() === 'PUT') {
			$id = intval($matches[1]);
			$post = get_post($id);
			if ($post && $post->post_type === 'pb_block') {
				// Check write permissions before allowing update
				if (!current_user_can('edit_others_posts')) {
					return new WP_Error(
						'rest_forbidden',
						__('You do not have permission to edit patterns.', 'pattern-builder'),
						['status' => 403]
					);
				}

				// Verify the REST API nonce
				$nonce = $request->get_header('X-WP-Nonce');
				if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
					return new WP_Error(
						'rest_cookie_invalid_nonce',
						__('Cookie nonce is invalid', 'pattern-builder'),
						['status' => 403]
					);
				}

				$updated_pattern = json_decode($request->get_body(), true);
				$pattern = Abstract_Pattern::from_post($post);
				$pattern->content = $updated_pattern['content'];
				$pattern = $this->controller->remap_patterns($pattern);
				$response = $this->controller->update_theme_pattern($pattern);
				return new WP_REST_Response($response, 200);
			}
		}
		return $response;
	}
}
