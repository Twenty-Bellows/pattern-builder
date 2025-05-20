<?php

class Pattern_Manager_Post_Type
{
	public function __construct()
	{
		add_action('init', [$this, 'register_pb_block_post_type']);
	}

	/**
	 * Registers the pb_block custom post type.
	 */
	public function register_pb_block_post_type()
	{
		$labels = [
			'name'               => __('PB Blocks', 'pattern-manager'),
			'singular_name'      => __('PB Block', 'pattern-manager'),
		];

		$args = [
			'labels'             => $labels,

			'public'             => true,
			'show_ui'            => true,
			'show_in_menu'       => true,

			'show_in_rest'       => true,
			'rest_base'          => 'pb_blocks',
			'supports'           => ['title', 'editor', 'revisions'],
			'hierarchical'       => false,
			'capability_type'    => 'pb_block',
			'map_meta_cap'       => true,
		];

		register_post_type('pb_block', $args);

		register_post_meta('pb_block', 'wp_pattern_sync_status', [
			'show_in_rest' => true,
			'type'         => 'string',
			'single'       => true,
		]);

		register_post_meta('pb_block', 'wp_pattern_block_types', [
			'show_in_rest' => true,
			'type'         => 'string',
			'single'       => true,
		]);

		register_post_meta('pb_block', 'wp_pattern_template_types', [
			'show_in_rest' => true,
			'type'         => 'string',
			'single'       => true,
		]);

		register_post_meta('pb_block', 'wp_pattern_post_types', [
			'show_in_rest' => true,
			'type'         => 'string',
			'single'       => true,
		]);



		/**
		 * Add custom capabilities for the pb_block post type.
		 */

		$roles = ['administrator', 'editor'];

		$capabilities = [
			'edit_pb_block',
			'read_pb_block',
			'delete_pb_block',
			'edit_pb_blocks',
			'edit_others_pb_blocks',
			'publish_pb_blocks',
			'read_private_pb_blocks',
			'delete_pb_blocks',
			'delete_private_pb_blocks',
			'delete_published_pb_blocks',
			'delete_others_pb_blocks',
			'edit_private_pb_blocks',
			'edit_published_pb_blocks',
		];

		// Assign capabilities to each role
		foreach ($roles as $role_name) {
			$role = get_role($role_name);
			if ($role) {
				foreach ($capabilities as $capability) {
					$role->add_cap($capability);
				}
			}
		}
	}

}
