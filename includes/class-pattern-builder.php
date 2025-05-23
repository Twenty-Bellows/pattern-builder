<?php

require_once __DIR__ . '/class-pattern-builder-api.php';
require_once __DIR__ . '/class-pattern-builder-admin.php';
require_once __DIR__ . '/class-pattern-builder-editor.php';
require_once __DIR__ . '/class-pattern-builder-post-type.php';

/**
 * Main class for managing the Pattern Builder plugin.
 */
class Pattern_Builder
{
    private static ?Pattern_Builder $instance = null;

    /**
     * Constructor to initialize the Pattern Builder components.
     */
    private function __construct()
    {
        new Pattern_Builder_API();
        new Pattern_Builder_Admin();
        new Pattern_Builder_Editor();
		new Pattern_Builder_Post_Type();
    }

    /**
     * Retrieves the single instance of the class.
     *
     * @return Pattern_Builder
     */
    public static function get_instance(): Pattern_Builder
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}

// Automatically instantiate the class when the file is included.
Pattern_Builder::get_instance();
