<?php
/**
 * The safe subset of CSS a block style variation may carry.
 *
 * The accept/reject corpus is a JSON fixture shared byte-for-byte with
 * patternbuilderwp.com, where the second copy of `Safe_Css` runs the same
 * cases. That is what keeps the two copies honest: a rule loosened on one
 * side and not the other turns red here or there on the next run.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Safe_Css;

class Test_Safe_Css extends WP_UnitTestCase {

	/**
	 * The shared corpus.
	 *
	 * @return array { accept, reject }
	 */
	private function corpus() {
		$path  = __DIR__ . '/fixtures/safe-css-cases.json';
		$cases = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.

		$this->assertIsArray( $cases, 'The shared Safe_Css corpus did not parse.' );

		return $cases;
	}

	/**
	 * Everything the subset is for, including all twelve CSS strings the
	 * patternbuilderwp.com theme's own variations carry. A rejection here
	 * means the checker is wrong, not the CSS.
	 */
	public function test_the_corpus_accepts_what_a_variation_legitimately_writes() {
		foreach ( $this->corpus()['accept'] as $case ) {
			$result = Safe_Css::check( $case['css'] );

			$this->assertTrue(
				$result,
				sprintf(
					'"%s" should be accepted but was refused: %s',
					$case['name'],
					is_wp_error( $result ) ? $result->get_error_message() : ''
				)
			);
		}
	}

	/**
	 * Everything the subset exists to keep out.
	 */
	public function test_the_corpus_refuses_what_it_is_meant_to() {
		foreach ( $this->corpus()['reject'] as $case ) {
			$result = Safe_Css::check( $case['css'] );

			$this->assertWPError(
				$result,
				sprintf( '"%s" should be refused: %s', $case['name'], $case['because'] )
			);
			$this->assertSame( 'pb_unsafe_css', $result->get_error_code() );
		}
	}

	/**
	 * A refusal has to say enough to be acted on. An agent told "raw CSS is
	 * not accepted" learns nothing; one told which function it reached for,
	 * in which declaration, fixes it and calls again.
	 */
	public function test_a_refusal_names_the_rule_and_the_fragment() {
		$refused = Safe_Css::check( 'background: url(https://evil.test/x.png);' );

		$this->assertWPError( $refused );
		$this->assertStringContainsString( 'url(', $refused->get_error_message() );
		$this->assertStringContainsString( 'https://evil.test/x.png', $refused->get_error_message() );
	}

	/**
	 * The one case that has to keep passing so nobody "fixes" it: a string
	 * spelling out a fetch is a string, and nothing fetches a string.
	 */
	public function test_a_string_that_only_looks_like_a_fetch_is_accepted() {
		$this->assertTrue( Safe_Css::check( 'content: "\\75 rl(x)";' ) );
	}

	/**
	 * What this subset is a subset *of*.
	 *
	 * `WP_Theme_JSON::process_blocks_custom_css()` is a crude parser — it
	 * splits on `&`, strips every `}`, then explodes on `{` and skips any
	 * part that does not come out in two pieces. Everything accepted here has
	 * to be something it reads *correctly*, so that what was validated is
	 * what core emits. Asserting one representative expansion is what makes a
	 * core change that moves the goalposts fail loudly rather than silently.
	 */
	public function test_an_accepted_string_expands_the_way_core_documents() {
		$css = 'position: relative; & > * { z-index: 1; } &::before { content: ""; } & a:hover { color: red; }';

		$this->assertTrue( Safe_Css::check( $css ) );

		$this->assertSame(
			':root :where(.is-style-card){position: relative;}'
				. ':root :where(.is-style-card > *){z-index: 1;}'
				. ':root :where(.is-style-card)::before{content: "";}'
				. ':root :where(.is-style-card a:hover){color: red;}',
			WP_Theme_JSON::process_blocks_custom_css( $css, '.is-style-card' )
		);
	}

	/**
	 * And the two shapes core gets wrong, which is why they are refused.
	 *
	 * Both are scoped rather than dangerous — that is the point. They are out
	 * because accepting them would approve something other than what core
	 * emits, and a checker that disagrees with the thing it is checking for
	 * is how the dangerous cases get in later.
	 */
	public function test_the_shapes_core_mis_parses_are_refused() {
		// A declaration after a nested rule is folded into that rule.
		$folded = '&:hover { color: red; } background: blue;';
		$this->assertWPError( Safe_Css::check( $folded ) );
		$this->assertStringContainsString(
			'background: blue',
			WP_Theme_JSON::process_blocks_custom_css( $folded, '.is-style-card' ),
			'Core still folds a trailing declaration into the rule above it.'
		);

		// A second level of nesting loses the outer rule's body.
		$nested = '& div { & p { color: red; } }';
		$this->assertWPError( Safe_Css::check( $nested ) );
		$this->assertStringContainsString(
			':root :where(.is-style-card div){}',
			WP_Theme_JSON::process_blocks_custom_css( $nested, '.is-style-card' ),
			'Core still drops the outer rule when one rule nests another.'
		);
	}

	/**
	 * The escape a comma buys. `scope_selector()` distributes over one and
	 * `append_to_selector()` does not, so a selector that begins with no
	 * space puts whatever follows the comma at the top level of the emitted
	 * list — styling the destination's whole document, from a variation.
	 */
	public function test_a_comma_in_a_selector_would_escape_the_variation() {
		$escape = '&.a, body { background: red; }';

		$this->assertWPError( Safe_Css::check( $escape ) );
		$this->assertStringContainsString(
			'body',
			WP_Theme_JSON::process_blocks_custom_css( $escape, '.is-style-card' )
		);
		$this->assertStringNotContainsString(
			'.is-style-cardbody',
			WP_Theme_JSON::process_blocks_custom_css( $escape, '.is-style-card' ),
			'The comma is still what lets `body` out rather than being appended to the scope.'
		);
	}

	/**
	 * Nothing accepted can carry `<`, so nothing accepted can end the style
	 * element it is printed into — which is what lets a preview print the
	 * generated CSS through `wp_strip_all_tags()` without it being mangled.
	 */
	public function test_nothing_accepted_survives_into_a_style_element_as_markup() {
		foreach ( $this->corpus()['accept'] as $case ) {
			$expanded = WP_Theme_JSON::process_blocks_custom_css( $case['css'], '.is-style-card' );

			$this->assertStringNotContainsString( '<', $expanded, $case['name'] );
			$this->assertSame( $expanded, wp_strip_all_tags( $expanded ), $case['name'] );
		}
	}

	/**
	 * Text a byte at a time from here on, so it has to *be* text.
	 *
	 * An invalid sequence is not dangerous on its own — a browser reads one as
	 * U+FFFD — but `wp_json_encode()` drops it silently on the way into the
	 * file or the package, so what was checked would not be what got written.
	 */
	public function test_css_that_is_not_valid_utf8_is_refused() {
		$this->assertWPError( Safe_Css::check( "color: \xC3\x28;" ) );
	}

	/**
	 * Not a string at all, which is what a malformed package carries.
	 */
	public function test_a_css_property_that_is_not_a_string_is_refused() {
		$this->assertWPError( Safe_Css::check( array( 'color' => 'red' ) ) );
		$this->assertWPError( Safe_Css::check( null ) );
	}
}
