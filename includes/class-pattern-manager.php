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
		}

		function get_patterns($request)
		{
			$unsynced_patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
			$synced_patterns = [];

			$all_patterns = array_merge( $unsynced_patterns, $synced_patterns );

			return rest_ensure_response($all_patterns);
		}

	}
}

$pattern_manager = new Twenty_Bellows_Pattern_Manager();
