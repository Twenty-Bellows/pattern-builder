<?php
/**
 * The Abilities API registrations: what an agent can read from this site and
 * what it can ask this site to store.
 *
 * These run only where core has the Abilities API. The plugin's floor is
 * older than the API, so the whole surface is conditional and a site without
 * it must simply carry on — which is the first thing asserted here.
 *
 * @package PatternBuilder
 */

use TwentyBellows\PatternBuilder\Pattern_Builder_Abilities;

class Test_Abilities extends WP_UnitTestCase {

	/**
	 * @var Pattern_Builder_Abilities
	 */
	private $abilities;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		/*
		 * The plugin already registered everything on core's hooks during
		 * bootstrap. This instance exists only to call the execute_* methods
		 * directly, so unhook it immediately — leaving it hooked would
		 * register every ability a second time on the next init.
		 */
		$this->abilities = new Pattern_Builder_Abilities();
		remove_action( 'wp_abilities_api_categories_init', array( $this->abilities, 'register_category' ) );
		remove_action( 'wp_abilities_api_init', array( $this->abilities, 'register_abilities' ) );
	}

	/**
	 * The guard is the whole compatibility story: on a site without the API
	 * the constructor must do nothing rather than fatal.
	 */
	public function test_constructing_is_safe_without_the_abilities_api() {
		$instance = new Pattern_Builder_Abilities();

		$this->assertInstanceOf( Pattern_Builder_Abilities::class, $instance );
	}

	/**
	 * Skip the rest where core has no Abilities API.
	 */
	private function require_abilities_api() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'This WordPress has no Abilities API.' );
		}
	}

	/**
	 * Registration happens on core's hooks during the plugin's own boot —
	 * core refuses a `wp_register_ability()` called anywhere else — so this
	 * asserts the real path rather than re-running it.
	 */
	public function test_every_ability_registers() {
		$this->require_abilities_api();

		$expected = array(
			'pattern-builder/get-design-system',
			'pattern-builder/list-block-types',
			'pattern-builder/list-patterns',
			'pattern-builder/get-pattern',
			'pattern-builder/render-pattern',
			'pattern-builder/get-authoring-guide',
			'pattern-builder/get-validator',
			'pattern-builder/get-editor-scripts',
			'pattern-builder/create-pattern',
			'pattern-builder/update-pattern',
		);

		foreach ( $expected as $name ) {
			$this->assertTrue( wp_has_ability( $name ), $name . ' did not register.' );
		}
	}

	/**
	 * Annotations are not documentation: core reads them to decide which HTTP
	 * method a call must arrive on. `readonly` is GET, `destructive` *and*
	 * `idempotent` together mean DELETE, and everything else is POST. An
	 * update marked destructive would therefore be reachable only over
	 * DELETE, which is why it is not marked so.
	 */
	public function test_annotations_map_to_the_methods_we_intend() {
		$this->require_abilities_api();

		foreach ( array( 'get-design-system', 'list-block-types', 'list-patterns', 'get-pattern', 'render-pattern', 'get-authoring-guide', 'get-validator', 'get-editor-scripts' ) as $read ) {
			$meta = wp_get_ability( 'pattern-builder/' . $read )->get_meta();
			$this->assertTrue( $meta['annotations']['readonly'], $read . ' should be readonly (GET).' );
			$this->assertTrue( $meta['show_in_rest'], $read . ' must be reachable over REST.' );
		}

		foreach ( array( 'create-pattern', 'update-pattern' ) as $write ) {
			$meta = wp_get_ability( 'pattern-builder/' . $write )->get_meta();
			$this->assertFalse( $meta['annotations']['readonly'], $write . ' is not a read.' );
			$this->assertFalse(
				$meta['annotations']['destructive'],
				$write . ' must not be destructive, or core will only accept it over DELETE.'
			);
		}
	}

	public function test_design_system_reports_this_site_s_tokens() {
		$result = $this->abilities->execute_design_system();

		$this->assertArrayHasKey( 'palette', $result );
		$this->assertArrayHasKey( 'spacing', $result );
		$this->assertArrayHasKey( 'fontSizes', $result );
		$this->assertArrayHasKey( 'layout', $result );

		// Core's own presets are always there, so a palette is never empty.
		$this->assertNotEmpty( $result['palette'] );
		$slugs = wp_list_pluck( $result['palette'], 'slug' );
		$this->assertContains( 'black', $slugs );
	}

	/**
	 * Presets arrive keyed by origin and a later origin wins by slug, which
	 * is what the editor shows — so a slug must appear once, not once per
	 * origin that defines it.
	 */
	public function test_a_preset_slug_is_not_repeated_across_origins() {
		$result = $this->abilities->execute_design_system();
		$slugs  = wp_list_pluck( $result['palette'], 'slug' );

		$this->assertSame( count( $slugs ), count( array_unique( $slugs ) ) );
	}

	public function test_block_types_are_the_ones_registered_here() {
		$result = $this->abilities->execute_block_types( array( 'namespace' => 'core' ) );

		$this->assertNotEmpty( $result['blocks'] );

		$names = wp_list_pluck( $result['blocks'], 'name' );
		$this->assertContains( 'core/paragraph', $names );

		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'core/', $name, 'The namespace filter let something else through.' );
		}
	}

	public function test_a_user_pattern_round_trips() {
		$created = $this->abilities->execute_create_pattern(
			array(
				'title'   => 'Abilities User Pattern',
				'content' => '<!-- wp:paragraph --><p>From an agent.</p><!-- /wp:paragraph -->',
				'source'  => 'user',
			)
		);

		$this->assertArrayHasKey( 'pattern', $created );
		$id = $created['pattern']['id'];

		$fetched = $this->abilities->execute_get_pattern( array( 'id' => (string) $id ) );
		$this->assertSame( 'Abilities User Pattern', $fetched['pattern']['title'] );
		$this->assertStringContainsString( 'From an agent.', $fetched['pattern']['content'] );

		$this->abilities->execute_update_pattern(
			array(
				'id'      => (string) $id,
				'content' => '<!-- wp:paragraph --><p>Replaced.</p><!-- /wp:paragraph -->',
			)
		);

		$again = $this->abilities->execute_get_pattern( array( 'id' => (string) $id ) );
		$this->assertStringContainsString( 'Replaced.', $again['pattern']['content'] );
		$this->assertStringNotContainsString( 'From an agent.', $again['pattern']['content'] );
	}

	/**
	 * A listing is a catalogue, not a payload: an agent choosing between
	 * patterns should not have to receive every one's markup to do it.
	 */
	public function test_listing_omits_markup() {
		$this->abilities->execute_create_pattern(
			array(
				'title'   => 'Listed Pattern',
				'content' => '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->',
				'source'  => 'user',
			)
		);

		$listed = $this->abilities->execute_list_patterns( array( 'source' => 'user' ) );

		$this->assertNotEmpty( $listed['patterns'] );
		foreach ( $listed['patterns'] as $pattern ) {
			$this->assertArrayNotHasKey( 'content', $pattern );
		}
	}

	public function test_rendering_resolves_blocks() {
		$created = $this->abilities->execute_create_pattern(
			array(
				'title'   => 'Rendered Pattern',
				'content' => '<!-- wp:paragraph --><p>Rendered body.</p><!-- /wp:paragraph -->',
				'source'  => 'user',
			)
		);

		$rendered = $this->abilities->execute_render_pattern( array( 'id' => (string) $created['pattern']['id'] ) );

		$this->assertStringContainsString( 'Rendered body.', $rendered['html'] );
		$this->assertStringNotContainsString( '<!-- wp:paragraph -->', $rendered['html'] );
	}

	/**
	 * The abilities hand over what is true about this site and somewhere to
	 * put a result; this one hands over the knowledge, so that an agent whose
	 * harness has no notion of a "skill" can still be told how to do the job.
	 */
	public function test_the_authoring_guide_indexes_itself() {
		$index = $this->abilities->execute_authoring_guide();

		$this->assertArrayHasKey( 'guides', $index );
		$names = wp_list_pluck( $index['guides'], 'name' );

		foreach ( array( 'authoring', 'pattern-kinds', 'block-vocabulary', 'block-markup', 'design-content-split' ) as $expected ) {
			$this->assertContains( $expected, $names, $expected . ' is missing from the index.' );
		}

		foreach ( $index['guides'] as $guide ) {
			$this->assertNotEmpty( $guide['title'], $guide['name'] . ' has no title.' );
			$this->assertGreaterThan( 100, $guide['words'], $guide['name'] . ' looks empty.' );
		}
	}

	public function test_a_guide_comes_back_as_markdown() {
		$guide = $this->abilities->execute_authoring_guide( array( 'guide' => 'pattern-kinds' ) );

		$this->assertSame( 'markdown', $guide['format'] );
		$this->assertStringContainsString( '# Kinds of pattern', $guide['content'] );
		$this->assertStringContainsString( 'Synced Design Pattern', $guide['content'] );
	}

	/**
	 * The main guide doubles as a Claude skill, so it carries YAML front
	 * matter that means nothing to any other caller.
	 */
	public function test_front_matter_is_stripped() {
		$guide = $this->abilities->execute_authoring_guide( array( 'guide' => 'authoring' ) );

		$this->assertStringStartsNotWith( '---', $guide['content'] );
		$this->assertStringNotContainsString( 'name: pattern-author', $guide['content'] );
		$this->assertStringContainsString( 'save()', $guide['content'] );
	}

	public function test_every_guide_concatenates() {
		$all = $this->abilities->execute_authoring_guide( array( 'guide' => 'all' ) );

		$this->assertSame( 'all', $all['name'] );
		$this->assertStringContainsString( 'guide: pattern-kinds', $all['content'] );
		$this->assertStringContainsString( 'guide: block-markup', $all['content'] );
	}

	public function test_an_unknown_guide_names_the_ones_that_exist() {
		$result = $this->abilities->execute_authoring_guide( array( 'guide' => 'nonsense' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_guide_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'pattern-kinds', $result->get_error_message() );
	}

	/**
	 * The shipped guides describe WordPress, not your project. What an agent
	 * most needs on top of them is the house rule — which blocks this build
	 * settled on, why a section is composed the way it is — that a theme knows
	 * and the plugin cannot. So the set is filtered, and both amending a
	 * shipped guide and adding one have to reach whoever asks, by every route
	 * the ability offers.
	 */
	public function test_a_theme_can_amend_and_add_guides() {
		add_filter(
			'pattern_builder_authoring_guides',
			function ( $guides ) {
				$guides['block-vocabulary']['content'] .= "\n\nOn this site, core blocks only.";
				$guides['house-rules']                  = array(
					'title'   => 'House rules',
					'content' => "# House rules\n\nSections are full width.",
				);
				return $guides;
			}
		);

		$amended = $this->abilities->execute_authoring_guide( array( 'guide' => 'block-vocabulary' ) );
		$this->assertStringContainsString( 'core blocks only', $amended['content'] );

		$added = $this->abilities->execute_authoring_guide( array( 'guide' => 'house-rules' ) );
		$this->assertStringContainsString( 'Sections are full width.', $added['content'] );

		// An agent that reads only the index still has to find it.
		$index = $this->abilities->execute_authoring_guide();
		$this->assertContains( 'house-rules', wp_list_pluck( $index['guides'], 'name' ) );

		// And "all" is what an agent installs wholesale.
		$all = $this->abilities->execute_authoring_guide( array( 'guide' => 'all' ) );
		$this->assertStringContainsString( 'Sections are full width.', $all['content'] );
		$this->assertStringContainsString( 'core blocks only', $all['content'] );
	}

	/**
	 * Titles are for the index, so a guide that arrives without one should
	 * still read as something in a list rather than as its slug.
	 */
	public function test_a_guide_added_without_a_title_takes_one_from_its_heading() {
		add_filter(
			'pattern_builder_authoring_guides',
			function ( $guides ) {
				$guides['untitled'] = array( 'content' => "# Copy voice\n\nPlain sentences." );
				return $guides;
			}
		);

		$index  = $this->abilities->execute_authoring_guide();
		$titles = array_column( $index['guides'], 'title', 'name' );

		$this->assertSame( 'Copy voice', $titles['untitled'] );
	}

	/**
	 * A filter is somebody else's code. One that returns nonsense should cost
	 * the nonsense, not the ability.
	 */
	public function test_a_broken_filter_costs_that_guide_not_the_ability() {
		add_filter(
			'pattern_builder_authoring_guides',
			function ( $guides ) {
				$guides['no-content']   = array( 'title' => 'Nothing here' );
				$guides['not-an-array'] = 'just a string';
				$guides['']             = array( 'content' => 'nameless' );
				return $guides;
			}
		);

		$names = wp_list_pluck( $this->abilities->execute_authoring_guide()['guides'], 'name' );

		$this->assertNotContains( 'no-content', $names );
		$this->assertNotContains( 'not-an-array', $names );
		$this->assertContains( 'authoring', $names, 'The shipped guides should survive a bad neighbour.' );
	}

	/**
	 * And one that returns no array at all leaves an empty shelf rather than
	 * a fatal.
	 */
	public function test_a_filter_that_returns_nothing_does_not_fatal() {
		add_filter( 'pattern_builder_authoring_guides', '__return_null' );

		$this->assertSame( array(), $this->abilities->execute_authoring_guide()['guides'] );
		$this->assertWPError( $this->abilities->execute_authoring_guide( array( 'guide' => 'authoring' ) ) );
	}

	/**
	 * The guides tell an agent to validate before storing anything, and for an
	 * agent that arrived over HTTP that instruction is unfollowable unless the
	 * tool travels too. No server can run the check itself — `save()` is
	 * JavaScript — but it can hand over the thing that can.
	 */
	public function test_the_validator_travels() {
		$result = $this->abilities->execute_validator();

		$this->assertSame( 'validate-pattern.mjs', $result['entry'] );

		$names = wp_list_pluck( $result['files'], 'name' );
		$this->assertContains( 'validate-pattern.mjs', $names );
		$this->assertContains( 'wp-core.mjs', $names );

		foreach ( $result['files'] as $file ) {
			$this->assertGreaterThan( 1000, strlen( $file['contents'] ), $file['name'] . ' looks empty.' );
		}

		// It is the entry point that has to be runnable, and the other file is
		// what it imports.
		$by_name = array_column( $result['files'], 'contents', 'name' );
		$this->assertStringContainsString( 'wp-core.mjs', $by_name['validate-pattern.mjs'] );
		$this->assertStringContainsString( 'jsdom', $result['usage'] );
	}

	/**
	 * WordPress serves its editor scripts to anyone, but not the order they
	 * load in: the manifest core generates is a PHP file, so a request for it
	 * executes and returns nothing. Only the site can answer this.
	 */
	public function test_editor_scripts_come_back_as_ordered_urls() {
		$result = $this->abilities->execute_editor_scripts();

		$this->assertNotEmpty( $result['scripts'] );
		$this->assertSame( home_url(), $result['site'] );

		foreach ( $result['scripts'] as $url ) {
			$this->assertStringStartsWith( 'http', $url, 'Every entry must be fetchable as-is.' );
		}

		$joined = implode( ' ', $result['scripts'] );
		$this->assertStringContainsString( 'blocks.min.js', $joined );
		$this->assertStringContainsString( 'block-library.min.js', $joined );

		/*
		 * Order is the whole point of asking. The JSX runtime reads
		 * `globalThis.React` as it loads, so React has to be there first —
		 * and when it is not, every JSX call in the editor bundles fails
		 * with nothing but a missing function to show for it.
		 */
		$react = $this->position_of( $result['scripts'], 'vendor/react.min.js' );
		$jsx   = $this->position_of( $result['scripts'], 'react-jsx-runtime' );
		$this->assertNotNull( $react );
		$this->assertNotNull( $jsx );
		$this->assertLessThan( $jsx, $react, 'React must load before the JSX runtime.' );

		// And the library everything else supports comes last.
		$blocks  = $this->position_of( $result['scripts'], 'dist/blocks.min.js' );
		$library = $this->position_of( $result['scripts'], 'block-library.min.js' );
		$this->assertLessThan( $library, $blocks );
	}

	/**
	 * Where a fragment first appears in a list of URLs.
	 *
	 * @param array  $urls     URLs.
	 * @param string $fragment Substring to find.
	 * @return int|null
	 */
	private function position_of( $urls, $fragment ) {
		foreach ( $urls as $index => $url ) {
			if ( false !== strpos( $url, $fragment ) ) {
				return $index;
			}
		}
		return null;
	}

	public function test_an_unknown_pattern_is_an_error_not_a_fatal() {
		$result = $this->abilities->execute_get_pattern( array( 'id' => 'nothing/here' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_pattern_not_found', $result->get_error_code() );
	}

	public function test_reads_and_writes_ask_for_different_authority() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		// An author may edit posts, so may read patterns.
		$this->assertTrue( $this->abilities->can_read() );

		// Writing a theme pattern writes a file into the theme.
		$this->assertFalse( $this->abilities->can_write() );
	}

	public function test_a_subscriber_can_do_neither() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( $this->abilities->can_read() );
		$this->assertFalse( $this->abilities->can_write() );
	}
}
