<?php

if (!class_exists('Twenty_Bellows_Pattern_Manager')) {

	/**
	 * Render the query filter block
	 *
	 * @package TwentyBellows
	 */
	class Twenty_Bellows_Pattern_Manager
	{
		/**
		 * Constructor
		 */
		public function __construct()
		{
			add_action('init', array($this, 'init'));

			add_action( 'rest_api_init', array($this, 'register_routes') );

		}

		public function init()
		{
		}

		public function register_routes()
		{
			register_rest_route('pattern-manager/v1', '/patterns', [
				'methods'  => 'GET',
				'callback' => [$this, 'get_patterns'],
				'permission_callback' => function () {
					return true;
				},
			]);

			register_rest_route('pattern-manager/v1', '/global-styles', [
				'methods'  => 'GET',
				'callback' => [$this, 'get_global_styles'],
				'permission_callback' => function () {
					return true;
				},
			]);
		}

		function get_global_styles($request){

			$editor_context = new WP_Block_Editor_Context( array( 'name' => 'core/edit-site' ) );
			$settings = get_block_editor_settings( [], $editor_context );

			return rest_ensure_response($settings);
		}

		function get_patterns($request)
		{
			$unsynced_patterns = $this->get_block_patterns_from_registry();
			$synced_patterns = $this->get_block_patterns_from_database();

			$all_patterns = array_merge( $unsynced_patterns, $synced_patterns );

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
					'source'   => $pattern['source'] ?? 'theme',
					'content'  => $pattern['content'],
					'synced'   => false,
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
					'content'  => $post->post_content,
					'synced'   => $metadata['wp_pattern_sync_status'][0] !== 'unsynced' ?? false,
				];
			}

			return $patterns;
		}
	}
}

$pattern_manager = new Twenty_Bellows_Pattern_Manager();
