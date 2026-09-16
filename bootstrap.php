<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Starter_Plugin
 */

$_tests_dir              = getenv( 'WP_TESTS_DIR' );
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

require 'vendor/autoload.php';
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require __DIR__ . '/vendor/autoload.php';

	require __DIR__ . '/pattern-builder.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
require "{$_tests_dir}/includes/bootstrap.php";
require __DIR__ . '/tests/php/class-pattern-test-case.php';
