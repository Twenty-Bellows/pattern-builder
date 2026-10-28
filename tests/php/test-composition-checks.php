<?php
/**
 * Whether markup lays out as written: a flex or grid container whose children cannot size
 * renders as a vertical stack, and that markup is valid, so only this says so.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Abilities;
use TwentyBellows\PatternBuilder\Pattern_Builder_Composition_Checks;

class Test_Composition_Checks extends Pattern_Test_Case {
	/**
	 * A group around a paragraph, with the layout given, or the default when none is.
	 *
	 * @param string $layout The group's layout attribute as JSON, or ''.
	 * @param string $style  The group's style attribute as JSON, or ''.
	 * @return string
	 */
	private function group( $layout = '', $style = '' ) {
		$attrs = array();
		if ( '' !== $layout ) {
			$attrs[] = '"layout":' . $layout;
		}
		if ( '' !== $style ) {
			$attrs[] = '"style":' . $style;
		}
		$json = $attrs ? ' {' . implode( ',', $attrs ) . '}' : '';

		return '<!-- wp:group' . $json . ' --><div class="wp-block-group"><!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
	}

	/**
	 * A container with the layout given, around the children given.
	 *
	 * @param string   $layout   The container's layout attribute as JSON.
	 * @param string[] $children Child markup.
	 * @return string
	 */
	private function container( $layout, $children ) {
		return '<!-- wp:group {"layout":' . $layout . '} --><div class="wp-block-group">' . implode( '', $children ) . '</div><!-- /wp:group -->';
	}

	/**
	 * The codes of the warnings some markup draws.
	 *
	 * @param string $markup Block markup.
	 * @return string[]
	 */
	private function codes( $markup ) {
		return array_column( Pattern_Builder_Composition_Checks::warnings( $markup ), 'code' );
	}

	/**
	 * The failure this exists for: a card pattern with a constrained root fills its whole
	 * track, so a grid of six renders as six full-width cards stacked.
	 */
	public function test_constrained_cards_in_a_grid_stack() {
		$this->register_pattern( 'test/card-constrained', $this->group( '{"type":"constrained"}' ) );
		$card = '<!-- wp:pattern {"slug":"test/card-constrained"} /-->';

		$warnings = Pattern_Builder_Composition_Checks::warnings( $this->container( '{"type":"grid"}', array( $card, $card, $card ) ) );

		$this->assertCount( 1, $warnings );
		$this->assertSame( 'composition_children_fill_track', $warnings[0]['code'] );
		$this->assertStringContainsString( '3 of 3', $warnings[0]['message'] );
		$this->assertStringContainsString( 'test/card-constrained', $warnings[0]['message'] );
	}

	public function test_a_constrained_child_written_inline_is_caught_too() {
		$this->assertSame(
			array( 'composition_children_fill_track' ),
			$this->codes( $this->container( '{"type":"flex"}', array( $this->group( '{"type":"constrained"}' ), $this->group() ) ) )
		);
	}

	/**
	 * A grid that says how many columns, or how wide, sizes its own tracks.
	 */
	public function test_a_grid_that_sets_its_columns_is_sized() {
		$this->register_pattern( 'test/card-constrained', $this->group( '{"type":"constrained"}' ) );
		$card = '<!-- wp:pattern {"slug":"test/card-constrained"} /-->';

		$this->assertSame( array(), $this->codes( $this->container( '{"type":"grid","columnCount":3}', array( $card, $card, $card ) ) ) );
		$this->assertSame( array(), $this->codes( $this->container( '{"type":"grid","minimumColumnWidth":"12rem"}', array( $card, $card, $card ) ) ) );
	}

	public function test_three_unsized_children_in_a_flex_row_will_not_hold_a_grid() {
		$this->assertSame(
			array( 'composition_flex_unsized' ),
			$this->codes( $this->container( '{"type":"flex"}', array( $this->group(), $this->group(), $this->group() ) ) )
		);
	}

	public function test_two_unsized_children_in_a_flex_row_are_fine() {
		$this->assertSame( array(), $this->codes( $this->container( '{"type":"flex"}', array( $this->group(), $this->group() ) ) ) );
	}

	/**
	 * A child that stretches or spans sizes itself, whatever its root lays out as.
	 */
	public function test_children_that_size_themselves_draw_nothing() {
		$stretched = $this->group( '{"type":"constrained"}', '{"layout":{"selfStretch":"fill"}}' );
		$spanning  = $this->group( '{"type":"constrained"}', '{"layout":{"columnSpan":2}}' );

		$this->assertSame( array(), $this->codes( $this->container( '{"type":"flex"}', array( $stretched, $stretched, $stretched ) ) ) );
		$this->assertSame( array(), $this->codes( $this->container( '{"type":"grid"}', array( $spanning, $spanning ) ) ) );
	}

	public function test_a_reference_with_more_than_one_root_is_unknown() {
		$this->register_pattern(
			'test/two-roots',
			'<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->'
		);

		$warnings = Pattern_Builder_Composition_Checks::warnings(
			$this->container( '{"type":"flex"}', array( '<!-- wp:pattern {"slug":"test/two-roots"} /-->' ) )
		);

		$this->assertSame( array( 'composition_reference_unknown' ), array_column( $warnings, 'code' ) );
		$this->assertStringContainsString( 'test/two-roots', $warnings[0]['message'] );
	}

	public function test_a_container_inside_another_is_checked() {
		$row = $this->container( '{"type":"flex"}', array( $this->group(), $this->group(), $this->group() ) );

		$this->assertSame( array( 'composition_flex_unsized' ), $this->codes( $this->container( '{"type":"constrained"}', array( $row ) ) ) );
	}

	/**
	 * A group with the attributes given, as JSON without its braces, around a paragraph.
	 *
	 * @param string $attrs The group's attributes.
	 * @return string
	 */
	private function band( $attrs ) {
		return '<!-- wp:group {' . $attrs . '} --><div class="wp-block-group"><!-- wp:paragraph --><p>Band</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
	}

	/**
	 * The habit this exists for: a full-width band padded at its sides, doing the job of the
	 * theme's two widths and its root padding.
	 */
	public function test_a_full_width_group_padding_its_sides_is_reported() {
		$warnings = Pattern_Builder_Composition_Checks::warnings(
			$this->band( '"align":"full","metadata":{"name":"Intro"},"style":{"spacing":{"padding":{"top":"2rem","right":"var:preset|spacing|50","bottom":"2rem","left":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}' )
		);

		$this->assertSame( array( 'composition_side_padding' ), array_column( $warnings, 'code' ) );
		$this->assertStringContainsString( '"Intro"', $warnings[0]['message'] );
		$this->assertStringContainsString( 'left and right', $warnings[0]['message'] );
	}

	public function test_a_group_with_nothing_painted_padding_one_side_is_reported() {
		$this->assertSame(
			array( 'composition_side_padding' ),
			$this->codes( $this->container( '{"type":"constrained"}', array( $this->band( '"style":{"spacing":{"padding":{"left":"3rem"}}}' ) ) ) )
		);
	}

	/**
	 * Padding inside something painted keeps its text off its own edges, which is not layout;
	 * a style variation may be what paints it.
	 */
	public function test_a_painted_group_may_pad_its_sides() {
		$padded = '"style":{"spacing":{"padding":{"left":"1rem","right":"1rem"}}}';

		$this->assertSame( array(), $this->codes( $this->band( '"backgroundColor":"contrast",' . $padded ) ) );
		$this->assertSame( array(), $this->codes( $this->band( '"className":"is-style-card",' . $padded ) ) );
		$this->assertSame( array(), $this->codes( $this->band( '"style":{"border":{"width":"1px"},"spacing":{"padding":{"left":"1rem","right":"1rem"}}}' ) ) );
	}

	public function test_wide_and_regular_groups_padded_top_and_bottom_draw_nothing() {
		$this->assertSame( array(), $this->codes( $this->band( '"align":"wide","style":{"spacing":{"padding":{"top":"2rem","bottom":"2rem"}}},"layout":{"type":"constrained"}' ) ) );
		$this->assertSame( array(), $this->codes( $this->band( '"style":{"spacing":{"padding":{"top":"2rem","bottom":"2rem"}}},"layout":{"type":"constrained"}' ) ) );
		$this->assertSame( array(), $this->codes( $this->band( '"align":"full","backgroundColor":"contrast","style":{"spacing":{"padding":{"top":"2rem","bottom":"2rem","left":"0","right":"0px"}}}' ) ) );
	}

	/**
	 * The writes report it, so an agent that stores through the site is told without running
	 * anything — and an update that leaves the markup alone is no occasion to repeat it.
	 */
	public function test_the_writes_report_it_only_for_markup_they_wrote() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->register_pattern( 'test/card-constrained', $this->group( '{"type":"constrained"}' ) );
		$card      = '<!-- wp:pattern {"slug":"test/card-constrained"} /-->';
		$abilities = new Pattern_Builder_Abilities();

		$markup  = $this->container( '{"type":"grid"}', array( $card, $card, $card ) );
		$created = $abilities->execute_create_pattern(
			array(
				'title'       => 'Card grid',
				'source'      => 'user',
				'description' => 'Three cards in a grid, used to check that a write reports a container whose children cannot size.',
				'content'     => $markup,
			)
		);
		$this->assertContains( 'composition_children_fill_track', array_column( $created['warnings'] ?? array(), 'code' ) );

		$retitled = $abilities->execute_update_pattern(
			array(
				'id'           => (string) $created['pattern']['id'],
				'expectedHash' => $created['pattern']['hash'],
				'title'        => 'Card grid, renamed',
				'content'      => $markup,
			)
		);
		$this->assertNotWPError( $retitled );
		$this->assertNotContains( 'composition_children_fill_track', array_column( $retitled['warnings'] ?? array(), 'code' ) );

		$edited = $abilities->execute_update_pattern(
			array(
				'id'           => (string) $created['pattern']['id'],
				'expectedHash' => $retitled['pattern']['hash'],
				'edits'        => array(
					array(
						'old' => '{"type":"grid"}',
						'new' => '{"type":"flex"}',
					),
				),
			)
		);
		$this->assertNotWPError( $edited );
		$this->assertContains( 'composition_children_fill_track', array_column( $edited['warnings'] ?? array(), 'code' ) );
	}
}
