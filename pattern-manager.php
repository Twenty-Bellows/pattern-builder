<?php

/**
 * Plugin Name:       Pattern Manager
 * Plugin URI: 	      https://github.com/Twenty-Bellows/pattern-manager
 * Description:       Manage Patterns in the block editor.
 * Requires at least: 6.6
 * Requires PHP:      7.2
 * Version:           1.0.0
 * Author:            Twenty Bellows
 * Author URI:        https://twentybellows.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pattern-manager
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/includes/class-pattern-manager.php';
require_once __DIR__ . '/includes/class-pattern-manager-admin.php';

add_action('enqueue_block_editor_assets', function () {

	$asset_file = include plugin_dir_path(__FILE__) . 'build/pattern-manager-editor-tools.asset.php';
	wp_enqueue_script(
		'pattern-manager',
		plugins_url('build/pattern-manager-editor-tools.js', __FILE__),
		$asset_file['dependencies'],
		$asset_file['version'],
		false
	);
	wp_enqueue_style(
		'pattern-manager-editor-style',
		plugins_url('build/pattern-manager-editor-tools.css', __FILE__),
		array(),
		$asset_file['version']
	);
});
