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
}
