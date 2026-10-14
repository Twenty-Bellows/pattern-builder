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
}
