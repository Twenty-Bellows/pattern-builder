<?php
/**
 * Finding a pattern through list-patterns: search, filters, paging and the keywords in use.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Abilities;

class Test_List_Patterns extends WP_UnitTestCase {
	/**
	 * @var Pattern_Builder_Abilities
	 */
	private $abilities;

	/**
	 * A throwaway theme directory the patterns are written into.
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

		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-listing-theme';
		if ( ! is_dir( $this->theme_dir . '/patterns' ) ) {
			mkdir( $this->theme_dir . '/patterns', 0777, true );
		}
		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'template_directory', array( $this, 'theme_dir' ) );

		$this->store( 'Pricing Two Plans', 'Two plans side by side, each with a price and a button.', array( 'pricing', 'card', 'grid' ), array( 'featured' ) );
		$this->store( 'Hero Split', 'A headline beside a photo, with one button.', array( 'hero', 'split' ), array( 'featured', 'banner' ) );
		$this->store( 'FAQ Accordion', 'Questions that open to show their answers.', array( 'faq', 'accordion' ), array() );
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
	 * Store a theme pattern.
	 *
	 * @param string   $title Title.
	 * @param string   $description Description.
	 * @param string[] $keywords Keywords.
	 * @param string[] $categories Category slugs.
	 */
	private function store( $title, $description, $keywords, $categories ) {
		$this->abilities->execute_create_pattern(
			array(
				'title'       => $title,
				'name'        => sanitize_title( $title ),
				'description' => $description,
				'keywords'    => $keywords,
				'categories'  => $categories,
				'content'     => '<!-- wp:paragraph --><p>' . $title . '</p><!-- /wp:paragraph -->',
				'source'      => 'theme',
			)
		);
	}

	/**
	 * The titles a listing returned.
	 *
	 * @param array $input Ability input.
	 * @return string[]
	 */
	private function titles( $input ) {
		$listed = $this->abilities->execute_list_patterns( array( 'source' => 'theme' ) + $input );

		return wp_list_pluck( $listed['patterns'], 'title' );
	}

	/**
	 * Search finds a word in the title, the description or the keywords, ignoring case.
	 */
	public function test_search_looks_at_title_description_and_keywords() {
		$this->assertSame( array( 'Hero Split' ), $this->titles( array( 'search' => 'HERO' ) ) );
		$this->assertSame( array( 'FAQ Accordion' ), $this->titles( array( 'search' => 'open to show' ) ) );
		$this->assertSame( array( 'Pricing Two Plans' ), $this->titles( array( 'search' => 'grid' ) ) );
		$this->assertSame( array(), $this->titles( array( 'search' => 'testimonial' ) ) );
	}

	/**
	 * A pattern has to carry every category and every keyword asked for.
	 */
	public function test_filters_require_everything_asked_for() {
		$featured = $this->titles( array( 'categories' => array( 'featured' ) ) );
		sort( $featured );
		$this->assertSame( array( 'Hero Split', 'Pricing Two Plans' ), $featured );

		$this->assertSame( array( 'Hero Split' ), $this->titles( array( 'categories' => array( 'featured', 'banner' ) ) ) );
		$this->assertSame( array( 'Pricing Two Plans' ), $this->titles( array( 'keywords' => array( 'Pricing', 'card' ) ) ) );
		$this->assertSame( array(), $this->titles( array( 'keywords' => array( 'pricing', 'hero' ) ) ) );
	}

	/**
	 * Without page or perPage every match comes back, so a caller that never pages sees what
	 * it always saw; with them, total still counts every match.
	 */
	public function test_paging_is_asked_for_and_total_counts_every_match() {
		$all = $this->abilities->execute_list_patterns( array( 'source' => 'theme' ) );
		$this->assertCount( 3, $all['patterns'] );
		$this->assertSame( 3, $all['total'] );
		$this->assertArrayNotHasKey( 'page', $all );

		$second = $this->abilities->execute_list_patterns(
			array(
				'source'  => 'theme',
				'page'    => 2,
				'perPage' => 2,
			)
		);
		$this->assertCount( 1, $second['patterns'] );
		$this->assertSame( 3, $second['total'] );
		$this->assertSame( 2, $second['page'] );
		$this->assertSame( 2, $second['perPage'] );
	}

	/**
	 * The keywords in use are the whole vocabulary, counted before any filter, most used
	 * first.
	 */
	public function test_keywords_in_use_are_counted_across_the_source() {
		$this->store( 'Pricing Three Plans', 'Three plans in a row.', array( 'Pricing', 'grid' ), array() );

		$listed = $this->abilities->execute_list_patterns(
			array(
				'source' => 'theme',
				'search' => 'faq',
			)
		);

		$counts = wp_list_pluck( $listed['keywordsInUse'], 'count', 'keyword' );
		$this->assertSame( 2, $counts['pricing'] );
		$this->assertSame( 2, $counts['grid'] );
		$this->assertSame( 1, $counts['faq'] );
		$this->assertSame( 2, $listed['keywordsInUse'][0]['count'] );
		$this->assertCount( 1, $listed['patterns'] );
	}
}
