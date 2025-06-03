<?php

/**
 * Plugin Name:       Pattern Builder
 * Plugin URI: 	      https://www.twentybellows.com/pattern-builder/
 * Description:       Manage Patterns in the WordPress Editor.
 * Requires at least: 6.6
 * Requires PHP:      7.2
 * Version: 0.1.2
 * Author:            Twenty Bellows
 * Author URI:        https://twentybellows.com
 * Text Domain:       pattern-builder
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/class-pattern-builder.php';
