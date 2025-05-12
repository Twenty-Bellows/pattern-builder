<?php

class Twenty_Bellows_Pattern_Manager_Admin {

    private const PAGE_SLUG = 'pattern-manager';
    private const PAGE_TITLE = 'Pattern Manager';

    /**
     * Constructor to initialize admin hooks.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'create_admin_menu']);
    }

    /**
     * Creates the admin menu for the Pattern Manager.
     */
    public function create_admin_menu(): void {
        add_theme_page(
            _x(self::PAGE_TITLE, 'UI String', 'pattern-manager'),
            _x(self::PAGE_TITLE, 'UI String', 'pattern-manager'),
            'edit_theme_options',
            self::PAGE_SLUG,
            [$this, 'render_admin_menu_page']
        );
    }

    /**
     * Renders the admin menu page.
     */
    public function render_admin_menu_page(): void {
        $this->enqueue_assets();
        echo '<div id="pattern-manager-app"></div>';
    }

    /**
     * Enqueues the necessary assets for the admin page.
     */
    private function enqueue_assets(): void {
        $asset_file = include plugin_dir_path(__DIR__) . '../build/pattern-manager-admin.asset.php';

        wp_enqueue_script(
            'pattern-manager-app',
            plugins_url('build/pattern-manager-admin.js', __DIR__),
            $asset_file['dependencies'],
            $asset_file['version']
        );

        wp_set_script_translations('pattern-manager-app', 'pattern-manager');
    }
}

