<?php

require_once __DIR__ . '/class-pattern-manager-abstract-pattern.php';

class Twenty_Bellows_Pattern_Manager_API
{
	private static $base_route = 'pattern-manager/v1';

	public function __construct()
	{
		add_action('rest_api_init', [$this, 'register_routes']);

		add_filter('rest_post_dispatch', [$this, 'inject_theme_synced_patterns'], 10, 3);

		add_action('plugins_loaded', array($this, 'register_patterns'));

		add_filter('render_block', [$this, 'render_pb_blocks'] , 10, 2);

		add_filter('rest_request_before_callbacks', [$this, 'handle_hijack_block_update'], 10, 3);

	}


	/**
	 * Registers REST API routes for the pattern manager.
	 */
	public function register_routes(): void
	{
		register_rest_route(self::$base_route, '/patterns', [
			'methods'  => 'GET',
			'callback' => [$this, 'get_patterns'],
			'permission_callback' => [$this, 'default_permission_callback'],
		]);

		register_rest_route(self::$base_route, '/global-styles', [
			'methods'  => 'GET',
			'callback' => [$this, 'get_global_styles'],
			'permission_callback' => [$this, 'default_permission_callback'],
		]);

		register_rest_route(self::$base_route, '/pattern', [
			'methods'  => 'PUT',
			'callback' => [$this, 'save_block_pattern'],
			'permission_callback' => [$this, 'default_permission_callback'],
		]);

		// register a route expecting a pattern slug
		register_rest_route(self::$base_route, '/pattern', [
			'methods'  => 'DELETE',
			'callback' => [$this, 'delete_block_pattern'],
			'permission_callback' => [$this, 'default_permission_callback'],
		]);
	}

	/**
	 * Default permission callback for all routes.
	 */
	public function default_permission_callback(): bool
	{
		return true;
	}

	// Callback functions //////////

	public function delete_block_pattern(WP_REST_Request $request)
	{
		$pattern_data = json_decode($request->get_body(), true);

		if (empty($pattern_data)) {
			return new WP_Error('no_patterns', 'No pattern to save', ['status' => 400]);
		}

		$pattern = new Abstract_Pattern($pattern_data);

		if ($pattern->source === 'user') {
			$response = $this->delete_user_pattern($pattern);
		} elseif ($pattern->source === 'theme') {
			$response = $this->delete_theme_pattern($pattern);
		} else {
			return new WP_Error('invalid_pattern', 'Pattern source is not valid', ['status' => 400]);
		}

		return rest_ensure_response($response);
	}

	private function delete_user_pattern(Abstract_Pattern $pattern)
	{
		$post = get_page_by_path($pattern->name, OBJECT, 'wp_block');
		if (empty($post)) {
			return new WP_Error('pattern_not_found', 'Pattern not found', ['status' => 404]);
		}
		$deleted = wp_delete_post($post->ID, true);
		if (!$deleted) {
			return new WP_Error('pattern_delete_failed', 'Failed to delete pattern', ['status' => 500]);
		}
		return ['message' => 'Pattern deleted successfully'];
	}

	private function delete_theme_pattern(Abstract_Pattern $pattern)
	{
		$path = $pattern->filePath ?? wp_get_theme()->get_stylesheet_directory() . '/patterns/' . basename($pattern->name) . '.php';
		if (!file_exists($path)) {
			return new WP_Error('pattern_not_found', 'Pattern not found', ['status' => 404]);
		}
		$deleted = unlink($path);
		if (!$deleted) {
			return new WP_Error('pattern_delete_failed', 'Failed to delete pattern', ['status' => 500]);
		}
		return ['message' => 'Pattern deleted successfully'];
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

		if ($pattern->source === 'user') {
			$response = $this->update_user_pattern($pattern);
		} elseif ($pattern->source === 'theme') {
			$response = $this->update_theme_pattern($pattern);
		} else {
			return new WP_Error('invalid_pattern', 'Pattern source is not valid', ['status' => 400]);
		}

		return rest_ensure_response($response);
	}

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
		$theme_patterns = $this->get_block_patterns_from_theme_files();
		$user_patterns = $this->get_block_patterns_from_database();

		$all_patterns = array_merge($theme_patterns, $user_patterns);

		return rest_ensure_response($all_patterns);
	}

	// Utility functions //////////

	/**
	 * Updates a theme pattern.
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @return Abstract_Pattern|WP_Error
	 */
	private function update_theme_pattern(Abstract_Pattern $pattern)
	{
		$post = $this->get_pb_block_post_for_pattern($pattern);

		wp_update_post([
			'ID'           => $post->ID,
			'post_title'   => $pattern->title,
			'post_content' => $pattern->content,
			'post_excerpt' => $pattern->description,
		]);

		// ensure the 'synced' meta key is set
		if ($pattern->synced) {
			delete_post_meta($post->ID, 'wp_pattern_sync_status');
		} else {
			update_post_meta($post->ID, 'wp_pattern_sync_status', 'unsynced');
		}

		// store categories
		$category_slugs = array_map(function ($category) {
			return $category['slug'] ?? sanitize_title($category['name']);
		}, $pattern->categories);
		wp_set_object_terms($post->ID, $category_slugs, 'wp_pattern_category', false);

		return $pattern;
	}

	private function update_theme_pattern_file(Abstract_Pattern $pattern)
	{
		$path = $pattern->filePath ?? wp_get_theme()->get_stylesheet_directory() . '/patterns/' . basename($pattern->name) . '.php';

		$file_content = $this->build_pattern_file_metadata($pattern) . $pattern->content . "\n";
		$response = file_put_contents($path, $file_content);

		if (!$response) {
			return new WP_Error('file_creation_failed', 'Failed to create pattern file', ['status' => 500]);
		}

		return $pattern;
	}

	/**
	 * Builds metadata for a pattern file.
	 *
	 * @param Abstract_Pattern $pattern The pattern object.
	 * @return string
	 */
	private function build_pattern_file_metadata(Abstract_Pattern $pattern): string
	{

		// map the pattern categories to their slugs
		$category_slugs = array_map(function ($category) {
			return $category['slug'] ?? sanitize_title($category['name']);
		}, $pattern->categories);

		$synced = $pattern->synced ? "\n * Synced: yes" : '';
		$inserter = $pattern->inserter ? '' : "\n * Inserter: no";
		$categories = $category_slugs ? "\n * Categories: " . implode(', ', $category_slugs) : '';
		$keywords = $pattern->keywords ? "\n * Keywords: " . implode(', ', $pattern->keywords) : '';
		$blockTypes = $pattern->blockTypes ? "\n * Block Types: " . implode(', ', $pattern->blockTypes) : '';
		$templateTypes = $pattern->templateTypes ? "\n * Template Types: " . implode(', ', $pattern->templateTypes) : '';
		$postTypes = $pattern->postTypes ? "\n * Post Types: " . implode(', ', $pattern->postTypes) : '';

		return <<<METADATA
	<?php
	/**
	 * Title: $pattern->title
	 * Slug: $pattern->name
	 * Description: $pattern->description$synced$inserter$categories$keywords$blockTypes$templateTypes$postTypes
	 */
	?>

	METADATA;
	}

	/**
	 * Updates a user pattern.
	 *
	 * @param Abstract_Pattern $pattern The pattern to update.
	 * @return Abstract_Pattern|WP_Error
	 */
	private function update_user_pattern(Abstract_Pattern $pattern)
	{
		$post = get_page_by_path($pattern->name, OBJECT, 'wp_block');

		if (empty($post)) {

			$post = [
				'post_title'   => $pattern->title,
				'post_name'    => $pattern->name,
				'post_content' => $pattern->content,
				'post_excerpt' => $pattern->description,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			];

			wp_insert_post($post);
		} else {
			wp_update_post([
				'ID'           => $post->ID,
				'post_title'   => $pattern->title,
				'post_content' => $pattern->content,
				'post_excerpt' => $pattern->description,
				'meta_input' => array(
					'wp_pattern_sync_status' => $pattern->synced ? "" : "unsynced",
					'wp_pattern_block_types' => $pattern->blockTypes,
					'wp_pattern_template_types' => $pattern->templateTypes,
					'wp_pattern_post_types' => $pattern->postTypes,
				),
			]);
		}

		// ensure the 'synced' meta key is set
		if ($pattern->synced) {
			delete_post_meta($post->ID, 'wp_pattern_sync_status');
		} else {
			update_post_meta($post->ID, 'wp_pattern_sync_status', 'unsynced');
		}

		// store categories
		$category_slugs = array_map(function ($category) {
			return $category['slug'] ?? sanitize_title($category['name']);
		}, $pattern->categories);
		wp_set_object_terms($post->ID, $category_slugs, 'wp_pattern_category', false);

		return $pattern;
	}

	/**
	 * Retrieves block patterns from the registry.
	 *
	 * @return Abstract_Pattern[]
	 */
	private function get_block_patterns_from_registry(): array
	{
		$patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();

		foreach ($patterns as &$pattern) {

			if (isset($pattern['categories'])) {
				$category_items = [];

				foreach ($pattern['categories'] as $category) {

					$term = get_term_by('slug', $category, 'wp_pattern_category');

					if (! $term) {
						$term = wp_insert_term($category, 'wp_pattern_category');
					}

					$category_items[] = array(
						'id' => $term->term_id,
						'name' => $term->name,
						'slug' => $term->slug,
					);
				}
				$pattern['categories'] = $category_items;
			}
		}

		// Convert to Abstract_Pattern objects
		return array_map([Abstract_Pattern::class, 'from_registry'], $patterns);
	}

	/**
	 * Retrieves block patterns from the database.
	 *
	 * @return array
	 */
	private function get_block_patterns_from_database(): array
	{
		$query = new WP_Query(['post_type' => 'wp_block']);
		$patterns = [];

		foreach ($query->posts as $post) {
			$patterns[] = Abstract_Pattern::from_post($post);
		}

		return $patterns;
	}

	function format_pb_block_response($post)
	{

		$categories = wp_get_object_terms($post->ID, 'wp_pattern_category');

		return [
			'id' => $post->ID,
			'date' => get_post_time('c', false, $post),
			'date_gmt' => get_post_time('c', true, $post),
			'guid' => [
				'rendered' => get_the_guid($post),
				'raw' => get_the_guid($post),
			],
			'modified' => get_post_modified_time('c', false, $post),
			'modified_gmt' => get_post_modified_time('c', true, $post),
			'password' => $post->post_password,
			'slug' => $post->post_name,
			'status' => $post->post_status,
			// 'type' => $post->post_type,
			'type' => 'wp_block',
			'link' => get_permalink($post),
			'title' => [
				'raw' => $post->post_title,
			],
			'content' => [
				'raw' => $post->post_content,
				'protected' => false,
				'block_version' => 1, // Optional: Add block version if needed
			],
			'excerpt' => [
				'raw' => $post->post_excerpt,
				'rendered' => $post->post_excerpt,
				'protected' => false,
			],
			'meta' => [],
			'wp_pattern_category' => $categories,
			'wp_pattern_sync_status' => get_post_meta($post->ID, 'wp_pattern_sync_status', true),
			// '_links' => $response->get_links(), // Preserve existing links
		];
	}

	public function inject_theme_synced_patterns($response, $server, $request)
	{

		if (preg_match('#/wp/v2/blocks/(?P<id>\d+)#', $request->get_route(), $matches)) {

			// Return a single pattern
			$block_id = intval($matches['id']);
			$pb_block = get_post($block_id);
			if ($pb_block && $pb_block->post_type === 'pb_block') {
				$data = $this->format_pb_block_response($pb_block);
				$response->set_data($data);
				$response->set_status(200);
			}
		} else if ($request->get_route() === '/wp/v2/blocks') {

			// Return all patterns

			$data = $response->get_data();

			$patterns = $this->get_block_patterns_from_theme_files();

			foreach ($patterns as $pattern) {

				// if the pattern is not synced skip it
				if (! $pattern->synced) {
					continue;
				}

				$post = $this->get_pb_block_post_for_pattern($pattern);
				$data[] = $this->format_pb_block_response($post);
			}

			// Update the response
			$response->set_data($data);
		}

		return $response;
	}

	public function get_block_patterns_from_theme_files()
	{
		$pattern_files = glob(get_stylesheet_directory() . '/patterns/*.php');
		$patterns = [];

		foreach ($pattern_files as $pattern_file) {
			$pattern = Abstract_Pattern::from_file($pattern_file);

			$pattern_slug = $pattern->name;
			$pattern_source = $pattern->source;

			$pattern = $this->get_pb_block_post_for_pattern($pattern);
			$pattern = Abstract_Pattern::from_post($pattern);

			$pattern->name = $pattern_slug;
			$pattern->source = $pattern_source;

			$patterns[] = $pattern;
		}

		return $patterns;
	}

	public function get_pb_block_post_for_pattern($pattern)
	{

		$pattern_post = get_page_by_path(sanitize_title($pattern->name), OBJECT, 'pb_block');

		if ($pattern_post) {
			return $pattern_post;
		}

		$post_id = wp_insert_post(array(
			'post_title' => $pattern->title,
			'post_name' => $pattern->name,
			'post_content' => $pattern->content,
			'post_type' => 'pb_block',
			'post_status' => 'publish',
			'ping_status' => 'closed',
			'comment_status' => 'closed',
			'meta_input' => array(
				'wp_pattern_sync_status' => $pattern->synced ? "" : "unsynced",
				'wp_pattern_block_types' => implode(',', $pattern->blockTypes),
				'wp_pattern_template_types' => implode(',', $pattern->templateTypes),
				'wp_pattern_post_types' => implode(',', $pattern->postTypes),
				'wp_pattern_keywords' => implode(',', $pattern->keywords),
			),
		));

		// store categories
		$category_slugs = array_map(function ($category) {
			return $category['slug'] ?? sanitize_title($category['name']);
		}, $pattern->categories);

		wp_set_object_terms($post_id, $category_slugs, 'wp_pattern_category', false);

		//return the post by post id
		return get_post($post_id);
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

		$patterns = $this->get_block_patterns_from_theme_files();

		foreach ($patterns as $pattern) {

			$post = $this->get_pb_block_post_for_pattern($pattern);

			if ($pattern_registry->is_registered($pattern->name)) {
				$pattern_registry->unregister($pattern->name);
			}

			if ($pattern->synced) {
				$pattern_registry->register(
					$pattern->name,
					array(
						'title'   => $pattern->title . ' (Synced)',
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
	 * Renders a "pb_block" block pattern.
	 * This is a block pattern stored as a pb_block post type instead of a wp_block post type.
	 * Which means that it is a "theme pattern" instead of a "user pattern".
	 *
	 * This borrows heavily from the core block rendering function.
	 *
	 * @param string $block_content The block content.
	 * @param array  $block        The block data.
	 * @return string
	 */
	public function render_pb_blocks($block_content, $block)
	{
		// store a reference to the block to prevent infinite recursion
		static $seen_refs = array();

		// if we have a block pattern with no content we PROBABLY are trying to render
		// a pb_block (theme pattern)
		if ($block['blockName'] === 'core/block' && $block_content === '') {

			$attributes = $block['attrs'] ?? [];

			if (empty($attributes['ref'])) {
				return '';
			}

			$post = get_post($attributes['ref']);
			if (! $post || 'pb_block' !== $post->post_type) {
				return '';
			}

			// if we have already seen this block, return an empty string to prevent recursion
			if ( isset( $seen_refs[ $attributes['ref'] ] ) ) {
				return '';
			}

			if ('publish' !== $post->post_status || ! empty($post->post_password)) {
				return '';
			}

			$seen_refs[ $attributes['ref'] ] = true;


			// Handle embeds for reusable blocks.
			global $wp_embed;
			$content = $wp_embed->run_shortcode( $post->post_content );
			$content = $wp_embed->autoembed( $content );

			/**
			 * We set the `pattern/overrides` context through the `render_block_context`
			 * filter so that it is available when a pattern's inner blocks are
			 * rendering via do_blocks given it only receives the inner content.
			 */
			$has_pattern_overrides = isset($attributes['content']) && null !== get_block_bindings_source('core/pattern-overrides');
			if ($has_pattern_overrides) {
				$filter_block_context = static function ($context) use ($attributes) {
					$context['pattern/overrides'] = $attributes['content'];
					return $context;
				};
				add_filter('render_block_context', $filter_block_context, 1);
			}

			// Apply Block Hooks.
			$content = apply_block_hooks_to_content_from_post_object($content, $post);

			// Render the block content.
			$content = do_blocks($content);

			// It is safe to render this block again.  No infinite recursion worries.
			unset($seen_refs[$attributes['ref']]);

			if ($has_pattern_overrides) {
				remove_filter('render_block_context', $filter_block_context, 1);
			}

			return $content;
		}
		return $block_content;
	}

	function handle_hijack_block_update($response, $handler, $request)
	{
		$route = $request->get_route();
		if (preg_match('#^/wp/v2/blocks/(\d+)$#', $route, $matches) && $request->get_method() === 'PUT') {
			$id = intval($matches[1]);
			$post = get_post($id);
			if ($post && $post->post_type === 'pb_block') {
				$updated_pattern = json_decode($request->get_body(), true);
				$pattern = Abstract_Pattern::from_post($post);
				$pattern->content = $updated_pattern['content'];
				$response = $this->update_theme_pattern($pattern);
				return new WP_REST_Response($response, 200);
			}
		}
		return $response;
	}
}
