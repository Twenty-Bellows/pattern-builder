<?php

class Pattern_Manager_Admin_Landing {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'create_admin_menu' ) );
	}

	function create_admin_menu() {
		$landing_page_slug       = 'pattern-manager';
		$landing_page_title      = _x( 'Pattern Manager', 'UI String', 'pattern-manager' );
		$landing_page_menu_title = $landing_page_title;
		add_theme_page( $landing_page_title, $landing_page_menu_title, 'edit_theme_options', $landing_page_slug, array( $this, 'admin_menu_page' ) );

	}

	public static function admin_menu_page() {

		$asset_file = include plugin_dir_path( __DIR__ ) . 'build/pattern-manager-admin.asset.php';

		// Load our app.js.
		wp_enqueue_script( 'pattern-manager-app', plugins_url( 'build/pattern-manager-admin.js', __DIR__ ), $asset_file['dependencies'], $asset_file['version'] );

		// Enable localization in the app.
		wp_set_script_translations( 'pattern-manager-app', 'pattern-manager' );

		echo '<div id="pattern-manager-app"></div>';
	}
}

$admin_landing = new Pattern_Manager_Admin_Landing();
