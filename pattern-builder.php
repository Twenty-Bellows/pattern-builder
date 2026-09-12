<?php

/**
 * Plugin Name:       Pattern Builder
 * Plugin URI:        https://www.twentybellows.com/pattern-builder/
 * Description:       Manage Patterns in the WordPress Editor.
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Version: 2.0.0
 * Author:            Twenty Bellows
 * Author URI:        https://twentybellows.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pattern-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PATTERN_BUILDER_VERSION', '2.0.0' );
define( 'PATTERN_BUILDER_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-pattern-builder.php';
add_action( 'plugins_loaded', array( 'TwentyBellows\PatternBuilder\Pattern_Builder', 'get_instance' ) );
register_activation_hook( __FILE__, array( 'TwentyBellows\PatternBuilder\Pattern_Builder_Telemetry', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'TwentyBellows\PatternBuilder\Pattern_Builder_Telemetry', 'on_deactivation' ) );
