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

	// The MCP Adapter, where the environment installed it (wp-env does), so the MCP server
	// is tested against the plugin people actually run.
	$mcp_adapter = WP_PLUGIN_DIR . '/mcp-adapter/mcp-adapter.php';
	if ( is_readable( $mcp_adapter ) ) {
		require $mcp_adapter;

		// The adapter starts once per process, on the first REST request. Inside a test
		// the routes it adds would be dropped with that test's hooks and never come back,
		// so it starts here instead, as it does under WP-CLI.
		add_action(
			'init',
			static function () {
				\WP\MCP\Core\McpAdapter::instance()->init();
			},
			20
		);
	}

	require __DIR__ . '/pattern-builder.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
require "{$_tests_dir}/includes/bootstrap.php";
require __DIR__ . '/tests/php/class-pattern-test-case.php';
