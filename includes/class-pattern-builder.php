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

/**
 * Main class for managing the Pattern Builder plugin.
 *
 * Boots on `plugins_loaded` so it can see whether the companion
 * Synced Patterns for Themes plugin is active. The pattern runtime — the
 * `core/pattern` content attribute, its render callback, and the editor-facing
 * pattern composition — is one shared surface: when the companion provides it,
 * Pattern Builder defers and only adds what the companion doesn't have
 * (editing, file writing, metadata management, conversions).
 */
class Pattern_Builder {

	/**
	 * Singleton instance.
	 *
	 * @var Pattern_Builder|null
	 */
	private static ?Pattern_Builder $instance = null;

	/**
	 * Whether this plugin provides the pattern runtime (vs. the companion).
	 *
	 * @var bool
	 */
	private bool $owns_runtime;

	/**
	 * Constructor to initialize the Pattern Builder components.
	 */
	private function __construct() {
		$this->owns_runtime = ! class_exists( '\\TwentyBellows\\SyncedPatternsForThemes\\Pattern_Block' );

		if ( $this->owns_runtime ) {
			( new Pattern_Block() )->register();
			( new Editor_Support( PATTERN_BUILDER_FILE ) )->register();

			// A theme switch changes which pattern files the synced lookup reads.
			add_action( 'switch_theme', array( Synced_Patterns::class, 'flush' ) );
		}

		new Pattern_Builder_Entity();
		new Pattern_Builder_API();
		new Pattern_Builder_Admin();
		new Pattern_Builder_Editor();
		new Pattern_Builder_Migration();
	}

	/**
	 * Whether this plugin registered the shared pattern runtime.
	 *
	 * @return bool
	 */
	public function owns_runtime(): bool {
		return $this->owns_runtime;
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
