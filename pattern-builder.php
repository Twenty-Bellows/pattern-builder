<?php

/**
 * Plugin Name:       Pattern Builder
 * Plugin URI:        https://www.twentybellows.com/pattern-builder/
 * Description:       Manage Patterns in the WordPress Editor.
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Version: 1.0.4
 * Author:            Twenty Bellows
 * Author URI:        https://twentybellows.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pattern-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/includes/class-pattern-builder.php';
require_once __DIR__ . '/includes/class-pattern-builder-post-type.php'; // Loaded via class-pattern-builder.php chain; explicit here for IDE clarity.

use TwentyBellows\PatternBuilder\Pattern_Builder;
use TwentyBellows\PatternBuilder\Pattern_Builder_Post_Type;

// Assign role capabilities on activation (not on every init).
register_activation_hook( __FILE__, array( Pattern_Builder_Post_Type::class, 'assign_capabilities' ) );

// Initialize the plugin.
Pattern_Builder::get_instance();
