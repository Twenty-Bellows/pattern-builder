<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Starter_Plugin
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

require 'vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	// Load composer autoloader here, after WordPress core is loaded
	require dirname( __FILE__ ) . '/vendor/autoload.php';

	// Manually ensure Freemius SDK is loaded since it checks for ABSPATH
	if ( ! function_exists( 'fs_dynamic_init' ) ) {
		require dirname( __FILE__ ) . '/vendor/freemius/wordpress-sdk/start.php';
	}

	require dirname( __FILE__ ) . '/pattern-builder.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
