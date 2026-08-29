<?php
namespace TwentyBellows\PatternBuilder;

/**
 * Registers the rowless `pb_pattern` post type.
 *
 * This registration creates no database rows and no admin UI — it exists for
 * two things, the same way core's `wp_template` registration does:
 *
 * 1. It hangs `Pattern_Builder_REST_Patterns_Controller` (string IDs, backed
 *    by theme pattern files) off core's REST routing.
 * 2. Because the type is `show_in_rest`, the block editor auto-creates a
 *    matching client-side entity from `/wp/v2/types`, which gives theme
 *    patterns entity-powered editing — undo, dirty tracking, save flow — with
 *    no mirror posts and no REST interception.
 */
class Pattern_Builder_Entity {

	/**
	 * The post type name.
	 */
	const POST_TYPE = 'pb_pattern';

	/**
	 * Constructor: hooks registration.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Registers the rowless post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'                  => array(
					'name'          => __( 'Theme Patterns', 'pattern-builder' ),
					'singular_name' => __( 'Theme Pattern', 'pattern-builder' ),
				),
				'description'             => __( 'File-based theme patterns managed by Pattern Builder.', 'pattern-builder' ),
				'public'                  => false,
				'show_ui'                 => false,
				'show_in_menu'            => false,
				'show_in_rest'            => true,
				'rest_namespace'          => 'pattern-builder/v1',
				'rest_base'               => 'patterns',
				'rest_controller_class'   => Pattern_Builder_REST_Patterns_Controller::class,
				// Registers the REST routes after the built-in post type routes, like wp_template.
				'late_route_registration' => true,
				'capability_type'         => array( 'pb_pattern', 'pb_patterns' ),
				'capabilities'            => array(
					'create_posts'           => 'edit_theme_options',
					'delete_posts'           => 'edit_theme_options',
					'delete_others_posts'    => 'edit_theme_options',
					'delete_private_posts'   => 'edit_theme_options',
					'delete_published_posts' => 'edit_theme_options',
					'edit_posts'             => 'edit_theme_options',
					'edit_others_posts'      => 'edit_theme_options',
					'edit_private_posts'     => 'edit_theme_options',
					'edit_published_posts'   => 'edit_theme_options',
					'publish_posts'          => 'edit_theme_options',
					'read'                   => 'edit_theme_options',
					'read_private_posts'     => 'edit_theme_options',
				),
				'map_meta_cap'            => true,
				'supports'                => array( 'title', 'editor' ),
			)
		);
	}
}
