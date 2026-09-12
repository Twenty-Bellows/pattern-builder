<?php
namespace TwentyBellows\PatternBuilder;

/**
 * Registers the rowless `pb_pattern` post type.
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
		add_action( 'rest_api_init', array( $this, 'register_user_pattern_fields' ) );
	}

	/**
	 * A user pattern's `origin` and `cloud` on its wp_block record, under the names a theme
	 * pattern's record uses.
	 *
	 * @return void
	 */
	public function register_user_pattern_fields() {
		$fields = array(
			'origin' => array( Pattern_File_Store::META_ORIGIN, __( 'The cloud pattern this one was first copied from, or empty when it is original work here.', 'pattern-builder' ) ),
			'cloud'  => array( Pattern_File_Store::META_CLOUD, __( 'The name of this pattern’s copy on the cloud, or empty when it has none.', 'pattern-builder' ) ),
		);

		foreach ( $fields as $field => list( $meta_key, $description ) ) {
			register_rest_field(
				'wp_block',
				$field,
				array(
					'get_callback' => static function ( $record ) use ( $meta_key ) {
						return (string) get_post_meta( (int) $record['id'], $meta_key, true );
					},
					'schema'       => array(
						'description' => $description,
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}
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
