<?php

require_once __DIR__ . '/class-pattern-manager-abstract-pattern.php';
class Twenty_Bellows_Pattern_Manager_API
{
	private static $base_route = 'pattern-manager/v1';

	public function __construct()
	{
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	public function register_routes()
	{
		register_rest_route(self::$base_route, '/patterns', [
			'methods'  => 'GET',
			'callback' => [$this, 'get_patterns'],
			'permission_callback' => function () {
				return true;
			},
		]);

		register_rest_route(self::$base_route, '/global-styles', [
			'methods'  => 'GET',
			'callback' => [$this, 'get_global_styles'],
			'permission_callback' => function () {
				return true;
			},
		]);

		register_rest_route(self::$base_route, '/pattern', [
			'methods'  => 'PUT',
			'callback' => [$this, 'save_block_pattern'],
			'permission_callback' => function () {
				return true;
			},
		]);

	}

	// Callback functions //////////

	function save_block_pattern($request)
	{
		$pattern = $request->get_body();

		$pattern = new Abstract_Pattern( json_decode($pattern, true) );

		if (empty($pattern)) {
			return new WP_Error('no_patterns', 'No pattern to save', array('status' => 400));
		}

		if ($pattern->source === 'user') {
			$response = $this->update_user_pattern( $pattern );
		}

		else if( $pattern->source === 'theme' ) {
			$response = $this->update_theme_pattern( $pattern );
		}

		else {
			return new WP_Error('invalid_pattern', 'Pattern source is not valid', array('status' => 400));
		}

		return rest_ensure_response($response);

	}

	function get_global_styles($request)
	{

		$editor_context = new WP_Block_Editor_Context(array('name' => 'core/edit-site'));
		$settings = get_block_editor_settings([], $editor_context);

		return rest_ensure_response($settings);
	}

	function get_patterns($request)
	{
		$unsynced_patterns = $this->get_block_patterns_from_registry();
		$synced_patterns = $this->get_block_patterns_from_database();

		$all_patterns = array_merge($unsynced_patterns, $synced_patterns);

		return rest_ensure_response($all_patterns);
	}

	// Utility functions //////////

	function update_theme_pattern ( $pattern ) {

		$path = $pattern->filePath;
		if ( ! $path ) {
			$theme = wp_get_theme();
			$filename = basename($pattern->name);
			$path = $theme->get_stylesheet_directory() . '/patterns/' . $filename . '.php';
		}

		$file_content = $this->build_pattern_file_metadata($pattern) . $pattern->content . "\n";
		$response = file_put_contents($path, $file_content);

		if ( ! $response ) {
			return new WP_Error('file_creation_failed', 'Failed to create pattern file', array('status' => 500));
		}

		return $pattern;
	}

	function build_pattern_file_metadata ( $pattern ) {

		$synced = $pattern->synced ? ' * Synced: yes' : '';
		$inserter = $pattern->inserter ? '' : ' * Inserter: no';
		$categories = '';
		$keywords = '';

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

	function update_user_pattern ( $pattern ) {

		$post = get_page_by_path($pattern->name, OBJECT, 'wp_block');

		if (empty($post)) {
			return new WP_Error('no_patterns', 'No pattern to save', array('status' => 400));
		}

		wp_update_post([
			'ID'           => $post->ID,
			'post_title'   => $pattern->title,
			'post_content' => $pattern->content,
			'post_excerpt' => $pattern->description,
		]);

		return $pattern;
	}

	function get_block_patterns_from_registry()
	{
		$patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();

		$unsynced_patterns = [];

		foreach ($patterns as $pattern) {
			$unsynced_patterns[] = Abstract_Pattern::from_registry($pattern);
		}

		return $unsynced_patterns;
	}

	function get_block_patterns_from_database()
	{
		$query = new WP_Query([
			'post_type'   => 'wp_block',
		]);

		$patterns = [];

		foreach ($query->posts as $post) {
			$metadata = get_post_meta($post->ID);
			$patterns[] = Abstract_Pattern::from_post($post, $metadata);
		}

		return $patterns;
	}
}
