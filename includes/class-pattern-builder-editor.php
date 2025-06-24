<?php

namespace TwentyBellows\PatternBuilder;

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

    }
}
