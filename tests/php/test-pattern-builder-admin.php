<?php
/**
 * Tests for the Appearance → Pattern Builder screen.
 *
 * @package Pattern_Builder
 */

/**
 * The editor settings the screen serves.
 *
 * @covers \TwentyBellows\PatternBuilder\Pattern_Builder_Admin
 */
class Test_Pattern_Builder_Admin extends WP_UnitTestCase {

	/**
	 * The screen's own block editor context.
	 *
	 * @return WP_Block_Editor_Context The context.
	 */
	private function editor_context(): WP_Block_Editor_Context {
		return new WP_Block_Editor_Context( array( 'name' => 'pattern-builder/editor' ) );
	}

	/**
	 * Runs the settings through every filter the plugin registers.
	 *
	 * @param WP_Block_Editor_Context $context The editor context.
	 * @return array The filtered settings.
	 */
	private function filter_settings( WP_Block_Editor_Context $context ): array {
		return apply_filters( 'block_editor_settings_all', array(), $context );
	}

	/**
	 * Core denies binding edits in this context, which is why the filter exists.
	 *
	 * `edit_block_binding` wants a post in the context or the name
	 * `core/edit-site`. This screen edits file-backed patterns, so it has
	 * neither and core maps the capability to `do_not_allow`.
	 */
	public function test_core_denies_binding_edits_without_a_post() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse(
			current_user_can( 'edit_block_binding', $this->editor_context() ),
			'Core was expected to deny edit_block_binding for a postless context.'
		);
	}

	/**
	 * Someone who can edit the pattern can edit its bindings.
	 */
	public function test_grants_binding_edits_to_theme_editors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = $this->filter_settings( $this->editor_context() );

		$this->assertTrue( $settings['canUpdateBlockBindings'] );
	}

	/**
	 * Someone who cannot edit the pattern cannot edit its bindings.
	 */
	public function test_withholds_binding_edits_from_everyone_else() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$settings = $this->filter_settings( $this->editor_context() );

		$this->assertFalse( $settings['canUpdateBlockBindings'] );
	}

	/**
	 * Every other editor keeps whatever core decided for it.
	 */
	public function test_leaves_other_editor_contexts_alone() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = $this->filter_settings(
			new WP_Block_Editor_Context( array( 'name' => 'core/edit-post' ) )
		);

		$this->assertArrayNotHasKey( 'canUpdateBlockBindings', $settings );
	}

	/**
	 * The rest of the editor's settings survive the filter untouched.
	 */
	public function test_leaves_the_other_settings_alone() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = apply_filters(
			'block_editor_settings_all',
			array( 'styles' => array( 'a style' ) ),
			$this->editor_context()
		);

		$this->assertSame( array( 'a style' ), $settings['styles'] );
	}
}
