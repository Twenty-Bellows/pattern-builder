<?php

require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-builder-controller.php';

class Pattern_Builder_API
{
	private static $synced_theme_patterns = [];
	private static $base_route = 'pattern-builder/v1';
	private $controller;

	public function __construct()
	{
		$this->controller = new Pattern_Builder_Controller();

		// add_action('rest_api_init', [$this, 'register_routes']);
		add_action('init', array($this, 'register_patterns'));

		// TODO: This is shared code with the Synced Patterns for Themes plugin.
		// It should be moved to a common location and make sure there are no conflicts.
		add_filter('rest_request_after_callbacks', [$this, 'inject_theme_synced_patterns'], 10, 3);

		add_filter('rest_request_before_callbacks', [$this, 'handle_hijack_block_update'], 10, 3);

		add_filter('rest_request_before_callbacks', [$this, 'handle_block_to_pattern_conversion'], 10, 3);

		// Add filter for wp:pattern block pre-rendering to modify block data
		add_filter('pre_render_block', [$this, 'filter_pattern_block_attributes'], 10, 2);

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
		$settings['mediaUpload'] = true;
		$settings['mediaLibrary'] = true;

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

				// TODO: Optimize this
				// NOTE: Because the name of the post is the slug, but the slug has /'s removed, we have to find the ACTUALY slug from the file.
				$all_patterns = $this->controller->get_block_patterns_from_theme_files();
				$pattern_slug = $pb_block->post_name;
				$pattern = array_find( $all_patterns, function ($p) use ($pattern_slug) {
					return sanitize_title($p->name) === sanitize_title($pattern_slug);
				});

				$data = $this->format_pb_block_response($pb_block, $request);
				$response = new WP_REST_Response($data);
			}
		}

		// Requesting all patterns.  Inject all of the synced theme patterns.
		else if ($request->get_route() === '/wp/v2/blocks') {

			$data = $response->get_data();
			$patterns = $this->controller->get_block_patterns_from_theme_files();

			foreach ($patterns as $pattern) {
				$post = $this->controller->get_pb_block_post_for_pattern($pattern);
				$data[] = $this->format_pb_block_response($post, $request);
			}

			$response->set_data($data);
		}

		return $response;
	}

	public function format_pb_block_response($post, $request)
	{
		$post->post_type = 'wp_block';

		// Create a mock request to pass to the controller
		$mock_request = new WP_REST_Request('GET', '/wp/v2/blocks/' . $post->ID);
		$mock_request->set_param('context', 'edit');

		$controller = new WP_REST_Blocks_Controller('wp_block');
		$response = $controller->prepare_item_for_response($post, $mock_request);

		$data = $controller->prepare_response_for_collection($response);

		$data['source'] = 'theme';

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
			if ($post->post_content !== $pattern->content) {
				$post->post_content = $pattern->content;
				wp_update_post($post);
			}

			if ($post->post_title !== $pattern->title) {
				$post->post_title = $pattern->title;
				wp_update_post($post);
			}

			if ($post->post_excerpt !== $pattern->description) {
				$post->post_excerpt = $pattern->description;
				wp_update_post($post);
			}

			if ($pattern->synced) {
				delete_post_meta($post->ID, 'wp_pattern_sync_status');
			} else {
				update_post_meta($post->ID, 'wp_pattern_sync_status', 'unsynced');
			}

			if ($pattern_registry->is_registered($pattern->name)) {
				$pattern_registry->unregister($pattern->name);
			}

			if ($pattern->synced) {

				self::$synced_theme_patterns[$pattern->name] = $post->ID;

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
						'inserter' => false,
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
		if (pb_fs()->can_use_premium_code__premium_only() || pb_fs_testing()) {

			$route = $request->get_route();

			if (preg_match('#^/wp/v2/blocks/(\d+)$#', $route, $matches) && $request->get_method() === 'PUT') {

				$id = intval($matches[1]);
				$post = get_post($id);
				$updated_pattern = json_decode($request->get_body(), true);

				$convert_user_pattern_to_theme_pattern = false;

				if ($post && $post->post_type === 'wp_block') {

					if (isset($updated_pattern['source']) && $updated_pattern['source'] === 'theme' ) {
						// we are attempting to convert a USER pattern to a THEME pattern.
						$convert_user_pattern_to_theme_pattern = true;
					}

				}

				if ($post && $post->post_type === 'pb_block' || $convert_user_pattern_to_theme_pattern ) {

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

					$pattern = Abstract_Pattern::from_post($post);

					if ( isset($updated_pattern['content']) ) {
						// remap pb_blocks to patterns
						$blocks = parse_blocks($updated_pattern['content']);
						$blocks = $this->convert_blocks_to_patterns($blocks);
						$pattern->content = serialize_blocks($blocks);
						//TODO: Format the content to be easy on the eyes.
					}

					if( isset($updated_pattern['title']) ) {
						$pattern->title = $updated_pattern['title'];
					}

					if( isset($updated_pattern['excerpt']) ) {
						$pattern->description = $updated_pattern['excerpt'];
					}

					if( isset($updated_pattern['wp_pattern_sync_status']) ) {
						$pattern->synced = $updated_pattern['wp_pattern_sync_status'] !== 'unsynced';
					}

					if (isset($updated_pattern['source']) && $updated_pattern['source'] === 'user' ) {
						// we are attempting to convert a THEME pattern to a USER pattern.
						$response = $this->controller->update_user_pattern($pattern);
						$post = get_post($pattern->id);
					}
					else {
						$response = $this->controller->update_theme_pattern($pattern);
						$post = $this->controller->get_pb_block_post_for_pattern($pattern);

					}

					$formatted_response = $this->format_pb_block_response($post, $request);
					return new WP_REST_Response($formatted_response, 200);

				}
			}
		}
		return $response;
	}

	public function handle_block_to_pattern_conversion( $response, $handler, $request ) {
		if ($request->get_method() === 'PUT' || $request->get_method() === 'POST') {
			$body = json_decode($request->get_body(), true);
			if (isset($body['content'])) {
				// parse the content string into blocks
				$blocks = parse_blocks($body['content']);
				$blocks = $this->convert_blocks_to_patterns($blocks);
				// convert the blocks back to a string
				$body['content'] = serialize_blocks($blocks);
				$request->set_body(wp_json_encode($body));
			}
		}
		return $response;
	}

	private function convert_blocks_to_patterns( $blocks ) {
		foreach ($blocks as &$block) {
			if ( isset($block['blockName']) && $block['blockName'] === 'core/block') {
				$post = get_post($block['attrs']['ref']);
				if ( $post->post_type === 'pb_block') {
					$slug = Pattern_Builder_Controller::format_pattern_slug_from_post($post->post_name);
					$block['blockName'] = 'core/pattern';
					$block['attrs'] = [
						'slug' => $slug,
					];
					if ( !empty($post->post_title) ) {
						$block['attrs']['title'] = $post->post_title;
					}
					unset($block['attrs']['ref']);
				}
			}
			elseif (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
				$block['innerBlocks'] = $this->convert_blocks_to_patterns($block['innerBlocks']);
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
	public function filter_pattern_block_attributes($pre_render, $parsed_block)
	{
		// Only process wp:pattern blocks
		if ($parsed_block['blockName'] !== 'core/pattern') {
			return $pre_render;
		}

		// Extract attributes from the pattern block
		$pattern_attrs = isset($parsed_block['attrs']) ? $parsed_block['attrs'] : [];

		$slug = $pattern_attrs['slug'] ?? '';

		// Remove attributes we don't want to pass down
		unset($pattern_attrs['slug']);

		// If no attributes to apply, return as-is
		if (empty($pattern_attrs)) {
			return $pre_render;
		}

		$synced_pattern_id = self::$synced_theme_patterns[$slug];

		// if there is a synced_pattern_id then contruct the block with a reference to the synced pattern that also has the rest of the pattern's attributes and render it.
		if ($synced_pattern_id) {
			$block_attributes = array_merge(
				['ref' => $synced_pattern_id],
				$pattern_attrs
			);
			$block_attributes = wp_json_encode($block_attributes);
			$block_string = "<!-- wp:block $block_attributes /-->";
			return do_blocks($block_string);
		}

		return $pre_render;
	}



}
