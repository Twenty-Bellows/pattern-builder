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

		register_rest_route(self::$base_route, '/pattern/(?P<slug>[\w-]+)', [
			'methods'  => 'PUT',
			'callback' => [$this, 'save_block_pattern'],
			'permission_callback' => function () {
				return true;
			},
			'args'     => [
				'slug' => [
					'required' => true,
					'type'     => 'string',
				],
			],
		]);

	}

	function save_block_pattern($request)
	{
		$slug = $request->get_param('slug');
		$pattern = $request->get_body();

		$pattern = new Abstract_Pattern( json_decode($pattern, true) );

		if (empty($pattern)) {
			return new WP_Error('no_patterns', 'No pattern to save', array('status' => 400));
		}

		if ($slug !== $pattern->name) {
			return new WP_Error('invalid_pattern', 'Pattern slug does not match', array('status' => 400));
		}

		return rest_ensure_response($pattern);
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

	function get_block_patterns_from_registry()
	{
		$patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();

		$unsynced_patterns = [];

		foreach ($patterns as $pattern) {
			$unsynced_patterns[] = [
				'name'     => $pattern['name'],
				'title'    => $pattern['title'],
				'description'    => $pattern['description'],
				'source'   => $pattern['source'] ?? 'theme',
				'content'  => $pattern['content'],
				'synced'   => false,
				'inserter' => true,
			];
		}

		return $unsynced_patterns;
	}

	function get_block_patterns_from_database()
	{
		$args = [
			'post_type'   => 'wp_block',
		];

		$query = new WP_Query($args);

		$patterns = [];

		foreach ($query->posts as $post) {

			$metadata = get_post_meta($post->ID);

			$patterns[] = [
				'name'     => $post->post_name,
				'title'    => $post->post_title,
				'description' => $post->post_excerpt,
				'content'  => $post->post_content,
				'synced'   => $metadata['wp_pattern_sync_status'][0] !== 'unsynced' ?? false,
				'inserter' => true,
				'source'   => 'user',
			];
		}

		return $patterns;
	}
}
