<?php

class Twenty_Bellows_Pattern_Manager_Editor
{

	public function __construct()
	{
		add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
	}

	function enqueue_block_editor_assets()
	{

		$asset_file = include plugin_dir_path(__FILE__) . '../build/pattern-manager-editor-tools.asset.php';

		wp_enqueue_script(
			'pattern-manager',
			plugins_url('../build/pattern-manager-editor-tools.js', __FILE__),
			$asset_file['dependencies'],
			$asset_file['version'],
			false
		);

		wp_enqueue_style(
			'pattern-manager-editor-style',
			plugins_url('../build/pattern-manager-editor-tools.css', __FILE__),
			array(),
			$asset_file['version']
		);
	}
}
