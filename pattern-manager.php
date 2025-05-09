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
