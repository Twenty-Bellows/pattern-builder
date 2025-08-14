<?php

/**
 * Plugin Name:       Pattern Builder
 * Plugin URI:        https://www.twentybellows.com/pattern-builder/
 * Description:       Manage Patterns in the WordPress Editor.
 * Requires at least: 6.6
 * Requires PHP:      7.2
 * Version:           1.0.1
 * Author:            Twenty Bellows
 * Author URI:        https://twentybellows.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pattern-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/class-pattern-builder.php';

use TwentyBellows\PatternBuilder\Pattern_Builder;

// Initialize the plugin
Pattern_Builder::get_instance();
