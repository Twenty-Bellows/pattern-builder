<?php

/**
 * Plugin Name:       Pattern Builder
 * Plugin URI: 	      https://github.com/Twenty-Bellows/pattern-builder
 * Description:       Manage Patterns in the block editor.
 * Requires at least: 6.6
 * Requires PHP:      7.2
 * Version:           0.1.0
 * Author:            Twenty Bellows
 * Author URI:        https://twentybellows.com
 * Text Domain:       pattern-builder
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/vendor/autoload.php';

if ( ! function_exists( 'pb_fs' ) && function_exists( 'fs_dynamic_init' ) ) {

    function pb_fs() {

        global $pb_fs;

        if ( ! isset( $pb_fs ) ) {
            // Include Freemius SDK.
            // SDK is auto-loaded through composer
            $pb_fs = fs_dynamic_init( array(
                'id'                  => '19056',
                'slug'                => 'pattern-builder',
                'type'                => 'plugin',
                'public_key'          => 'pk_d2b48b9343ac970336f401830cd52',
                'is_premium'          => true,
                'premium_suffix'      => 'Early Access',
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'menu'                => array(
                    'slug'           => 'pattern-builder',
                    'support'        => false,
                    'parent'         => array(
                        'slug' => 'themes.php',
                    ),
                ),
            ) );
        }

        return $pb_fs;
    }

    // Init Freemius.
    pb_fs();
    // Signal that SDK was initiated.
    do_action( 'pb_fs_loaded' );
}

require_once __DIR__ . '/includes/class-pattern-builder.php';
