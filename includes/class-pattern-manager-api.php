<?php

require_once __DIR__ . '/class-pattern-manager-abstract-pattern.php';

class Twenty_Bellows_Pattern_Manager_API {
    private static $base_route = 'pattern-manager/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registers REST API routes for the pattern manager.
     */
    public function register_routes(): void {
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
    }

    /**
     * Default permission callback for all routes.
     */
    public function default_permission_callback(): bool {
        return true;
    }

    // Callback functions //////////

    /**
     * Saves a block pattern.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_REST_Response|WP_Error
     */
    public function save_block_pattern(WP_REST_Request $request) {
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
    public function get_global_styles(WP_REST_Request $request): WP_REST_Response {
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
    public function get_patterns(WP_REST_Request $request): WP_REST_Response {
        $unsynced_patterns = $this->get_block_patterns_from_registry();
        $synced_patterns = $this->get_block_patterns_from_database();

        $all_patterns = array_merge($unsynced_patterns, $synced_patterns);

        return rest_ensure_response($all_patterns);
    }

    // Utility functions //////////

    /**
     * Updates a theme pattern.
     *
     * @param Abstract_Pattern $pattern The pattern to update.
     * @return Abstract_Pattern|WP_Error
     */
    private function update_theme_pattern(Abstract_Pattern $pattern) {
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
    private function build_pattern_file_metadata(Abstract_Pattern $pattern): string {
		$synced = $pattern->synced ? "\n * Synced: yes" : '';
		$inserter = $pattern->inserter ? '' : "\n * Inserter: no";
        $categories = $pattern->categories ? "\n * Categories: " . implode(', ', $pattern->categories) : '';
        $keywords = $pattern->keywords ? "\n * Keywords: " . implode(', ', $pattern->keywords) : '';

		return <<<METADATA
	<?php
	/**
	 * Title: $pattern->title
	 * Slug: $pattern->name
	 * Description: $pattern->description$synced$inserter$categories$keywords
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
    private function update_user_pattern(Abstract_Pattern $pattern) {
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
			]);
		}

		// ensure the 'synced' meta key is set
		if ($pattern->synced) {
			delete_post_meta($post->ID, 'wp_pattern_sync_status');
		} else {
			update_post_meta($post->ID, 'wp_pattern_sync_status', 'unsynced');
		}

        return $pattern;
    }

    /**
     * Retrieves block patterns from the registry.
     *
     * @return array
     */
    private function get_block_patterns_from_registry(): array {
        $patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();

        return array_map([Abstract_Pattern::class, 'from_registry'], $patterns);
    }

    /**
     * Retrieves block patterns from the database.
     *
     * @return array
     */
    private function get_block_patterns_from_database(): array {
        $query = new WP_Query(['post_type' => 'wp_block']);
        $patterns = [];

        foreach ($query->posts as $post) {
            $metadata = get_post_meta($post->ID);
            $patterns[] = Abstract_Pattern::from_post($post, $metadata);
        }

        return $patterns;
    }
}
