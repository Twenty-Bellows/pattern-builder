<?php
/**
 * Tests for the v1 → v2 migration.
 *
 * @package Pattern_Builder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Migration;

class Pattern_Builder_Migration_Test extends WP_UnitTestCase {

	private $test_dir;

	public function setUp(): void {
		parent::setUp();

		$this->test_dir = sys_get_temp_dir() . '/pattern-builder-migration-test';
		$this->remove_test_directory( $this->test_dir );
		mkdir( $this->test_dir );
		mkdir( $this->test_dir . '/patterns' );

		add_filter( 'stylesheet_directory', array( $this, 'get_test_directory' ) );
	}

	public function tearDown(): void {
		$this->remove_test_directory( $this->test_dir );
		remove_filter( 'stylesheet_directory', array( $this, 'get_test_directory' ) );
		parent::tearDown();
	}

	public function get_test_directory() {
		return $this->test_dir;
	}

	private function remove_test_directory( $dir ) {
		if ( is_dir( $dir ) ) {
			$files = array_diff( scandir( $dir ), array( '.', '..' ) );
			foreach ( $files as $file ) {
				( is_dir( "$dir/$file" ) ) ? $this->remove_test_directory( "$dir/$file" ) : unlink( "$dir/$file" );
			}
			rmdir( $dir );
		}
	}

	/**
	 * Inserts a v1-style mirror post the way version 1 stored them.
	 */
	private function insert_mirror_post( $slug, $title ) {
		return wp_insert_post(
			array(
				'post_title'   => $title,
				// v1 encoded '/' as '-x-x-' to fit post_name.
				'post_name'    => str_replace( '/', '-x-x-', $slug ),
				'post_content' => '<!-- wp:paragraph --><p>Mirror</p><!-- /wp:paragraph -->',
				'post_type'    => 'tbell_pattern_block',
				'post_status'  => 'publish',
			)
		);
	}

	public function test_migration_rewrites_refs_and_deletes_mirrors() {
		$mirror_id = $this->insert_mirror_post( 'simple-theme/hero', 'Hero' );

		// A post referencing the mirror, with overrides that must survive.
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'A page',
				'post_content' => '<!-- wp:block {"ref":' . $mirror_id . ',"content":{"headline":{"content":"Hi"}}} /-->',
				'post_type'    => 'page',
				'post_status'  => 'publish',
			)
		);

		// A post referencing a real wp_block that must NOT be rewritten.
		$wp_block_id  = wp_insert_post(
			array(
				'post_title'   => 'Real synced pattern',
				'post_content' => '<!-- wp:paragraph --><p>Real</p><!-- /wp:paragraph -->',
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			)
		);
		$untouched_id = wp_insert_post(
			array(
				'post_title'   => 'Another page',
				'post_content' => '<!-- wp:block {"ref":' . $wp_block_id . '} /-->',
				'post_type'    => 'page',
				'post_status'  => 'publish',
			)
		);

		// A theme pattern file referencing the mirror.
		file_put_contents(
			$this->test_dir . '/patterns/page-home.php',
			"<?php\n/**\n * Title: Home\n * Slug: simple-theme/page-home\n */\n?>\n<!-- wp:block {\"ref\":{$mirror_id}} /-->\n"
		);

		// The capabilities v1 granted.
		get_role( 'administrator' )->add_cap( 'edit_tbell_pattern_blocks' );
		get_role( 'administrator' )->add_cap( 'delete_tbell_pattern_block' );

		$report = ( new Pattern_Builder_Migration() )->migrate();

		// Post refs rewritten, overrides preserved.
		$content = get_post( $post_id )->post_content;
		$this->assertStringContainsString( 'wp:pattern', $content );
		$this->assertStringContainsString( '"slug":"simple-theme/hero"', $content );
		$this->assertStringContainsString( '"headline":{"content":"Hi"}', $content );
		$this->assertStringNotContainsString( 'wp:block', $content );

		// Real wp_block refs untouched.
		$this->assertStringContainsString( '"ref":' . $wp_block_id, get_post( $untouched_id )->post_content );

		// Theme file rewritten.
		$file = file_get_contents( $this->test_dir . '/patterns/page-home.php' );
		$this->assertStringContainsString( 'wp:pattern {"slug":"simple-theme/hero"}', $file );
		$this->assertStringNotContainsString( 'wp:block', $file );

		// Mirror deleted, caps removed.
		$this->assertNull( get_post( $mirror_id ) );
		$this->assertEquals( 1, $report['deleted_mirrors'] );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'edit_tbell_pattern_blocks' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'delete_tbell_pattern_block' ) );
	}

	public function test_migration_is_idempotent_with_nothing_to_do() {
		$report = ( new Pattern_Builder_Migration() )->migrate();

		$this->assertEquals( 0, $report['deleted_mirrors'] );
		$this->assertSame( array(), $report['rewritten_files'] );
		$this->assertSame( array(), $report['rewritten_posts'] );
	}
}
