<?php

class Pattern_Builder_Editor {

    public function __construct() {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
    }

    /**
     * Enqueues assets for the block editor.
     */
    public function enqueue_block_editor_assets(): void {
        $asset_file = include plugin_dir_path(__FILE__) . '../build/PatternBuilder_EditorTools.asset.php';

        wp_enqueue_script(
            'pattern-builder',
            plugins_url('../build/PatternBuilder_EditorTools.js', __FILE__),
            $asset_file['dependencies'],
            $asset_file['version'],
            false
        );

        wp_enqueue_style(
            'pattern-builder-editor-style',
            plugins_url('../build/PatternBuilder_EditorTools.css', __FILE__),
            [],
            $asset_file['version']
        );

        // Pass synced patterns data to JavaScript
		$synced_patterns =
        $synced_patterns = $this->get_synced_patterns_map();
        wp_localize_script(
            'pattern-builder',
            'patternBuilderData',
            [
                'syncedPatterns' => $synced_patterns
            ]
        );
    }

    /**
     * Get a map of pattern slugs to their synced post IDs.
     *
     * @return array Map of pattern slug => post ID
     */
    private function get_synced_patterns_map(): array {
        $synced_patterns = [];

        // Query for all pb_block posts that have a _pattern_sync_source meta
        $args = [
            'post_type' => 'pb_block',
            'posts_per_page' => -1,
        ];

        $posts = get_posts($args);

        foreach ($posts as $post) {
            $synced_patterns[$post->post_name] = $post->ID;
        }

        return $synced_patterns;
    }
}
