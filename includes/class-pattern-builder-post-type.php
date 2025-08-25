<?php

namespace TwentyBellows\PatternBuilder;

class Pattern_Builder_Post_Type {

	public function __construct() {
		add_action( 'init', array( $this, 'register_tbell_pattern_block_post_type' ) );
		add_filter( 'render_block', array( $this, 'render_tbell_pattern_blocks' ), 10, 2 );
		add_filter( 'register_block_type_args', array( $this, 'add_content_attribute_to_core_pattern_block' ), 10, 2 );
	}

	/**
	 * Adds a "content" attribute to the core/pattern block type.
	 * This is used to store the pattern overrides for the block.
	 *
	 * @param array  $args The block type arguments.
	 * @param string $block_type The block type name.
	 * @return array
	 */
	public function add_content_attribute_to_core_pattern_block( $args, $block_type ) {
		if ( $block_type === 'core/pattern' ) {
			$extra_attributes   = array(
				'content' => array(
					'type' => 'object',
				),
			);
			$args['attributes'] = array_merge( $args['attributes'], $extra_attributes );
		}
		return $args;
	}

	/**
	 * Registers the tbell_pattern_block custom post type.
	 */
	public function register_tbell_pattern_block_post_type() {
		$labels = array(
			'name'          => __( 'Pattern Builder Blocks', 'pattern-builder' ),
			'singular_name' => __( 'Pattern Builder Block', 'pattern-builder' ),
		);

		$args = array(
			'labels'          => $labels,

			'public'          => true,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => true,
			'rest_base'       => 'tbell_pattern_blocks',
			'supports'        => array( 'title', 'editor', 'revisions' ),
			'hierarchical'    => false,
			'capability_type' => 'tbell_pattern_block',
			'map_meta_cap'    => true,
		);

		$result = register_post_type( 'tbell_pattern_block', $args );

		register_post_meta(
			'tbell_pattern_block',
			'wp_pattern_sync_status',
			array(
				'show_in_rest' => true,
				'type'         => 'string',
				'single'       => true,
			)
		);

		register_post_meta(
			'tbell_pattern_block',
			'wp_pattern_block_types',
			array(
				'show_in_rest' => true,
				'type'         => 'string',
				'single'       => true,
			)
		);

		register_post_meta(
			'tbell_pattern_block',
			'wp_pattern_template_types',
			array(
				'show_in_rest' => true,
				'type'         => 'string',
				'single'       => true,
			)
		);

		register_post_meta(
			'tbell_pattern_block',
			'wp_pattern_inserter',
			array(
				'show_in_rest' => true,
				'type'         => 'string',
				'single'       => true,
			)
		);

		register_post_meta(
			'tbell_pattern_block',
			'wp_pattern_post_types',
			array(
				'show_in_rest' => true,
				'type'         => 'string',
				'single'       => true,
			)
		);

		/**
		 * Add custom capabilities for the tbell_pattern_block post type.
		 */

		$roles = array( 'administrator', 'editor' );

		$capabilities = array(
			'edit_tbell_pattern_block',
			'read_tbell_pattern_block',
			'delete_tbell_pattern_block',
			'edit_tbell_pattern_blocks',
			'edit_others_tbell_pattern_blocks',
			'publish_tbell_pattern_blocks',
			'read_private_tbell_pattern_blocks',
			'delete_tbell_pattern_blocks',
			'delete_private_tbell_pattern_blocks',
			'delete_published_tbell_pattern_blocks',
			'delete_others_tbell_pattern_blocks',
			'edit_private_tbell_pattern_blocks',
			'edit_published_tbell_pattern_blocks',
		);

		// Assign capabilities to each role
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $capabilities as $capability ) {
					$role->add_cap( $capability );
				}
			}
		}
	}

	/**
	 * Renders a "tbell_pattern_block" block pattern.
	 * This is a block pattern stored as a tbell_pattern_block post type instead of a wp_block post type.
	 * Which means that it is a "theme pattern" instead of a "user pattern".
	 *
	 * This borrows heavily from the core block rendering function.
	 *
	 * @param string $block_content The block content.
	 * @param array  $block        The block data.
	 * @return string
	 */
	public function render_tbell_pattern_blocks( $block_content, $block ) {
		// store a reference to the block to prevent infinite recursion
		static $seen_refs = array();

		// if we have a block pattern with no content we PROBABLY are trying to render
		// a tbell_pattern_block (theme pattern)
		if ( $block['blockName'] === 'core/block' && $block_content === '' ) {

			$attributes = $block['attrs'] ?? array();

			if ( empty( $attributes['ref'] ) ) {
				return '';
			}

			$post = get_post( $attributes['ref'] );
			if ( ! $post || 'tbell_pattern_block' !== $post->post_type ) {
				return '';
			}

			// if we have already seen this block, return an empty string to prevent recursion
			if ( isset( $seen_refs[ $attributes['ref'] ] ) ) {
				return '';
			}

			if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
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
			$has_pattern_overrides = isset( $attributes['content'] ) && null !== get_block_bindings_source( 'core/pattern-overrides' );
			if ( $has_pattern_overrides ) {
				$filter_block_context = static function ( $context ) use ( $attributes ) {
					$context['pattern/overrides'] = $attributes['content'];
					return $context;
				};
				add_filter( 'render_block_context', $filter_block_context, 1 );
			}

			// Apply Block Hooks.
			$content = apply_block_hooks_to_content_from_post_object( $content, $post );

			// Render the block content.
			$content = do_blocks( $content );

			// It is safe to render this block again.  No infinite recursion worries.
			unset( $seen_refs[ $attributes['ref'] ] );

			if ( $has_pattern_overrides ) {
				remove_filter( 'render_block_context', $filter_block_context, 1 );
			}

			return $content;
		}
		return $block_content;
	}
}
