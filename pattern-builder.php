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
	exit; // Exit if accessed directly.
}

define( 'PATTERN_BUILDER_VERSION', '2.0.0' );
define( 'PATTERN_BUILDER_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-pattern-builder.php';

/*
 * Boot on plugins_loaded: plugin directories load alphabetically, so at this
 * file's include time the companion Synced Patterns for Themes plugin — which
 * provides the same core/pattern runtime — has not loaded yet. By
 * plugins_loaded every plugin has, and Pattern_Builder can decide whether to
 * provide the runtime itself or defer to the companion.
 */
add_action( 'plugins_loaded', array( 'TwentyBellows\PatternBuilder\Pattern_Builder', 'get_instance' ) );

/*
 * Usage telemetry is opt-in (see Pattern_Builder_Telemetry): these send
 * nothing unless an administrator already said yes on this site.
 */
register_activation_hook( __FILE__, array( 'TwentyBellows\PatternBuilder\Pattern_Builder_Telemetry', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'TwentyBellows\PatternBuilder\Pattern_Builder_Telemetry', 'on_deactivation' ) );
