<?php
/**
 * Changing a pattern that already exists through the abilities: pinning a write to the
 * version its caller read, and what an update reports about itself.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Abilities;

class Test_Abilities_Editing extends WP_UnitTestCase {
	/**
	 * @var Pattern_Builder_Abilities
	 */
	private $abilities;

	/**
	 * A throwaway theme directory, for the tests that write files into one.
	 *
	 * @var string
	 */
	private $theme_dir = '';

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->abilities = new Pattern_Builder_Abilities();
		remove_action( 'wp_abilities_api_categories_init', array( $this->abilities, 'register_category' ) );
		remove_action( 'wp_abilities_api_init', array( $this->abilities, 'register_abilities' ) );
	}

	public function tear_down() {
		if ( '' !== $this->theme_dir ) {
			remove_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
			remove_filter( 'template_directory', array( $this, 'theme_dir' ) );
			foreach ( (array) glob( $this->theme_dir . '/patterns/*' ) as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			$this->theme_dir = '';
		}
		parent::tear_down();
	}

	/**
	 * The throwaway theme directory, as a filter.
	 *
	 * @return string
	 */
	public function theme_dir() {
		return $this->theme_dir;
	}

	/**
	 * Point the active theme at a directory this test may write into, so nothing lands in the
	 * suite's own fixture theme.
	 */
	private function use_a_writable_theme() {
		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-editing-theme';

		if ( ! is_dir( $this->theme_dir . '/patterns' ) ) {
			mkdir( $this->theme_dir . '/patterns', 0777, true );
		}

		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'template_directory', array( $this, 'theme_dir' ) );
	}

	/**
	 * A pattern to change, in either store.
	 *
	 * @param string $source 'theme' or 'user'.
	 * @param string $text The paragraph's words.
	 * @return string The id get-pattern and update-pattern take.
	 */
	private function a_pattern( $source, $text = 'First.' ) {
		if ( 'theme' === $source ) {
			$this->use_a_writable_theme();
		}

		$created = $this->abilities->execute_create_pattern(
			array(
				'title'   => 'Editing ' . $source,
				'name'    => 'editing-' . $source,
				'content' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->',
				'source'  => $source,
			)
		);

		return (string) $created['pattern']['id'];
	}

	/**
	 * The ids both stores are tested with.
	 *
	 * @return array
	 */
	public function sources() {
		return array(
			'theme pattern' => array( 'theme' ),
			'user pattern'  => array( 'user' ),
		);
	}

	/**
	 * The hash a write returns has to be the one the next read returns, or an agent pinning
	 * its next edit to it is refused for a change nobody made.
	 *
	 * @dataProvider sources
	 *
	 * @param string $source Store.
	 */
	public function test_the_hash_a_write_returns_is_the_one_the_next_read_returns( $source ) {
		$this->use_a_writable_theme();
		$created = $this->abilities->execute_create_pattern(
			array(
				'title'   => 'Editing hash',
				'name'    => 'editing-hash',
				'content' => '<!-- wp:paragraph --><p>First.</p><!-- /wp:paragraph -->',
				'source'  => $source,
			)
		);

		$read = $this->abilities->execute_get_pattern( array( 'id' => (string) $created['pattern']['id'] ) );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $read['pattern']['hash'] );
		$this->assertSame( $created['pattern']['hash'], $read['pattern']['hash'] );
	}

	/**
	 * Pinned to the version it read, an update goes through and answers with the new hash.
	 *
	 * @dataProvider sources
	 *
	 * @param string $source Store.
	 */
	public function test_an_update_pinned_to_the_current_version_is_written( $source ) {
		$id   = $this->a_pattern( $source );
		$read = $this->abilities->execute_get_pattern( array( 'id' => $id ) );

		$updated = $this->abilities->execute_update_pattern(
			array(
				'id'           => $id,
				'content'      => '<!-- wp:paragraph --><p>Second.</p><!-- /wp:paragraph -->',
				'expectedHash' => $read['pattern']['hash'],
			)
		);

		$this->assertNotWPError( $updated );
		$this->assertTrue( $updated['changed'] );
		$this->assertNotSame( $read['pattern']['hash'], $updated['pattern']['hash'] );

		$again = $this->abilities->execute_get_pattern( array( 'id' => $id ) );
		$this->assertSame( $updated['pattern']['hash'], $again['pattern']['hash'] );
		$this->assertStringContainsString( 'Second.', $again['pattern']['content'] );
	}

	/**
	 * Somebody else's change between the read and the write is the whole reason for the hash:
	 * the stale write is refused, nothing is written, and the answer says what is there now.
	 *
	 * @dataProvider sources
	 *
	 * @param string $source Store.
	 */
	public function test_an_update_pinned_to_a_stale_version_is_refused( $source ) {
		$id    = $this->a_pattern( $source );
		$stale = $this->abilities->execute_get_pattern( array( 'id' => $id ) );

		$this->abilities->execute_update_pattern(
			array(
				'id'      => $id,
				'content' => '<!-- wp:paragraph --><p>Somebody else.</p><!-- /wp:paragraph -->',
			)
		);
		$current = $this->abilities->execute_get_pattern( array( 'id' => $id ) );

		$result = $this->abilities->execute_update_pattern(
			array(
				'id'           => $id,
				'content'      => '<!-- wp:paragraph --><p>Mine.</p><!-- /wp:paragraph -->',
				'expectedHash' => $stale['pattern']['hash'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_pattern_changed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( $current['pattern']['hash'], $data['currentHash'] );

		$after = $this->abilities->execute_get_pattern( array( 'id' => $id ) );
		$this->assertStringContainsString( 'Somebody else.', $after['pattern']['content'] );
	}

	/**
	 * A change to a header alone moves the hash too, since an update replaces headers as well
	 * as markup.
	 */
	public function test_a_metadata_change_alone_moves_the_hash() {
		$id     = $this->a_pattern( 'theme' );
		$before = $this->abilities->execute_get_pattern( array( 'id' => $id ) );

		$updated = $this->abilities->execute_update_pattern(
			array(
				'id'      => $id,
				'content' => $before['pattern']['content'],
				'title'   => 'Editing renamed',
			)
		);

		$this->assertTrue( $updated['changed'] );
		$this->assertNotSame( $before['pattern']['hash'], $updated['pattern']['hash'] );
	}

	/**
	 * A write that leaves the pattern exactly as it was says so. It is still a success, since
	 * the ability is idempotent, but an agent that meant to change something needs to know it
	 * did not.
	 *
	 * @dataProvider sources
	 *
	 * @param string $source Store.
	 */
	public function test_a_write_that_changes_nothing_says_so( $source ) {
		$id   = $this->a_pattern( $source );
		$read = $this->abilities->execute_get_pattern( array( 'id' => $id ) );

		$updated = $this->abilities->execute_update_pattern(
			array(
				'id'           => $id,
				'content'      => $read['pattern']['content'],
				'expectedHash' => $read['pattern']['hash'],
			)
		);

		$this->assertNotWPError( $updated );
		$this->assertFalse( $updated['changed'] );
		$this->assertSame( $read['pattern']['hash'], $updated['pattern']['hash'] );
	}

	/**
	 * Edit a pattern by fragment, pinned to the version read.
	 *
	 * @param string $id Pattern id.
	 * @param array  $edits The edits.
	 * @return array|WP_Error
	 */
	private function edit( $id, $edits ) {
		$read = $this->abilities->execute_get_pattern( array( 'id' => $id ) );

		return $this->abilities->execute_update_pattern(
			array(
				'id'           => $id,
				'edits'        => $edits,
				'expectedHash' => $read['pattern']['hash'],
			)
		);
	}

	/**
	 * A fragment changes in place and the rest of the pattern is left as it was.
	 *
	 * @dataProvider sources
	 *
	 * @param string $source Store.
	 */
	public function test_an_edit_changes_one_fragment_in_place( $source ) {
		$id = $this->a_pattern( $source, 'Keep this. Change this.' );

		$updated = $this->edit(
			$id,
			array(
				array(
					'old' => 'Change this.',
					'new' => 'Changed.',
				),
			)
		);

		$this->assertNotWPError( $updated );
		$this->assertTrue( $updated['changed'] );
		$after = $this->abilities->execute_get_pattern( array( 'id' => $id ) );
		$this->assertStringContainsString( 'Keep this. Changed.', $after['pattern']['content'] );
	}

	/**
	 * Edits apply in order to one copy, so a later one sees what an earlier one wrote.
	 */
	public function test_edits_apply_in_order() {
		$edited = Pattern_Builder_Abilities::apply_edits(
			'<p>one</p>',
			array(
				array(
					'old' => 'one',
					'new' => 'two',
				),
				array(
					'old' => 'two',
					'new' => 'three',
				),
			)
		);

		$this->assertSame( '<p>three</p>', $edited );
	}

	/**
	 * replaceAll is the one way a fragment may match more than once.
	 */
	public function test_replace_all_changes_every_occurrence() {
		$edited = Pattern_Builder_Abilities::apply_edits(
			'<p>a</p><p>a</p>',
			array(
				array(
					'old'        => 'a',
					'new'        => 'b',
					'replaceAll' => true,
				),
			)
		);

		$this->assertSame( '<p>b</p><p>b</p>', $edited );
	}

	/**
	 * Indentation copied imperfectly out of formatted markup still finds its one match.
	 */
	public function test_a_fragment_matches_despite_different_whitespace() {
		$edited = Pattern_Builder_Abilities::apply_edits(
			"<div>\n\t<p>Hello</p>\n</div>",
			array(
				array(
					'old' => '<div> <p>Hello</p>',
					'new' => '<div><p>Goodbye</p>',
				),
			)
		);

		$this->assertSame( "<div><p>Goodbye</p>\n</div>", $edited );
	}

	/**
	 * A fragment that matches more than once is ambiguous, so nothing is written and the error
	 * says where the matches are.
	 */
	public function test_an_ambiguous_fragment_refuses_every_edit() {
		$result = Pattern_Builder_Abilities::apply_edits(
			"<p>a</p>\n<p>a</p>",
			array(
				array(
					'old' => 'a',
					'new' => 'b',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_edit_refused', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 0, $data['edit'] );
		$this->assertSame( array( 1, 2 ), $data['lines'] );
	}

	/**
	 * One edit that matches nothing stops all of them, including the ones before it.
	 *
	 * @dataProvider sources
	 *
	 * @param string $source Store.
	 */
	public function test_one_failed_edit_writes_nothing( $source ) {
		$id = $this->a_pattern( $source, 'Untouched.' );

		$result = $this->edit(
			$id,
			array(
				array(
					'old' => 'Untouched.',
					'new' => 'Touched.',
				),
				array(
					'old' => 'Not there.',
					'new' => 'Anything.',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 1, $result->get_error_data()['edit'] );
		$after = $this->abilities->execute_get_pattern( array( 'id' => $id ) );
		$this->assertStringContainsString( 'Untouched.', $after['pattern']['content'] );
	}

	/**
	 * Content and edits together are two answers to one question.
	 */
	public function test_content_and_edits_together_are_refused() {
		$id   = $this->a_pattern( 'user' );
		$read = $this->abilities->execute_get_pattern( array( 'id' => $id ) );

		$result = $this->abilities->execute_update_pattern(
			array(
				'id'           => $id,
				'content'      => '<!-- wp:paragraph --><p>Whole.</p><!-- /wp:paragraph -->',
				'edits'        => array(
					array(
						'old' => 'First.',
						'new' => 'Part.',
					),
				),
				'expectedHash' => $read['pattern']['hash'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_content_and_edits', $result->get_error_code() );
	}

	/**
	 * A fragment only means something against the version it was cut from.
	 */
	public function test_edits_without_a_hash_are_refused() {
		$id = $this->a_pattern( 'user' );

		$result = $this->abilities->execute_update_pattern(
			array(
				'id'    => $id,
				'edits' => array(
					array(
						'old' => 'First.',
						'new' => 'Second.',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_edits_need_a_hash', $result->get_error_code() );
	}

	/**
	 * An update with neither content nor edits has nothing to store.
	 */
	public function test_an_update_with_nothing_to_write_is_refused() {
		$id = $this->a_pattern( 'user' );

		$result = $this->abilities->execute_update_pattern( array( 'id' => $id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_nothing_to_write', $result->get_error_code() );
	}

	/**
	 * The edited result is held to the same checks as markup sent whole.
	 */
	public function test_the_edited_result_goes_through_the_markup_checks() {
		$id = $this->a_pattern( 'user' );

		$result = $this->edit(
			$id,
			array(
				array(
					'old' => '<!-- wp:paragraph -->',
					'new' => '<!-- wp:paragraph {"fontSize":"large" -->',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_markup_refused', $result->get_error_code() );
	}

	/**
	 * The warning codes a write's answer carries.
	 *
	 * @param array $answer The answer.
	 * @return string[]
	 */
	private function warning_codes( $answer ) {
		return isset( $answer['warnings'] ) ? wp_list_pluck( $answer['warnings'], 'code' ) : array();
	}

	/**
	 * Create a user pattern with a given description.
	 *
	 * @param string $description Description.
	 * @return array The answer.
	 */
	private function described( $description ) {
		return $this->abilities->execute_create_pattern(
			array(
				'title'       => 'Described',
				'description' => $description,
				'content'     => '<!-- wp:paragraph --><p>First.</p><!-- /wp:paragraph -->',
				'source'      => 'user',
			)
		);
	}

	/**
	 * What a description is missing comes back as a warning, and the pattern is stored anyway.
	 */
	public function test_a_weak_description_is_warned_about_but_stored() {
		$this->assertSame( array( 'description_missing' ), $this->warning_codes( $this->described( '' ) ) );
		$this->assertSame( array( 'description_short' ), $this->warning_codes( $this->described( 'A pricing table.' ) ) );

		$answer = $this->described( 'A modern, clean pricing section for a landing page with two plans side by side and a button under each.' );
		$this->assertSame( array( 'description_says_nothing' ), $this->warning_codes( $answer ) );
		$this->assertStringContainsString( 'modern, clean', $answer['warnings'][0]['message'] );
		$this->assertNotEmpty( $answer['pattern']['id'] );
	}

	/**
	 * A description someone can choose the pattern by earns no warning at all.
	 */
	public function test_a_good_description_earns_no_warning() {
		$answer = $this->described( 'Home page hero with the site header over a full-width photograph, a headline, a short sentence and two buttons.' );

		$this->assertArrayNotHasKey( 'warnings', $answer );
	}

	/**
	 * A description that explains how the pattern is built, in several sentences or one long
	 * one, is told to say which pattern it is instead.
	 */
	public function test_a_description_longer_than_a_sentence_is_warned_about() {
		$answer = $this->described( 'Pricing section for a landing page: two plans side by side, each with a name, a price, what is included and a button. They stack on a narrow screen. Depends on nothing.' );
		$this->assertSame( array( 'description_long' ), $this->warning_codes( $answer ) );
		$this->assertStringContainsString( '3 sentences', $answer['warnings'][0]['message'] );

		$this->assertSame(
			array( 'description_long' ),
			$this->warning_codes( $this->described( 'Pricing section for a landing page with two plans side by side, each with its name, a price, a list of what is included, a note on billing and a button under it.' ) )
		);
	}

	/**
	 * A full stop inside a sentence, or a quoted title that ends one, is still one sentence.
	 */
	public function test_one_sentence_is_counted_as_one() {
		$this->assertSame( array(), $this->warning_codes( $this->described( 'Price list for version 2.5 of the plans page, with three tiers side by side and a button under each.' ) ) );
		$this->assertSame( array(), $this->warning_codes( $this->described( 'Newsletter band for the foot of a page, with a heading, an email field and a button labelled "Sign up."' ) ) );
	}

	/**
	 * An update that moves the markup under an unchanged description is told to check it; the
	 * old description's own shortcomings are not its business.
	 */
	public function test_markup_moving_under_an_unchanged_description_is_noted() {
		$created = $this->described( 'A pricing table.' );
		$id      = (string) $created['pattern']['id'];

		$updated = $this->abilities->execute_update_pattern(
			array(
				'id'      => $id,
				'content' => '<!-- wp:paragraph --><p>Second.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( array( 'description_unchanged' ), $this->warning_codes( $updated ) );
	}

	/**
	 * A description an update sets is judged like one a create sets.
	 */
	public function test_a_description_an_update_sets_is_judged() {
		$created = $this->described( 'Pricing section for a landing page, with two plans side by side, each with a price and a button.' );
		$id      = (string) $created['pattern']['id'];

		$updated = $this->abilities->execute_update_pattern(
			array(
				'id'          => $id,
				'content'     => '<!-- wp:paragraph --><p>First.</p><!-- /wp:paragraph -->',
				'description' => 'Pricing.',
			)
		);

		$this->assertSame( array( 'description_short' ), $this->warning_codes( $updated ) );
	}

	/**
	 * A paragraph that is a Pattern Overrides slot.
	 *
	 * @param string $name Slot name.
	 * @param string $text Placeholder copy.
	 * @return string
	 */
	private function slot( $name, $text ) {
		return '<!-- wp:paragraph {"metadata":{"name":"' . $name . '","bindings":{"__default":{"source":"core/pattern-overrides"}}}} --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * A synced card with a headline slot, and a section that fills it.
	 *
	 * @return array { card: string, section: string }
	 */
	private function a_card_and_a_section_that_fills_it() {
		$this->use_a_writable_theme();

		$card = $this->abilities->execute_create_pattern(
			array(
				'title'   => 'Slot card',
				'name'    => 'slot-card',
				'content' => $this->slot( 'headline', 'A headline' ) . $this->slot( 'body', 'Some words' ),
				'source'  => 'theme',
				'synced'  => true,
			)
		);
		$name = $card['pattern']['name'];

		$section = $this->abilities->execute_create_pattern(
			array(
				'title'   => 'Slot section',
				'name'    => 'slot-section',
				'content' => '<!-- wp:pattern {"slug":"' . $name . '","content":{"headline":{"content":"Filled in"}}} /-->',
				'source'  => 'theme',
			)
		);
		$this->assertNotWPError( $section );

		return array(
			'card'    => $name,
			'section' => $section['pattern']['name'],
		);
	}

	/**
	 * Removing a slot a section fills would send the section back to placeholder copy, so the
	 * update is refused, names the place and the key, and writes nothing.
	 */
	public function test_removing_a_slot_a_reference_fills_is_refused() {
		$names = $this->a_card_and_a_section_that_fills_it();

		$result = $this->abilities->execute_update_pattern(
			array(
				'id'      => $names['card'],
				'content' => $this->slot( 'body', 'Some words' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_slots_still_filled', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( array( 'headline' ), $data['removed'] );
		$this->assertSame( $names['section'], $data['places'][0]['id'] );
		$this->assertSame( array( 'headline' ), $data['places'][0]['keys'] );

		$after = $this->abilities->execute_get_pattern( array( 'id' => $names['card'] ) );
		$this->assertStringContainsString( '"name":"headline"', $after['pattern']['content'] );
	}

	/**
	 * A rename is the usual way this happens, and the refusal says it is a removal plus an
	 * addition.
	 */
	public function test_a_renamed_slot_is_named_as_removed_and_added() {
		$names = $this->a_card_and_a_section_that_fills_it();

		$result = $this->abilities->execute_update_pattern(
			array(
				'id'      => $names['card'],
				'content' => $this->slot( 'title', 'A headline' ) . $this->slot( 'body', 'Some words' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( array( 'title' ), $result->get_error_data()['added'] );
		$this->assertStringContainsString( 'removal plus an addition', $result->get_error_message() );
	}

	/**
	 * Listing the slot in dropSlots is how a caller says it means it; the write goes through
	 * and the answer says what reverts.
	 */
	public function test_drop_slots_lets_the_update_through_and_reports_what_reverts() {
		$names = $this->a_card_and_a_section_that_fills_it();

		$updated = $this->abilities->execute_update_pattern(
			array(
				'id'        => $names['card'],
				'content'   => $this->slot( 'body', 'Some words' ),
				'dropSlots' => array( 'headline' ),
			)
		);

		$this->assertNotWPError( $updated );
		$this->assertSame( $names['section'], $updated['reverted'][0]['id'] );
		$this->assertSame( array( 'headline' ), $updated['reverted'][0]['keys'] );
	}

	/**
	 * A slot nothing fills can go without ceremony.
	 */
	public function test_removing_a_slot_nothing_fills_goes_through() {
		$names = $this->a_card_and_a_section_that_fills_it();

		$updated = $this->abilities->execute_update_pattern(
			array(
				'id'      => $names['card'],
				'content' => $this->slot( 'headline', 'A headline' ),
			)
		);

		$this->assertNotWPError( $updated );
		$this->assertArrayNotHasKey( 'reverted', $updated );
	}

	/**
	 * A page filling the slot counts as much as a pattern does.
	 */
	public function test_a_post_that_fills_the_slot_is_counted() {
		$this->use_a_writable_theme();
		$card    = $this->abilities->execute_create_pattern(
			array(
				'title'   => 'Slot card',
				'name'    => 'slot-card',
				'content' => $this->slot( 'headline', 'A headline' ),
				'source'  => 'theme',
				'synced'  => true,
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Landing',
				'post_content' => '<!-- wp:pattern {"slug":"' . $card['pattern']['name'] . '","content":{"headline":{"content":"Welcome"}}} /-->',
			)
		);

		$result = $this->abilities->execute_update_pattern(
			array(
				'id'      => $card['pattern']['name'],
				'content' => '<!-- wp:paragraph --><p>No slots now.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( $post_id, $result->get_error_data()['places'][0]['id'] );
		$this->assertStringContainsString( 'Landing', $result->get_error_message() );
	}
}
