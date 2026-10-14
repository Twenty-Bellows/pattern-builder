<?php
/**
 * Where a pattern is used: patterns, templates and posts that place it, directly or through
 * another pattern.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Abilities;

class Test_Pattern_Usage extends WP_UnitTestCase {
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

		// Every theme pattern here is written into a directory the test owns, never into the
		// suite's own fixture theme.
		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-usage-theme';
		if ( ! is_dir( $this->theme_dir . '/patterns' ) ) {
			mkdir( $this->theme_dir . '/patterns', 0777, true );
		}
		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'template_directory', array( $this, 'theme_dir' ) );
	}

	public function tear_down() {
		remove_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		remove_filter( 'template_directory', array( $this, 'theme_dir' ) );
		foreach ( (array) glob( $this->theme_dir . '/patterns/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
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
	 * Store a theme pattern and answer with its namespaced name.
	 *
	 * @param string $name Bare name.
	 * @param string $content Markup.
	 * @return string
	 */
	private function theme_pattern( $name, $content ) {
		$created = $this->abilities->execute_create_pattern(
			array(
				'title'   => ucfirst( $name ),
				'name'    => $name,
				'content' => $content,
				'source'  => 'theme',
				'synced'  => true,
			)
		);

		return $created['pattern']['name'];
	}

	/**
	 * A reference to a theme pattern.
	 *
	 * @param string $name Namespaced name.
	 * @return string
	 */
	private function placing( $name ) {
		return '<!-- wp:pattern {"slug":"' . $name . '"} /-->';
	}

	/**
	 * The places that use a pattern, keyed by id.
	 *
	 * @param string $id Pattern id.
	 * @return array
	 */
	private function usage( $id ) {
		$answer = $this->abilities->execute_list_pattern_usage( array( 'id' => $id ) );
		$this->assertNotWPError( $answer );

		$by_id = array();
		foreach ( $answer['usedBy'] as $place ) {
			$by_id[ (string) $place['id'] ] = $place;
		}

		return array(
			'places'  => $by_id,
			'total'   => $answer['total'],
			'hasMore' => $answer['hasMore'],
		);
	}

	/**
	 * A section placing a card uses it directly; a page placing the section uses it through
	 * the section, and says so.
	 */
	public function test_patterns_that_place_it_directly_and_indirectly_are_found() {
		$card    = $this->theme_pattern( 'usage-card', '<!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph -->' );
		$section = $this->theme_pattern( 'usage-section', $this->placing( $card ) );
		$page    = $this->theme_pattern( 'usage-page', $this->placing( $section ) );

		$usage = $this->usage( $card );

		$this->assertTrue( $usage['places'][ $section ]['direct'] );
		$this->assertSame( 'pattern', $usage['places'][ $section ]['kind'] );
		$this->assertFalse( $usage['places'][ $page ]['direct'] );
		$this->assertSame( $section, $usage['places'][ $page ]['via'] );
		$this->assertSame( 2, $usage['total'] );
	}

	/**
	 * A user pattern is placed by ref, and a post that places it is found.
	 */
	public function test_a_post_placing_a_user_pattern_by_ref_is_found() {
		$created = $this->abilities->execute_create_pattern(
			array(
				'title'   => 'Usage Banner',
				'content' => '<!-- wp:paragraph --><p>Banner</p><!-- /wp:paragraph -->',
				'source'  => 'user',
				'synced'  => true,
			)
		);
		$ref     = (int) $created['pattern']['id'];
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Draft page',
				'post_content' => '<!-- wp:block {"ref":' . $ref . '} /-->',
			)
		);

		$usage = $this->usage( (string) $ref );

		$this->assertArrayHasKey( (string) $post_id, $usage['places'] );
		$place = $usage['places'][ (string) $post_id ];
		$this->assertSame( 'post', $place['kind'] );
		$this->assertSame( 'page', $place['postType'] );
		$this->assertSame( 'draft', $place['status'] );
		$this->assertTrue( $place['direct'] );
	}

	/**
	 * A post placing the section that places the card changes when the card does.
	 */
	public function test_a_post_placing_a_carrier_is_indirect() {
		$card    = $this->theme_pattern( 'usage-card', '<!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph -->' );
		$section = $this->theme_pattern( 'usage-section', $this->placing( $card ) );
		$post_id = self::factory()->post->create( array( 'post_content' => $this->placing( $section ) ) );

		$usage = $this->usage( $card );

		$this->assertFalse( $usage['places'][ (string) $post_id ]['direct'] );
		$this->assertSame( $section, $usage['places'][ (string) $post_id ]['via'] );
	}

	/**
	 * The name in prose is not a placement: the LIKE finds it, parsing turns it away.
	 */
	public function test_a_name_mentioned_in_prose_is_not_a_use() {
		$card = $this->theme_pattern( 'usage-card', '<!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph -->' );
		self::factory()->post->create( array( 'post_content' => '<!-- wp:paragraph --><p>We wrote ' . $card . ' last week.</p><!-- /wp:paragraph -->' ) );

		$usage = $this->usage( $card );

		$this->assertSame( 0, $usage['total'] );
		$this->assertSame( array(), $usage['places'] );
	}

	/**
	 * A template placing it is listed, the way a site's own header or footer would be.
	 */
	public function test_a_template_placing_it_is_found() {
		$card = $this->theme_pattern( 'usage-card', '<!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph -->' );
		self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'usage-template',
				'post_title'   => 'Usage template',
				'post_status'  => 'publish',
				'post_content' => $this->placing( $card ),
				'tax_input'    => array( 'wp_theme' => array( get_stylesheet() ) ),
			)
		);

		$usage = $this->usage( $card );

		$templates = array_filter(
			$usage['places'],
			static function ( $place ) {
				return 'template' === $place['kind'];
			}
		);
		$this->assertCount( 1, $templates );
		$this->assertSame( 'Usage template', array_values( $templates )[0]['title'] );
	}

	/**
	 * Posts can be every page on a site, so a few are named and the rest counted.
	 */
	public function test_posts_beyond_the_named_ones_are_counted() {
		$card = $this->theme_pattern( 'usage-card', '<!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph -->' );
		self::factory()->post->create_many( 12, array( 'post_content' => $this->placing( $card ) ) );

		$answer = $this->abilities->execute_list_pattern_usage( array( 'id' => $card ) );

		$this->assertCount( 10, $answer['usedBy'] );
		$this->assertSame( 12, $answer['total'] );
		$this->assertTrue( $answer['hasMore'] );
	}

	/**
	 * A pattern nothing places has no uses.
	 */
	public function test_an_unused_pattern_has_no_places() {
		$card = $this->theme_pattern( 'usage-alone', '<!-- wp:paragraph --><p>Alone</p><!-- /wp:paragraph -->' );

		$answer = $this->abilities->execute_list_pattern_usage( array( 'id' => $card ) );

		$this->assertSame( array(), $answer['usedBy'] );
		$this->assertSame( 0, $answer['total'] );
		$this->assertFalse( $answer['hasMore'] );
	}
}
