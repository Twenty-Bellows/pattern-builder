<?php
/**
 * What a template or template part is refused or warned about before it reaches the theme.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Template_Checks;

class Test_Template_Checks extends Pattern_Test_Case {
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->use_a_template_theme();
	}

	/**
	 * The codes of some warnings.
	 *
	 * @param array[] $warnings Warnings.
	 * @return string[]
	 */
	private function codes( array $warnings ): array {
		return array_column( $warnings, 'code' );
	}

	public function test_a_template_placing_parts_that_exist_passes() {
		$this->assertTrue(
			Pattern_Builder_Template_Checks::check( 'template', 'landing', '<!-- wp:template-part {"slug":"header","tagName":"header"} /--><!-- wp:post-content /-->' )
		);
	}

	/**
	 * An unresolved part renders as nothing, with no error anywhere.
	 */
	public function test_a_part_that_does_not_exist_is_refused() {
		$result = Pattern_Builder_Template_Checks::check( 'template', 'landing', '<!-- wp:template-part {"slug":"footer"} /-->' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'footer part resolves to nothing', $result->get_error_data()['problems'][0] );
	}

	public function test_a_part_with_no_slug_is_refused() {
		$this->assertWPError( Pattern_Builder_Template_Checks::check( 'template', 'landing', '<!-- wp:template-part /-->' ) );
	}

	/**
	 * A reference naming another theme renders only while that theme is active.
	 */
	public function test_a_part_of_another_theme_is_refused_and_one_of_this_theme_is_not() {
		$theirs = Pattern_Builder_Template_Checks::check( 'template', 'landing', '<!-- wp:template-part {"slug":"header","theme":"another-theme"} /-->' );
		$ours   = Pattern_Builder_Template_Checks::check( 'template', 'landing', '<!-- wp:template-part {"slug":"header","theme":"pb-template-test"} /-->' );

		$this->assertWPError( $theirs );
		$this->assertStringContainsString( 'another-theme', $theirs->get_error_message() );
		$this->assertTrue( $ours );
	}

	public function test_a_part_placing_itself_is_refused() {
		$result = Pattern_Builder_Template_Checks::check( 'template-part', 'header', '<!-- wp:template-part {"slug":"header"} /-->' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'places itself', $result->get_error_message() );
	}

	/**
	 * A cycle through another part renders the same error a part placing itself does.
	 */
	public function test_a_part_placing_itself_through_another_is_refused() {
		file_put_contents( get_stylesheet_directory() . '/parts/nav.html', "<!-- wp:template-part {\"slug\":\"header\"} /-->\n" );

		$result = Pattern_Builder_Template_Checks::check( 'template-part', 'header', '<!-- wp:template-part {"slug":"nav"} /-->' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'which already places header', $result->get_error_message() );
	}

	public function test_part_slugs_names_only_this_theme_s_parts() {
		$markup = '<!-- wp:group --><div class="wp-block-group"><!-- wp:template-part {"slug":" header "} /--></div><!-- /wp:group -->'
			. '<!-- wp:template-part {"slug":"footer","theme":"another-theme"} /-->'
			. '<!-- wp:template-part {"slug":"header","theme":"pb-template-test"} /-->'
			. '<!-- wp:template-part /-->';

		$this->assertSame( array( 'header' ), Pattern_Builder_Template_Checks::part_slugs( $markup ) );
	}

	/**
	 * Everything create-pattern refuses is refused here too.
	 */
	public function test_the_pattern_checks_run_as_well() {
		$result = Pattern_Builder_Template_Checks::check( 'template', 'landing', '<!-- wp:pattern {"slug":"nowhere/missing"} /--><!-- wp:no-such/block /-->' );

		$this->assertWPError( $result );
		$this->assertCount( 2, $result->get_error_data()['problems'] );
	}

	public function test_a_navigation_menu_id_is_warned_about() {
		$warnings = Pattern_Builder_Template_Checks::warnings( '<!-- wp:navigation {"ref":42} /-->' );

		$this->assertSame( array( 'template_database_id' ), $this->codes( $warnings ) );
		$this->assertStringContainsString( 'menu 42', $warnings[0]['message'] );
	}

	public function test_a_synced_pattern_id_is_warned_about() {
		$warnings = Pattern_Builder_Template_Checks::warnings( '<!-- wp:group --><div class="wp-block-group"><!-- wp:block {"ref":7} /--></div><!-- /wp:group -->' );

		$this->assertSame( array( 'template_database_id' ), $this->codes( $warnings ) );
	}

	public function test_navigation_without_a_ref_is_fine() {
		$this->assertSame( array(), Pattern_Builder_Template_Checks::warnings( '<!-- wp:navigation /-->' ) );
	}

	/**
	 * A template file has no PHP to reach the theme's assets, so an image in one is this
	 * site's.
	 */
	public function test_images_in_a_template_are_warned_about_once() {
		$markup = '<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://example.org/a.jpg" class="wp-image-5"/></figure><!-- /wp:image -->'
			. '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.org/b.jpg"/></figure><!-- /wp:image -->'
			. '<!-- wp:cover {"url":"https://example.org/c.jpg"} --><div class="wp-block-cover"></div><!-- /wp:cover -->';

		$warnings = Pattern_Builder_Template_Checks::warnings( $markup );

		$this->assertSame( array( 'template_media' ), $this->codes( $warnings ) );
		$this->assertStringContainsString( 'core/image, core/cover', $warnings[0]['message'] );
	}

	public function test_layout_warnings_carry_over() {
		$card   = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$markup = '<!-- wp:group {"layout":{"type":"flex"}} --><div class="wp-block-group">' . $card . $card . '</div><!-- /wp:group -->';

		$this->assertContains( 'composition_children_fill_track', $this->codes( Pattern_Builder_Template_Checks::warnings( $markup ) ) );
	}
}
