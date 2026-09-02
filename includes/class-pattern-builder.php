<?php

namespace TwentyBellows\PatternBuilder;

require_once __DIR__ . '/class-pattern-builder-security.php';
require_once __DIR__ . '/class-pattern-builder-abstract-pattern.php';
require_once __DIR__ . '/class-pattern-builder-localization.php';
require_once __DIR__ . '/class-pattern-file-store.php';
require_once __DIR__ . '/class-inner-html-processor.php';
require_once __DIR__ . '/class-block-markup.php';
require_once __DIR__ . '/class-pattern-resolver.php';
require_once __DIR__ . '/class-pattern-block.php';
require_once __DIR__ . '/class-synced-patterns.php';
require_once __DIR__ . '/class-editor-support.php';
require_once __DIR__ . '/class-pattern-builder-rest-patterns-controller.php';
require_once __DIR__ . '/class-pattern-builder-entity.php';
require_once __DIR__ . '/class-pattern-builder-api.php';
require_once __DIR__ . '/class-pattern-builder-admin.php';
require_once __DIR__ . '/class-pattern-builder-editor.php';
require_once __DIR__ . '/class-pattern-builder-migration.php';
require_once __DIR__ . '/class-pattern-builder-cloud.php';
require_once __DIR__ . '/class-pattern-builder-cloud-tokens.php';
require_once __DIR__ . '/class-pattern-builder-cloud-porter.php';
require_once __DIR__ . '/class-pattern-builder-cloud-controller.php';
require_once __DIR__ . '/class-pattern-builder-cloud-abilities.php';
require_once __DIR__ . '/class-pattern-builder-telemetry.php';
require_once __DIR__ . '/class-pattern-builder-abilities.php';

/**
 * Main class for managing the Pattern Builder plugin.
 *
 * Always registers the full stack — the pattern runtime (vendored from the
 * companion Synced Patterns for Themes plugin, kept logic-identical) and the
 * editing layer on top. When both plugins are installed, the companion
 * detects Pattern Builder at `plugins_loaded` and stays entirely unloaded;
 * this plugin never has to coordinate.
 */
class Pattern_Builder {

	/**
	 * Singleton instance.
	 *
	 * @var Pattern_Builder|null
	 */
	private static ?Pattern_Builder $instance = null;

	/**
	 * Constructor to initialize the Pattern Builder components.
	 */
	private function __construct() {
		( new Pattern_Block() )->register();
		( new Editor_Support( PATTERN_BUILDER_FILE ) )->register();

		// A theme switch changes which pattern files the synced lookup reads.
		add_action( 'switch_theme', array( Synced_Patterns::class, 'flush' ) );

		new Pattern_Builder_Entity();
		new Pattern_Builder_API();
		new Pattern_Builder_Admin();
		new Pattern_Builder_Editor();
		new Pattern_Builder_Migration();
		Pattern_Builder_Cloud::register();
		new Pattern_Builder_Cloud_Controller();
		new Pattern_Builder_Telemetry();
		new Pattern_Builder_Abilities();
		new Pattern_Builder_Cloud_Abilities();
	}

	/**
	 * Retrieves the single instance of the class.
	 *
	 * @return Pattern_Builder
	 */
	public static function get_instance(): Pattern_Builder {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
