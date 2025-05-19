<?php

class Twenty_Bellows_Pattern_Manager_Editor {

    public function __construct() {
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
    }

    /**
     * Enqueues assets for the block editor.
     */
    public function enqueue_block_editor_assets(): void {
        $asset_file = include plugin_dir_path(__FILE__) . '../build/PatternManager_EditorTools.asset.php';

        wp_enqueue_script(
            'pattern-manager',
            plugins_url('../build/PatternManager_EditorTools.js', __FILE__),
            $asset_file['dependencies'],
            $asset_file['version'],
            false
        );

        wp_enqueue_style(
            'pattern-manager-editor-style',
            plugins_url('../build/PatternManager_EditorTools.css', __FILE__),
            [],
            $asset_file['version']
        );
    }
}
