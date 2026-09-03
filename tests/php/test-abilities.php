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

	/**
	 * A theme.json this class wrote, to be removed again.
	 *
	 * @var string
	 */
	private $theme_json = '';

	/**
	 * A throwaway theme directory, for the tests that write files into one.
	 *
	 * @var string
	 */
	private $theme_dir = '';

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

	public function tear_down() {
		if ( '' !== $this->theme_json && file_exists( $this->theme_json ) ) {
			unlink( $this->theme_json );
			$this->theme_json = '';
		}
		if ( '' !== $this->theme_dir ) {
			remove_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
			remove_filter( 'template_directory', array( $this, 'theme_dir' ) );
			foreach ( (array) glob( $this->theme_dir . '/assets/images/*' ) as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			$this->theme_dir = '';
		}
		wp_clean_theme_json_cache();
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
	 * Point the active theme at a directory this test may write into. The
	 * storage mechanics are covered in Test_Assets; here it only has to be
	 * somewhere the write can land.
	 */
	private function use_a_writable_theme() {
		$this->theme_dir = sys_get_temp_dir() . '/pattern-builder-abilities-theme';

		if ( ! is_dir( $this->theme_dir . '/assets/images' ) ) {
			mkdir( $this->theme_dir . '/assets/images', 0777, true );
		}

		add_filter( 'stylesheet_directory', array( $this, 'theme_dir' ) );
		add_filter( 'template_directory', array( $this, 'theme_dir' ) );
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
			'pattern-builder/add-design-tokens',
			// Media and fonts: what a pattern points at.
			'pattern-builder/find-media',
			'pattern-builder/add-asset',
			'pattern-builder/add-placeholder-image',
			'pattern-builder/list-fonts',
			'pattern-builder/add-font',
			// The cloud, through this site's connection.
			'pattern-builder/list-collections',
			'pattern-builder/get-collection',
			'pattern-builder/search-cloud-patterns',
			'pattern-builder/install-collection',
			'pattern-builder/install-cloud-pattern',
			'pattern-builder/upload-pattern',
			'pattern-builder/create-collection',
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

		foreach ( array( 'get-design-system', 'list-block-types', 'list-patterns', 'get-pattern', 'render-pattern', 'get-authoring-guide', 'get-validator', 'get-editor-scripts', 'find-media', 'list-fonts' ) as $read ) {
			$meta = wp_get_ability( 'pattern-builder/' . $read )->get_meta();
			$this->assertTrue( $meta['annotations']['readonly'], $read . ' should be readonly (GET).' );
			$this->assertTrue( $meta['show_in_rest'], $read . ' must be reachable over REST.' );
		}

		foreach ( array( 'create-pattern', 'update-pattern', 'add-design-tokens', 'add-asset', 'add-placeholder-image', 'add-font' ) as $write ) {
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

		foreach ( array( 'authoring', 'pattern-kinds', 'block-vocabulary', 'block-markup', 'design-content-split', 'assets', 'keeping-current', 'abilities' ) as $expected ) {
			$this->assertContains( $expected, $names, $expected . ' is missing from the index.' );
		}

		foreach ( $index['guides'] as $guide ) {
			$this->assertNotEmpty( $guide['title'], $guide['name'] . ' has no title.' );
			$this->assertGreaterThan( 100, $guide['words'], $guide['name'] . ' looks empty.' );
		}
	}

	/**
	 * A guide the index cannot serve is worse than one that does not exist:
	 * the skill tells the reader to go and read it, and over the wire there
	 * is nothing there. So every reference the index document makes to
	 * another guide has to name one this ability will actually hand over.
	 *
	 * This is not hypothetical — `keeping-current.md` shipped in the
	 * directory, was named in the References list, and was never registered.
	 */
	public function test_every_guide_the_index_points_at_can_be_served() {
		$authoring = $this->abilities->execute_authoring_guide( array( 'guide' => 'authoring' ) );
		$names     = wp_list_pluck( $this->abilities->execute_authoring_guide()['guides'], 'name' );

		$this->assertGreaterThan(
			0,
			preg_match_all( '#references/([a-z-]+)\.md#', $authoring['content'], $matches ),
			'The index names no other guides, which cannot be right.'
		);

		foreach ( array_unique( $matches[1] ) as $referenced ) {
			$this->assertContains(
				$referenced,
				$names,
				'The authoring guide points at references/' . $referenced . '.md, which get-authoring-guide cannot serve. Add it to guide_files().'
			);
		}
	}

	/**
	 * An agent that goes straight to create-pattern reads no guide at all, so
	 * the one step it cannot afford to skip has to be on the index — and the
	 * abilities it names have to be ones that exist, or it is worse than
	 * saying nothing.
	 */
	public function test_the_index_says_to_validate_and_points_at_the_means() {
		$index = $this->abilities->execute_authoring_guide();

		$this->assertArrayHasKey( 'validate', $index );
		$validate = $index['validate'];

		$this->assertStringContainsString( 'save()', $validate['why'] );
		$this->assertStringContainsString( 'jsdom', $validate['requires'] );

		foreach ( array( $validate['tool'], $validate['scripts'] ) as $name ) {
			$this->assertTrue(
				wp_has_ability( $name ),
				$name . ' is named on the index but is not registered.'
			);
		}

		// And the guide it sends you to has to be one the index lists.
		$this->assertContains( $validate['guide'], wp_list_pluck( $index['guides'], 'name' ) );
	}

	/**
	 * The pointer belongs to the index. A single guide is the document that
	 * was asked for and nothing else.
	 */
	public function test_a_single_guide_carries_no_index_furniture() {
		$guide = $this->abilities->execute_authoring_guide( array( 'guide' => 'block-markup' ) );

		$this->assertArrayNotHasKey( 'validate', $guide );
		$this->assertArrayNotHasKey( 'guides', $guide );
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

	/**
	 * A pattern that hard-codes `#4f46e5` opts out of the site's palette, its
	 * dark mode and every future restyle. This is the way an agent puts the
	 * value in the design system instead and references it by slug, and it
	 * has to reach both homes a preset can live in.
	 */
	public function test_tokens_land_in_global_styles() {
		$result = $this->abilities->execute_add_design_tokens(
			array(
				'destination' => 'user',
				'tokens'      => array(
					array(
						'type'  => 'color',
						'slug'  => 'kiln-red',
						'name'  => 'Kiln Red',
						'value' => '#b3391f',
					),
					array(
						'type'  => 'spacing',
						'slug'  => 'band',
						'name'  => 'Band',
						'value' => 'clamp(3rem, 8vw, 7rem)',
					),
					array(
						'type'  => 'fontFamily',
						'slug'  => 'display-face',
						'name'  => 'Display Face',
						'value' => 'Fraunces, Georgia, serif',
					),
				),
			)
		);

		$this->assertSame(
			array(
				'color'      => array( 'kiln-red' ),
				'spacing'    => array( 'band' ),
				'fontFamily' => array( 'display-face' ),
			),
			$result['written']
		);
		$this->assertSame( array(), $result['skipped'] );
		$this->assertSame( 'user', $result['destination'] );

		// The point of writing them: the editor, and the next pattern, see them.
		$system = $this->abilities->execute_design_system();
		$this->assertContains( 'kiln-red', wp_list_pluck( $system['palette'], 'slug' ) );
		$this->assertContains( 'band', wp_list_pluck( $system['spacing'], 'slug' ) );
		$this->assertContains( 'display-face', wp_list_pluck( $system['fontFamilies'], 'slug' ) );
	}

	/**
	 * The default destination, because a token written here travels with the
	 * theme and is versioned with it.
	 */
	public function test_tokens_land_in_theme_json_by_default() {
		$this->give_the_theme_a_theme_json();

		$result = $this->abilities->execute_add_design_tokens(
			array(
				'tokens' => array(
					array(
						'type'  => 'fontSize',
						'slug'  => 'display',
						'name'  => 'Display',
						'value' => '3.5rem',
					),
				),
			)
		);

		$this->assertSame( 'theme', $result['destination'] );
		$this->assertSame( array( 'fontSize' => array( 'display' ) ), $result['written'] );

		$config = json_decode( file_get_contents( $this->theme_json ), true );
		$this->assertSame( 'display', $config['settings']['typography']['fontSizes'][0]['slug'] );
		$this->assertSame( '3.5rem', $config['settings']['typography']['fontSizes'][0]['size'] );
	}

	/**
	 * Never an overwrite. A slug this site already answers for keeps its own
	 * value, and the agent is told so rather than left to assume its value
	 * landed — otherwise it would go on to invent `accent-2` beside it.
	 */
	public function test_an_existing_slug_is_reported_not_overwritten() {
		$result = $this->abilities->execute_add_design_tokens(
			array(
				'destination' => 'user',
				'tokens'      => array(
					array(
						'type'  => 'color',
						'slug'  => 'black',
						'name'  => 'Not Black',
						'value' => '#ff0000',
					),
					array(
						'type'  => 'color',
						'slug'  => 'kiln-red',
						'name'  => 'Kiln Red',
						'value' => '#b3391f',
					),
				),
			)
		);

		$this->assertSame( array( 'color' => array( 'kiln-red' ) ), $result['written'] );
		$this->assertSame(
			array(
				array(
					'type' => 'color',
					'slug' => 'black',
				),
			),
			$result['skipped']
		);

		// Core's own black is still black.
		$palette = wp_list_pluck( $this->abilities->execute_design_system()['palette'], 'color', 'slug' );
		$this->assertNotSame( '#ff0000', $palette['black'] );
	}

	/**
	 * The cloud path can trust the service's token types; agent input has
	 * been through nothing. A near miss like "typography" must be refused,
	 * not dropped: `missing()` skips a type it does not know, so a silent
	 * drop would answer "wrote nothing" and the agent would go on to
	 * reference a preset that was never created.
	 */
	public function test_an_unknown_token_type_is_refused() {
		$result = $this->abilities->execute_add_design_tokens(
			array(
				'tokens' => array(
					array(
						'type'  => 'typography',
						'slug'  => 'display',
						'value' => '3.5rem',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_bad_token_type', $result->get_error_code() );
		$this->assertStringContainsString( 'fontSize', $result->get_error_message() );
	}

	/**
	 * The same grammar the service enforces, re-run here — an agent's value
	 * is as untrusted as the wire's.
	 */
	public function test_a_value_that_is_not_a_value_is_refused() {
		$result = $this->abilities->execute_add_design_tokens(
			array(
				'destination' => 'user',
				'tokens'      => array(
					array(
						'type'  => 'color',
						'slug'  => 'sneaky',
						'value' => 'red; background:url(javascript:alert(1))',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_bad_token', $result->get_error_code() );
	}

	/**
	 * Core derives the CSS custom property from the slug, so a slug with a
	 * space in it lands in the file and resolves to nothing. And a preset
	 * with no label reads as a blank swatch in the editor, so the name is
	 * filled in from the slug rather than written empty.
	 */
	public function test_a_slug_is_normalized_and_a_name_is_optional() {
		$result = $this->abilities->execute_add_design_tokens(
			array(
				'destination' => 'user',
				'tokens'      => array(
					array(
						'type'  => 'color',
						'slug'  => 'Kiln Red!',
						'value' => '#b3391f',
					),
				),
			)
		);

		$this->assertSame( array( 'color' => array( 'kiln-red' ) ), $result['written'] );

		$palette = wp_list_pluck( $this->abilities->execute_design_system()['palette'], 'name', 'slug' );
		$this->assertSame( 'Kiln Red', $palette['kiln-red'] );
	}

	public function test_nothing_to_add_is_an_error_not_a_silent_no_op() {
		$result = $this->abilities->execute_add_design_tokens( array( 'tokens' => array() ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_no_tokens', $result->get_error_code() );
	}

	/**
	 * A classic theme has no theme.json to write into, and the refusal has to
	 * name the way through rather than just failing.
	 */
	public function test_a_theme_without_a_theme_json_says_where_else_to_put_them() {
		$result = $this->abilities->execute_add_design_tokens(
			array(
				'tokens' => array(
					array(
						'type'  => 'color',
						'slug'  => 'kiln-red',
						'value' => '#b3391f',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_cloud_no_theme_json', $result->get_error_code() );
		$this->assertStringContainsString( 'Site styles', $result->get_error_message() );
	}

	/**
	 * The one thing an ability cannot do is take a file, so the route that
	 * can has to be discoverable from inside the abilities — otherwise every
	 * agent works it out again, or gives up and inlines a remote URL.
	 * `find-media` is where an agent looking for an image arrives, so the
	 * instructions ride along with the answer.
	 */
	public function test_find_media_says_how_to_send_a_file() {
		$this->require_abilities_api();

		$found = wp_get_ability( 'pattern-builder/find-media' )->execute( array() );

		$this->assertArrayHasKey( 'upload', $found );
		$this->assertSame( 'POST', $found['upload']['method'] );
		$this->assertStringContainsString( '/pattern-builder/v1/assets', $found['upload']['route'] );
		$this->assertStringContainsString( 'Content-Disposition', wp_json_encode( $found['upload']['headers'] ) );
		// An example an agent can run, rather than a shape to infer.
		$this->assertStringContainsString( '--data-binary', $found['upload']['example'] );
		$this->assertStringContainsString( 'destination', wp_json_encode( $found['upload']['query'] ) );
	}

	/**
	 * The upload limits are reported rather than discovered by a failure: the
	 * resize cap and the server's own ceiling both change the answer.
	 */
	public function test_find_media_reports_the_upload_limits() {
		$this->require_abilities_api();

		$found = wp_get_ability( 'pattern-builder/find-media' )->execute( array() );

		$this->assertStringContainsString( '2400', $found['upload']['limits'] );
	}

	/**
	 * An agent that reaches `add-asset` with a JPEG in hand must be told
	 * where to send it, in the description it has already been given.
	 */
	public function test_add_asset_names_the_route_in_its_description() {
		$this->require_abilities_api();

		$description = wp_get_ability( 'pattern-builder/add-asset' )->get_description();

		$this->assertStringContainsString( '/pattern-builder/v1/assets', $description );
		$this->assertStringContainsString( 'Content-Disposition', $description );
	}

	/**
	 * Neither form given is an error that says what to do, including the
	 * route for the case an ability cannot serve.
	 */
	public function test_add_asset_refuses_with_nothing_to_store() {
		$this->require_abilities_api();

		$result = wp_get_ability( 'pattern-builder/add-asset' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_asset_nothing_given', $result->get_error_code() );
		$this->assertStringContainsString( '/pattern-builder/v1/assets', $result->get_error_message() );
	}

	/**
	 * Both forms given is ambiguous rather than a silent preference.
	 */
	public function test_add_asset_refuses_both_forms_at_once() {
		$this->require_abilities_api();

		$result = wp_get_ability( 'pattern-builder/add-asset' )->execute(
			array(
				'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
				'url' => 'https://example.org/hero.png',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'pb_asset_ambiguous', $result->get_error_code() );
	}

	/**
	 * An SVG an agent authored is stored, and the answer is the reference to
	 * put in the markup rather than a path to work one out from.
	 */
	public function test_add_asset_stores_an_authored_svg() {
		$this->require_abilities_api();
		$this->use_a_writable_theme();

		$result = wp_get_ability( 'pattern-builder/add-asset' )->execute(
			array(
				'svg'      => '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>',
				'filename' => 'dot',
			)
		);

		$this->assertNotWPError( $result );
		// The extension is added rather than the file stored without one.
		$this->assertSame( 'dot.svg', $result['filename'] );
		$this->assertStringContainsString( 'get_stylesheet_directory_uri', $result['reference'] );
	}

	/**
	 * A placeholder is drawn and stored, so a pattern under construction has
	 * something local in its image slots rather than a remote service's URL.
	 */
	public function test_a_placeholder_is_drawn_and_stored() {
		$this->require_abilities_api();
		$this->use_a_writable_theme();

		$result = wp_get_ability( 'pattern-builder/add-placeholder-image' )->execute(
			array(
				'width'  => 1400,
				'height' => 700,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'placeholder-1400x700.svg', $result['filename'] );
		$this->assertStringContainsString( 'get_stylesheet_directory_uri', $result['reference'] );
	}

	/**
	 * `add-font` is idempotent and says so: installing a family twice leaves
	 * the same files and the same preset, so core may accept a repeat.
	 */
	public function test_add_font_is_marked_idempotent() {
		$this->require_abilities_api();

		$meta = wp_get_ability( 'pattern-builder/add-font' )->get_meta();

		$this->assertTrue( $meta['annotations']['idempotent'] );
	}

	/**
	 * Storing an asset is not idempotent — a second call stores a second
	 * copy — and must not claim to be, since the annotation is behaviour.
	 */
	public function test_add_asset_is_not_marked_idempotent() {
		$this->require_abilities_api();

		$meta = wp_get_ability( 'pattern-builder/add-asset' )->get_meta();

		$this->assertFalse( $meta['annotations']['idempotent'] );
	}

	/**
	 * A font family needs naming; an empty call is an error rather than an
	 * arbitrary choice.
	 */
	public function test_add_font_needs_a_family() {
		$this->require_abilities_api();

		$result = wp_get_ability( 'pattern-builder/add-font' )->execute( array( 'family' => '' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pb_font_no_family', $result->get_error_code() );
	}

	/**
	 * Give the active theme a minimal theme.json for the duration of one
	 * test, and remember it so tear_down takes it away again.
	 */
	private function give_the_theme_a_theme_json() {
		$this->theme_json = get_stylesheet_directory() . '/theme.json';
		file_put_contents( $this->theme_json, wp_json_encode( array( 'version' => 3 ) ) );
		wp_clean_theme_json_cache();
	}
}
