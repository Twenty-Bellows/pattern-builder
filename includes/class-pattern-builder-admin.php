<?php

namespace TwentyBellows\PatternBuilder;

class Pattern_Builder_Admin {

	private const PAGE_SLUG  = 'pattern-builder';
	private const PAGE_TITLE = 'Pattern Builder';

	/**
	 * Constructor to initialize admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'create_admin_menu' ) );
	}

	/**
	 * Creates the admin menu for the Pattern Builder.
	 */
	public function create_admin_menu(): void {
		add_theme_page(
			_x( 'Pattern Builder', 'UI String', 'pattern-builder' ),
			_x( 'Pattern Builder', 'UI String', 'pattern-builder' ),
			'edit_theme_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_menu_page' )
		);
	}

	/**
	 * Renders the admin menu page as a plain PHP page (no React build required).
	 */
	public function render_admin_menu_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html_x( 'Pattern Builder', 'UI String', 'pattern-builder' ); ?> <span style="font-size:14px;font-weight:normal;color:#646970;"><?php esc_html_e( 'by Twenty Bellows', 'pattern-builder' ); ?></span></h1>

			<p><?php esc_html_e( 'Pattern Builder adds functionality to the WordPress Editor to enhance the Pattern Building experience. All of the tools are available in the Site Editor and Block Editor — open a pattern there to get started.', 'pattern-builder' ); ?></p>

			<h2><?php esc_html_e( 'Learn More', 'pattern-builder' ); ?></h2>
			<ul>
				<li><a href="https://twentybellows.com/pattern-builder-help#what-are-patterns" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'What are Patterns and how are they different than Custom Blocks?', 'pattern-builder' ); ?></a></li>
				<li><a href="https://twentybellows.com/pattern-builder-help#theme-vs-user-patterns" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'What is the difference between a Theme Pattern and a User Pattern?', 'pattern-builder' ); ?></a></li>
				<li><a href="https://twentybellows.com/pattern-builder-help#synced-vs-unsynced-patterns" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'What is the difference between a Synced Pattern and an Unsynced Pattern?', 'pattern-builder' ); ?></a></li>
				<li><a href="https://twentybellows.com/pattern-builder-help#themes-synced-patterns" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Can Themes have Synced Patterns?', 'pattern-builder' ); ?></a></li>
				<li><a href="https://twentybellows.com/pattern-builder-help#edit-theme-patterns" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit Theme Patterns', 'pattern-builder' ); ?></a></li>
				<li><a href="https://twentybellows.com/pattern-builder-help#include-images-in-patterns" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Include image assets used in patterns in your Theme', 'pattern-builder' ); ?></a></li>
				<li><a href="https://twentybellows.com/pattern-builder-help#localize-patterns" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Prepare Patterns for Localization', 'pattern-builder' ); ?></a></li>
			</ul>
		</div>
		<?php
	}
}
