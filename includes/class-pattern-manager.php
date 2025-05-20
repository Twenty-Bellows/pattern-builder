<?php

require_once __DIR__ . '/class-pattern-manager-api.php';
require_once __DIR__ . '/class-pattern-manager-admin.php';
require_once __DIR__ . '/class-pattern-manager-editor.php';
require_once __DIR__ . '/class-pattern-manager-post-type.php';

/**
 * Main class for managing the Pattern Manager plugin.
 */
class Twenty_Bellows_Pattern_Manager
{
    private static ?Twenty_Bellows_Pattern_Manager $instance = null;

    /**
     * Constructor to initialize the Pattern Manager components.
     */
    private function __construct()
    {
        new Twenty_Bellows_Pattern_Manager_API();
        new Twenty_Bellows_Pattern_Manager_Admin();
        new Twenty_Bellows_Pattern_Manager_Editor();
		new Pattern_Manager_Post_Type();
    }

    /**
     * Retrieves the single instance of the class.
     *
     * @return Twenty_Bellows_Pattern_Manager
     */
    public static function get_instance(): Twenty_Bellows_Pattern_Manager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}

// Automatically instantiate the class when the file is included.
Twenty_Bellows_Pattern_Manager::get_instance();
