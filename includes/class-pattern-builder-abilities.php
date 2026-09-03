<?php

namespace TwentyBellows\PatternBuilder;

/**
 * Abilities: what an agent can ask this site about its patterns, and what it
 * can ask this site to store.
 *
 * The Abilities API (WordPress core) is a registry of machine-readable
 * capabilities — JSON Schema in, JSON Schema out, a permission callback, and
 * annotations saying whether a thing reads or writes. Core exposes the
 * registry over REST at `wp-abilities/v1`, so anything that can authenticate
 * to this site can discover these and call them; a bridge that turns the
 * registry into MCP tools gets them for free, and so does every other plugin
 * that registers abilities. That is the reason to register here rather than
 * to build a bespoke agent interface of our own.
 *
 * What is deliberately absent is generation. Nothing here takes a prompt.
 * An `execute_callback` that turned a description into a pattern would need a
 * model behind it, which would put this plugin back in the business of
 * running inference on somebody's behalf. The judgement of what a good
 * pattern is belongs to whatever agent is calling, and the knowledge it needs
 * travels as prose. These abilities are the two things an agent cannot supply
 * for itself: what is true about *this* site, and somewhere to put the result.
 *
 * Note what is also absent: validation. Whether block markup is valid is
 * decided by re-running the block's `save()`, which is JavaScript — no PHP
 * here can answer it (`WP_Block_Type` has no `save`; `serialize_block()`
 * only replays what it parsed). The most useful thing to offer is the one
 * thing the server cannot do, so the agent has to run that check itself
 * before calling `create-pattern`.
 *
 * Registration is conditional. The API arrived in WordPress well after this
 * plugin's 6.8 floor, and none of the plugin's own functionality depends on
 * it, so on an older site the whole file is inert and the REST controller
 * remains the way in.
 */
class Pattern_Builder_Abilities {

	const CATEGORY = 'pattern-builder';

	/**
	 * Hook the component into WordPress.
	 */
	public function __construct() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the category these abilities group under.
	 */
	public function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Pattern Builder', 'pattern-builder' ),
				'description' => __( 'Read this site’s design system and blocks, and store patterns in its theme or database.', 'pattern-builder' ),
			)
		);
	}

	/**
	 * Register every ability.
	 */
	public function register_abilities() {
		$this->register_design_system();
		$this->register_block_types();
		$this->register_list_patterns();
		$this->register_get_pattern();
		$this->register_render_pattern();
		$this->register_authoring_guide();
		$this->register_validator();
		$this->register_editor_scripts();
		$this->register_create_pattern();
		$this->register_update_pattern();
		$this->register_add_design_tokens();
		$this->register_find_media();
		$this->register_add_asset();
		$this->register_add_placeholder_image();
		$this->register_list_fonts();
		$this->register_add_font();
	}

	/**
	 * Anyone who may look at patterns in the editor may read these.
	 *
	 * @return bool
	 */
	public function can_read() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Writing a theme pattern writes a file into the theme, which is the same
	 * authority the REST controller asks for.
	 *
	 * @return bool
	 */
	public function can_write() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * The annotations shared by every read: no state changes, same answer
	 * twice. Core enforces these — a readonly ability is refused over
	 * anything but GET — so they are behaviour, not documentation.
	 *
	 * @return array
	 */
	private function read_annotations() {
		return array(
			// Abilities are not exposed over the REST API unless they say so,
			// and an agent that cannot reach these cannot use them.
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * The design system as this site actually resolves it.
	 *
	 * An agent writing a pattern needs the palette, the spacing scale and the
	 * type scale, and getting them itself means reading theme.json, merging
	 * the parent theme's, and applying whatever a style variation changed.
	 * WordPress already does all of that; this hands over the answer.
	 */
	private function register_design_system() {
		wp_register_ability(
			'pattern-builder/get-design-system',
			array(
				'label'               => __( 'Get the design system', 'pattern-builder' ),
				'description'         => __( 'Returns this site’s resolved design tokens — color palette and gradients, spacing scale, typography (font families and sizes), and layout widths — as WordPress merges them from core, the parent theme, the child theme and the active style variation. Use these values when writing pattern markup instead of inventing colors or sizes.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'palette'      => array(
							'type'        => 'array',
							'description' => 'Color presets. Use the slug in a block\'s backgroundColor/textColor attribute, or var(--wp--preset--color--{slug}) in CSS.',
							'items'       => array( 'type' => 'object' ),
						),
						'gradients'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'spacing'      => array(
							'type'        => 'array',
							'description' => 'Spacing presets. Reference as var(--wp--preset--spacing--{slug}).',
							'items'       => array( 'type' => 'object' ),
						),
						'fontSizes'    => array(
							'type'        => 'array',
							'description' => 'Font size presets. Use the slug in a block\'s fontSize attribute.',
							'items'       => array( 'type' => 'object' ),
						),
						'fontFamilies' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'layout'       => array(
							'type'        => 'object',
							'description' => 'contentSize and wideSize — the widths a constrained layout uses.',
						),
						'variations'   => array(
							'type'        => 'array',
							'description' => 'Style variations the theme offers, by title.',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_design_system' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * Resolve the merged theme.json settings.
	 *
	 * @return array
	 */
	public function execute_design_system() {
		$settings = \WP_Theme_JSON_Resolver::get_merged_data()->get_settings();

		$variations = array();
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			if ( isset( $variation['title'] ) ) {
				$variations[] = (string) $variation['title'];
			}
		}

		return array(
			'palette'      => $this->preset( $settings, array( 'color', 'palette' ) ),
			'gradients'    => $this->preset( $settings, array( 'color', 'gradients' ) ),
			'spacing'      => $this->preset( $settings, array( 'spacing', 'spacingSizes' ) ),
			'fontSizes'    => $this->preset( $settings, array( 'typography', 'fontSizes' ) ),
			'fontFamilies' => $this->preset( $settings, array( 'typography', 'fontFamilies' ) ),
			'layout'       => isset( $settings['layout'] ) ? $settings['layout'] : array(),
			'variations'   => $variations,
		);
	}

	/**
	 * Read a preset list out of merged settings.
	 *
	 * Presets are keyed by origin (default/theme/custom) and a later origin
	 * overrides an earlier one by slug — which is what the editor shows, so
	 * it is what an agent should be told.
	 *
	 * @param array $settings Merged settings.
	 * @param array $path     Path to the preset group.
	 * @return array
	 */
	private function preset( $settings, $path ) {
		$node = $settings;
		foreach ( $path as $key ) {
			if ( ! isset( $node[ $key ] ) ) {
				return array();
			}
			$node = $node[ $key ];
		}

		if ( ! is_array( $node ) ) {
			return array();
		}

		// A flat list is already resolved; otherwise flatten the origins in
		// precedence order so the last definition of a slug wins.
		if ( isset( $node[0] ) ) {
			return array_values( $node );
		}

		$by_slug = array();
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			if ( empty( $node[ $origin ] ) || ! is_array( $node[ $origin ] ) ) {
				continue;
			}
			foreach ( $node[ $origin ] as $item ) {
				if ( isset( $item['slug'] ) ) {
					$by_slug[ $item['slug'] ] = $item;
				}
			}
		}

		return array_values( $by_slug );
	}

	/**
	 * The block types this site actually has.
	 *
	 * Markup for a block that is not registered here parses to
	 * `core/missing`, so an agent guessing from general knowledge of
	 * WordPress will eventually write a pattern this site cannot render.
	 */
	private function register_block_types() {
		wp_register_ability(
			'pattern-builder/list-block-types',
			array(
				'label'               => __( 'List available block types', 'pattern-builder' ),
				'description'         => __( 'Returns every block type registered on this site with its attribute schema and whether it supports inner blocks. Markup referencing a block that is not in this list will render as an unrecognised block, so check here before using a block that is not core.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'namespace' => array(
							'type'        => 'string',
							'description' => 'Optional: only return blocks in this namespace, e.g. "core".',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'blocks' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_block_types' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * List registered block types.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute_block_types( $input = array() ) {
		$namespace = isset( $input['namespace'] ) ? (string) $input['namespace'] : '';
		$blocks    = array();

		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( '' !== $namespace && 0 !== strpos( $name, $namespace . '/' ) ) {
				continue;
			}

			$blocks[] = array(
				'name'        => $name,
				'title'       => isset( $type->title ) ? $type->title : '',
				'category'    => isset( $type->category ) ? $type->category : '',
				'attributes'  => is_array( $type->attributes ) ? $type->attributes : array(),
				'usesContext' => is_array( $type->uses_context ) ? $type->uses_context : array(),
				'dynamic'     => $type->is_dynamic(),
			);
		}

		return array( 'blocks' => $blocks );
	}

	/**
	 * Every pattern on this site, both kinds.
	 */
	private function register_list_patterns() {
		wp_register_ability(
			'pattern-builder/list-patterns',
			array(
				'label'               => __( 'List patterns', 'pattern-builder' ),
				'description'         => __( 'Returns the patterns on this site: theme patterns (PHP files in the theme) and user patterns (reusable blocks in the database), with their names, titles, categories and synced status. Content is omitted; use get-pattern for one pattern\'s markup.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'source' => array(
							'type'        => 'string',
							'enum'        => array( 'theme', 'user', 'all' ),
							'description' => 'Which patterns to return. Defaults to all.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'patterns' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_patterns' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * List patterns without their markup.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute_list_patterns( $input = array() ) {
		$source = isset( $input['source'] ) ? (string) $input['source'] : 'all';
		$store  = new Pattern_File_Store();
		$found  = array();

		if ( 'user' !== $source ) {
			$found = array_merge( $found, $store->get_theme_patterns() );
		}
		if ( 'theme' !== $source ) {
			$found = array_merge( $found, $store->get_user_patterns() );
		}

		$patterns = array();
		foreach ( $found as $pattern ) {
			$patterns[] = $this->summarize( $pattern );
		}

		return array( 'patterns' => $patterns );
	}

	/**
	 * One pattern, with its markup.
	 */
	private function register_get_pattern() {
		wp_register_ability(
			'pattern-builder/get-pattern',
			array(
				'label'               => __( 'Get a pattern', 'pattern-builder' ),
				'description'         => __( 'Returns one pattern including its raw block markup. Identify a theme pattern by its namespaced name (e.g. "my-theme/hero") and a user pattern by its numeric post ID. Useful for reading an existing pattern before composing something in the same style.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'string',
							'description' => 'Namespaced pattern name, or a user pattern\'s post ID.',
							'minLength'   => 1,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pattern' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_pattern' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * Fetch one pattern with content.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_get_pattern( $input ) {
		$pattern = $this->find( isset( $input['id'] ) ? (string) $input['id'] : '' );
		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}

		$summary            = $this->summarize( $pattern );
		$summary['content'] = $pattern->content;

		return array( 'pattern' => $summary );
	}

	/**
	 * What a stored pattern renders as on the front end.
	 *
	 * Takes an id rather than markup on purpose. A read-only ability is
	 * refused over anything but GET, and a pattern's markup does not belong
	 * in a query string; marking this writable to allow a POST body would
	 * make the annotation a lie. An agent that wants to see un-saved markup
	 * should validate it first — which it has to do anyway, and which this
	 * site cannot do for it — then store it and render that.
	 */
	private function register_render_pattern() {
		wp_register_ability(
			'pattern-builder/render-pattern',
			array(
				'label'               => __( 'Render a pattern', 'pattern-builder' ),
				'description'         => __( 'Returns the front-end HTML a stored pattern produces, with blocks resolved. Use it to check that a pattern renders the way it was intended — note that HTML rendering correctly says nothing about whether the block markup is valid in the editor, which only a JavaScript block validator can decide.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'string',
							'description' => 'Namespaced pattern name, or a user pattern\'s post ID.',
							'minLength'   => 1,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'html' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_render_pattern' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * Render a stored pattern.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_render_pattern( $input ) {
		$pattern = $this->find( isset( $input['id'] ) ? (string) $input['id'] : '' );
		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}

		return array( 'html' => do_blocks( $pattern->content ) );
	}

	/**
	 * Hand an agent the knowledge, not just the mechanism.
	 *
	 * The other abilities say what is true about this site and take finished
	 * markup; none of them says how to write a good pattern. That knowledge is
	 * prose, and prose is the most portable thing there is — so rather than
	 * shipping it only as a Claude skill, this serves the same documents over
	 * the same interface everything else uses. Whatever is calling can put
	 * them wherever its own harness expects: a SKILL.md, a rules file, a
	 * system prompt, an AGENTS.md.
	 *
	 * It answers with an index by default. The full set runs to tens of
	 * thousands of words, and an ability that dumped all of it into a caller's
	 * context uninvited would be a poor guest.
	 */
	private function register_authoring_guide() {
		wp_register_ability(
			'pattern-builder/get-authoring-guide',
			array(
				'label'               => __( 'Get the pattern authoring guide', 'pattern-builder' ),
				'description'         => __( 'Returns documentation on how to write good block patterns — what makes one good, the kinds of pattern and the headers each needs, which blocks are allowed where, the attribute-to-markup contract, and the design/content split with Pattern Overrides. Call it with no input for the index of available guides, then request one by name. The text is agent-facing instructions in Markdown: install it wherever your harness reads instructions from. Read this before writing pattern markup by hand.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'guide' => array(
							'type'        => 'string',
							'description' => 'Which guide to return. Omit for the index; "all" for every guide concatenated.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'guides'   => array(
							'type'        => 'array',
							'description' => 'The index: name, title and size of each available guide.',
							'items'       => array( 'type' => 'object' ),
						),
						'name'     => array( 'type' => 'string' ),
						'format'   => array( 'type' => 'string' ),
						'content'  => array(
							'type'        => 'string',
							'description' => 'Markdown. Agent-facing instructions, not user documentation.',
						),
						'validate' => array(
							'type'        => 'object',
							'description' => 'On the index only: the check to run before storing anything, and which abilities hand you the means to run it.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_authoring_guide' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * Where the guides live.
	 *
	 * Under the plugin rather than beside the Claude skill, because the skill
	 * directory is development tooling and does not ship. The skill is a
	 * symlink to this directory, so there is one copy of every document and
	 * both ways of consuming it read the same file.
	 *
	 * @return string
	 */
	private function guide_dir() {
		return plugin_dir_path( PATTERN_BUILDER_FILE ) . 'guides/pattern-author/';
	}

	/**
	 * The guides this plugin ships, by name.
	 *
	 * @return array name => relative path.
	 */
	private function guide_files() {
		return array(
			'authoring'            => 'SKILL.md',
			'pattern-kinds'        => 'references/pattern-kinds.md',
			'block-vocabulary'     => 'references/block-vocabulary.md',
			'block-markup'         => 'references/block-markup.md',
			'design-content-split' => 'references/design-content-split.md',
			'assets'               => 'references/assets.md',
			'keeping-current'      => 'references/keeping-current.md',
			'abilities'            => 'references/abilities.md',
		);
	}

	/**
	 * Every guide this site offers, loaded and filtered.
	 *
	 * The documents this plugin ships are general — they describe WordPress,
	 * not your project. What an agent most needs on top of them is the house
	 * rule: which blocks this build has settled on, the copy voice, the reason
	 * a particular section is composed the way it is. A theme knows those and
	 * the plugin cannot, so the set is filtered before it is served.
	 *
	 * The filter deals in text rather than file paths on purpose: a guide
	 * added this way needs no filesystem access, and no caller can steer a
	 * read outside the plugin.
	 *
	 * @return array name => array( title, content ).
	 */
	private function guides() {
		$guides = array();

		foreach ( $this->guide_files() as $name => $relative ) {
			$text = $this->read_guide( $relative );
			if ( null === $text ) {
				continue;
			}
			$guides[ $name ] = array(
				'title'   => $this->guide_title( $text, $name ),
				'content' => $text,
			);
		}

		/**
		 * Filters the authoring guides an agent is given.
		 *
		 * Amend a shipped guide by appending to its `content`, or add one of
		 * your own under a new key. Both reach every agent that asks this
		 * site how to write a pattern, which makes this the place to put a
		 * project's own conventions.
		 *
		 *     add_filter( 'pattern_builder_authoring_guides', function ( $guides ) {
		 *         $guides['house-rules'] = array(
		 *             'title'   => 'House rules for this theme',
		 *             'content' => "# House rules\n\nSections are full-width…",
		 *         );
		 *         return $guides;
		 *     } );
		 *
		 * @param array $guides Guides, keyed by name, each with `title` and
		 *                      `content` (Markdown).
		 */
		$guides = apply_filters( 'pattern_builder_authoring_guides', $guides );

		// A filter that returns something unusable should not take the
		// ability down with it.
		if ( ! is_array( $guides ) ) {
			return array();
		}

		$clean = array();
		foreach ( $guides as $name => $guide ) {
			if ( ! is_array( $guide ) || empty( $guide['content'] ) || ! is_string( $guide['content'] ) ) {
				continue;
			}
			$key = sanitize_key( (string) $name );
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = array(
				'title'   => isset( $guide['title'] ) && is_string( $guide['title'] )
					? $guide['title']
					: $this->guide_title( $guide['content'], $key ),
				'content' => $guide['content'],
			);
		}

		return $clean;
	}

	/**
	 * Serve the index, one guide, or all of them.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_authoring_guide( $input = array() ) {
		$guides = $this->guides();
		$wanted = isset( $input['guide'] ) ? sanitize_key( (string) $input['guide'] ) : '';

		if ( '' === $wanted ) {
			$index = array();
			foreach ( $guides as $name => $guide ) {
				$index[] = array(
					'name'  => $name,
					'title' => $guide['title'],
					'words' => str_word_count( wp_strip_all_tags( $guide['content'] ) ),
				);
			}

			return array(
				'guides'   => $index,
				'format'   => 'markdown',
				'name'     => 'index',
				// Say what this is for, since an index alone does not.
				'content'  => __( 'Agent-facing instructions for writing WordPress block patterns. Request one by name with input[guide], or "all" for everything. Install the Markdown wherever your harness reads instructions from.', 'pattern-builder' ),

				/*
				 * An agent that goes straight to create-pattern never reads a
				 * guide, and the one step it cannot afford to skip is the one
				 * this site cannot do for it. So the index carries it, where
				 * anyone asking what to read will see it first.
				 */
				'validate' => array(
					'why'      => __( 'Validate markup before storing it. A block is valid only if re-running its save() reproduces the markup, and save() is JavaScript — no server can run it, so nothing here checks this for you. Invalid markup renders correctly on the front end and fails the moment an editor opens the pattern.', 'pattern-builder' ),
					'before'   => array( 'pattern-builder/create-pattern', 'pattern-builder/update-pattern', 'pattern-builder/upload-pattern' ),
					'tool'     => 'pattern-builder/get-validator',
					'scripts'  => 'pattern-builder/get-editor-scripts',
					'requires' => 'node, jsdom',
					'guide'    => 'block-markup',
				),
			);
		}

		if ( 'all' === $wanted ) {
			$parts = array();
			foreach ( $guides as $name => $guide ) {
				$parts[] = "<!-- guide: {$name} -->\n\n" . $guide['content'];
			}

			return array(
				'name'    => 'all',
				'format'  => 'markdown',
				'content' => implode( "\n\n---\n\n", $parts ),
			);
		}

		if ( ! isset( $guides[ $wanted ] ) ) {
			return new \WP_Error(
				'pb_guide_not_found',
				/* translators: %s: comma separated guide names. */
				sprintf( __( 'No guide by that name. Available: %s.', 'pattern-builder' ), implode( ', ', array_keys( $guides ) ) ),
				array( 'status' => 404 )
			);
		}

		return array(
			'name'    => $wanted,
			'format'  => 'markdown',
			'content' => $guides[ $wanted ]['content'],
		);
	}

	/**
	 * Read one guide, with its YAML front matter stripped.
	 *
	 * The main guide doubles as a Claude skill, so it carries front matter
	 * that means nothing to anybody else. The prose underneath is the part
	 * worth handing over.
	 *
	 * @param string $relative Path under the guide directory.
	 * @return string|null Null when the file is absent or unreadable.
	 */
	private function read_guide( $relative ) {
		$path = $this->guide_dir() . $relative;

		// Nothing here takes a path from a caller, but keep the read inside
		// the plugin regardless.
		$real = realpath( $path );
		$root = realpath( $this->guide_dir() );
		if ( ! $real || ! $root || 0 !== strpos( $real, $root ) || ! is_readable( $real ) ) {
			return null;
		}

		$text = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin ships.
		if ( false === $text ) {
			return null;
		}

		return trim( preg_replace( '/\A---\r?\n.*?\r?\n---\r?\n/s', '', $text ) );
	}

	/**
	 * A guide's title, from its first heading.
	 *
	 * @param string $text     Guide text.
	 * @param string $fallback Name to use when there is no heading.
	 * @return string
	 */
	private function guide_title( $text, $fallback ) {
		if ( preg_match( '/^#\s+(.+)$/m', $text, $m ) ) {
			return trim( $m[1] );
		}
		return $fallback;
	}

	/**
	 * Hand over the validator itself.
	 *
	 * The guides tell an agent to validate before it stores anything, and for
	 * an agent with a shell on this machine that is a path on disk. An agent
	 * that reached these abilities over HTTP has no such path and no copy of
	 * the script, which made the instruction unfollowable for exactly the
	 * callers the Abilities API exists to serve. So the scripts travel too.
	 */
	private function register_validator() {
		wp_register_ability(
			'pattern-builder/get-validator',
			array(
				'label'               => __( 'Get the pattern validator', 'pattern-builder' ),
				'description'         => __( 'Returns the source of the block markup validator, as files to write next to each other and run with Node. Block validity is decided by re-running a block\'s save(), which is JavaScript, so no server can answer it and this site cannot validate on your behalf — but it can hand you the tool. Pair it with get-editor-scripts, which says where this site\'s own block code lives, and the check runs against the exact WordPress your pattern is destined for. Requires Node and jsdom (npm i --no-save jsdom).', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'files' => array(
							'type'        => 'array',
							'description' => 'Each with a name and its contents. Write them into one directory.',
							'items'       => array( 'type' => 'object' ),
						),
						'entry' => array(
							'type'        => 'string',
							'description' => 'The file to run.',
						),
						'usage' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_validator' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * The files the validator is made of.
	 *
	 * @return array|\WP_Error
	 */
	public function execute_validator() {
		$files = array();

		foreach ( array( 'validate-pattern.mjs', 'wp-core.mjs' ) as $name ) {
			$contents = $this->read_script( $name );
			if ( null === $contents ) {
				return new \WP_Error(
					'pb_validator_missing',
					/* translators: %s: file name. */
					sprintf( __( 'The validator is not installed on this site: %s is missing.', 'pattern-builder' ), $name ),
					array( 'status' => 500 )
				);
			}
			$files[] = array(
				'name'     => $name,
				'contents' => $contents,
			);
		}

		return array(
			'files' => $files,
			'entry' => 'validate-pattern.mjs',

			/*
			 * Not translated, deliberately. This is a command line recipe rather
			 * than interface copy, and the guides it belongs beside are served as
			 * they were written too.
			 */
			'usage' => implode(
				"\n",
				array(
					'Write both files into one directory, then:',
					'',
					'  npm i --no-save jsdom',
					"  curl -u USER:APP_PASSWORD 'SITE/?rest_route=/wp-abilities/v1/abilities/pattern-builder/get-editor-scripts/run' > scripts.json",
					'  node validate-pattern.mjs --scripts scripts.json pattern.html',
					'',
					"The first run downloads this site's block code (about 4MB) and caches it.",
					'Exit status is non-zero when anything is invalid, in an old form, or has lost an attribute.',
				)
			),
		);
	}

	/**
	 * Where this site's own editor scripts are, in the order they load.
	 *
	 * WordPress ships every byte the validator needs and serves it over HTTP
	 * already. What it does not serve is the dependency graph: the manifest
	 * core generates is a PHP file, so a request for it executes and returns
	 * nothing. Only the site can answer that, which is why this exists.
	 */
	private function register_editor_scripts() {
		wp_register_ability(
			'pattern-builder/get-editor-scripts',
			array(
				'label'               => __( 'Get this site\'s editor script URLs', 'pattern-builder' ),
				'description'         => __( 'Returns the URLs of this site\'s own block editor JavaScript, in dependency order, for a validator to load. This is the block library this site actually runs, which is the only version whose opinion counts: whether markup is what a block writes today is a question different WordPress versions answer differently. Feed the response to the validator from get-validator.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'scripts'   => array(
							'type'        => 'array',
							'description' => 'Absolute URLs, dependencies first. Load them in this order.',
							'items'       => array( 'type' => 'string' ),
						),
						'wordpress' => array( 'type' => 'string' ),
						'site'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_editor_scripts' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * Resolve the block editor's scripts to URLs, in load order.
	 *
	 * @return array
	 */
	public function execute_editor_scripts() {
		global $wp_version;

		/*
		 * A fresh registry rather than the global one: `all_deps()` fills
		 * `to_do`, and leaving that behind on the singleton would change what
		 * a later part of this request decides to print.
		 */
		$scripts = new \WP_Scripts();
		$scripts->all_deps( array( 'wp-blocks', 'wp-block-editor', 'wp-block-library' ) );

		$urls = array();
		foreach ( $scripts->to_do as $handle ) {
			if ( empty( $scripts->registered[ $handle ]->src ) ) {
				continue;
			}

			$item = $scripts->registered[ $handle ];
			$src  = $item->src;

			// Core registers its own scripts with a site-relative src.
			if ( ! preg_match( '|^(https?:)?//|', $src ) ) {
				$src = site_url( $src );
			}

			// The version keeps a caching client honest across upgrades.
			$ver = isset( $item->ver ) ? $item->ver : $wp_version;
			if ( $ver ) {
				$src = add_query_arg( 'ver', $ver, $src );
			}

			$urls[] = $src;
		}

		return array(
			'scripts'   => $urls,
			'wordpress' => $wp_version,
			'site'      => home_url(),
		);
	}

	/**
	 * Read one of the shipped scripts.
	 *
	 * @param string $name File name under the guide's scripts directory.
	 * @return string|null Null when it is not there.
	 */
	private function read_script( $name ) {
		$root = realpath( $this->guide_dir() . 'scripts' );
		$path = realpath( $this->guide_dir() . 'scripts/' . $name );

		// Nothing here takes a name from a caller, but keep the read inside
		// the plugin regardless.
		if ( ! $root || ! $path || 0 !== strpos( $path, $root ) || ! is_readable( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin ships.

		return false === $contents ? null : $contents;
	}

	/**
	 * Store a new pattern.
	 *
	 * The markup arrives finished. Nothing here composes it.
	 */
	private function register_create_pattern() {
		wp_register_ability(
			'pattern-builder/create-pattern',
			array(
				'label'               => __( 'Create a pattern', 'pattern-builder' ),
				'description'         => __( 'Stores finished block markup as a new pattern — either a PHP file in the active theme, or a reusable block in the database. This does not generate anything: supply markup you have already written and validated with a JavaScript block validator, because invalid markup renders correctly on the front end and only fails once an editor opens it.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->write_schema( true ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pattern' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_create_pattern' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Update an existing pattern.
	 */
	private function register_update_pattern() {
		wp_register_ability(
			'pattern-builder/update-pattern',
			array(
				'label'               => __( 'Update a pattern', 'pattern-builder' ),
				'description'         => __( 'Replaces an existing pattern’s markup and metadata. Overwrites whatever is there, so read the pattern first if you mean to preserve part of it. As with create-pattern, validate the markup before sending it.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->write_schema( false ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pattern' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_update_pattern' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,

						/*
						 * Not destructive, even though it overwrites. Core reads
						 * these to pick the HTTP method a call must arrive on —
						 * readonly is GET, destructive plus idempotent is DELETE,
						 * everything else is POST — so `destructive` here means
						 * delete-like, not "changes data". Marking an update
						 * destructive would make it callable only over DELETE.
						 */
						'destructive' => false,
						// The same call twice leaves the same pattern.
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * The input both writes accept.
	 *
	 * @param bool $creating Whether this is the create schema.
	 * @return array
	 */
	private function write_schema( $creating ) {
		$properties = array(
			'title'         => array(
				'type'        => 'string',
				'description' => 'Human-readable pattern title.',
				'minLength'   => 1,
			),
			'content'       => array(
				'type'        => 'string',
				'description' => 'The pattern\'s block markup, complete and already validated.',
				'minLength'   => 1,
			),
			'source'        => array(
				'type'        => 'string',
				'enum'        => array( 'theme', 'user' ),
				'description' => 'Where to store it: "theme" writes a PHP file into the active theme, "user" creates a reusable block. Defaults to theme.',
			),
			'name'          => array(
				'type'        => 'string',
				'description' => 'Namespaced slug for a theme pattern, e.g. "my-theme/hero". Derived from the title when omitted.',
			),
			'description'   => array(
				'type'        => 'string',
				'description' => 'Short description shown in the inserter.',
			),
			'categories'    => array(
				'type'        => 'array',
				'description' => 'Pattern category slugs.',
				'items'       => array( 'type' => 'string' ),
			),
			'keywords'      => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'synced'        => array(
				'type'        => 'boolean',
				'description' => 'Whether the pattern is synced. A synced theme pattern can be referenced with core/pattern and have its slots filled.',
			),
			'viewportWidth' => array(
				'type'        => 'integer',
				'description' => 'Preview width in pixels.',
			),
		);

		if ( $creating ) {
			return array(
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => array( 'title', 'content' ),
				'additionalProperties' => false,
			);
		}

		$properties['id'] = array(
			'type'        => 'string',
			'description' => 'Namespaced pattern name, or a user pattern\'s post ID.',
			'minLength'   => 1,
		);

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'id', 'content' ),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * Create a pattern from finished markup.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_create_pattern( $input ) {
		$source = isset( $input['source'] ) ? (string) $input['source'] : 'theme';

		if ( 'user' === $source ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'wp_block',
					'post_status'  => 'publish',
					'post_title'   => (string) $input['title'],
					'post_content' => (string) $input['content'],
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			return array( 'pattern' => $this->summarize( Abstract_Pattern::from_post( get_post( $post_id ) ) ) );
		}

		$pattern = new Abstract_Pattern( $this->pattern_args( $input ) );
		$store   = new Pattern_File_Store();
		$result  = $store->update_theme_pattern( $pattern );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'pattern' => $this->summarize( $pattern ) );
	}

	/**
	 * Replace an existing pattern.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_update_pattern( $input ) {
		$existing = $this->find( (string) $input['id'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( 'user' === $existing->source ) {
			$post_id = wp_update_post(
				array(
					'ID'           => (int) $existing->id,
					'post_title'   => isset( $input['title'] ) ? (string) $input['title'] : $existing->title,
					'post_content' => (string) $input['content'],
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			return array( 'pattern' => $this->summarize( Abstract_Pattern::from_post( get_post( $post_id ) ) ) );
		}

		$args         = $this->pattern_args( $input, $existing );
		$args['name'] = $existing->name;

		$pattern = new Abstract_Pattern( $args );
		$store   = new Pattern_File_Store();
		$result  = $store->update_theme_pattern( $pattern );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'pattern' => $this->summarize( $pattern ) );
	}

	/**
	 * Adding a token is how a pattern's design ends up in the design system
	 * rather than hard-coded into its markup.
	 *
	 * An agent turning a screenshot into a pattern has colors, sizes and a
	 * type stack in hand and two places to put them: inline in the markup,
	 * where they opt the pattern out of the site's palette, its dark mode and
	 * every future restyle; or in the design system, where they become presets
	 * every block can reference by slug. The second is right and until now
	 * there was no way to do it over the wire — `theme.json` is a file with no
	 * REST route, and Global Styles took raw JSON with nothing validating it.
	 *
	 * The writing itself is `Pattern_Builder_Cloud_Tokens::apply()`, which a
	 * cloud download has always used: it writes only the slugs this site does
	 * not already define, so a definition here always wins over an incoming
	 * one, and it puts every value through the per-type grammar before it
	 * lands anywhere near a stylesheet.
	 */
	private function register_add_design_tokens() {
		wp_register_ability(
			'pattern-builder/add-design-tokens',
			array(
				'label'               => __( 'Add design tokens', 'pattern-builder' ),
				'description'         => __( 'Adds colors, gradients, spacing sizes, font sizes and font families to this site\'s design system, so a pattern can reference them by slug instead of hard-coding values. Writes to the active theme\'s theme.json, or to the site\'s Global Styles. A slug this site already defines is left alone and reported as skipped — this never overwrites an existing token. Call get-design-system first to see what exists.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'tokens'      => array(
							'type'        => 'array',
							'description' => 'The tokens to add.',
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'type'  => array(
										'type'        => 'string',
										'enum'        => array( 'color', 'gradient', 'spacing', 'fontSize', 'fontFamily' ),
										'description' => 'Which part of the design system this belongs to.',
									),
									'slug'  => array(
										'type'        => 'string',
										'description' => 'The slug a pattern references it by, e.g. "accent" for var:preset|color|accent.',
									),
									'name'  => array(
										'type'        => 'string',
										'description' => 'The human-readable label shown in the editor.',
									),
									'value' => array(
										'type'        => 'string',
										'description' => 'The value: a CSS color, a gradient, a length, or a font-family stack. Font files are never carried — a fontFamily is a stack of names only.',
									),
								),
								'required'             => array( 'type', 'slug', 'value' ),
								'additionalProperties' => false,
							),
						),
						'destination' => array(
							'type'        => 'string',
							'enum'        => array( 'theme', 'user' ),
							'description' => '"theme" writes the active theme\'s theme.json, so the tokens travel with the theme and are versioned with it; "user" writes Global Styles, which stays in this site\'s database and is revertable in the editor. Defaults to "theme".',
						),
					),
					'required'             => array( 'tokens' ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'written'     => array(
							'type'        => 'object',
							'description' => 'The slugs added, by token type.',
						),
						'skipped'     => array(
							'type'        => 'array',
							'description' => 'Tokens this site already defines, which were left as they are.',
							'items'       => array( 'type' => 'object' ),
						),
						'destination' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_add_design_tokens' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Write the tokens this site does not already define.
	 *
	 * The cloud download path hands `apply()` a package the service built and
	 * validated, so it can take `type`, `slug` and `name` on trust. An agent's
	 * input has been through nothing, so it is normalized first: an unknown
	 * type is refused rather than dropped (an agent that wrote "typography"
	 * for "fontSize" would otherwise get an empty result and go on to
	 * reference a preset that was never created), and a slug is put through
	 * `sanitize_title()` because core derives the CSS custom property from it
	 * — `My Colour!` would land in the file and resolve to nothing.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_add_design_tokens( $input ) {
		$tokens      = isset( $input['tokens'] ) ? (array) $input['tokens'] : array();
		$destination = ( isset( $input['destination'] ) && 'user' === $input['destination'] ) ? 'user' : 'theme';
		$known       = array_keys( Pattern_Builder_Cloud_Tokens::types() );

		if ( ! $tokens ) {
			return new \WP_Error(
				'pb_no_tokens',
				__( 'No tokens to add.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		$normalized = array();
		foreach ( $tokens as $token ) {
			$token = (array) $token;
			$type  = isset( $token['type'] ) ? (string) $token['type'] : '';
			$slug  = isset( $token['slug'] ) ? sanitize_title( (string) $token['slug'] ) : '';

			if ( ! in_array( $type, $known, true ) ) {
				return new \WP_Error(
					'pb_bad_token_type',
					sprintf(
						/* translators: 1: the type given, 2: the accepted types. */
						__( '"%1$s" is not a design token type. Use one of: %2$s.', 'pattern-builder' ),
						$type,
						implode( ', ', $known )
					),
					array( 'status' => 400 )
				);
			}

			if ( '' === $slug ) {
				return new \WP_Error(
					'pb_bad_token_slug',
					__( 'Every token needs a slug of lower-case letters, digits and hyphens — it is the name a pattern references it by.', 'pattern-builder' ),
					array( 'status' => 400 )
				);
			}

			$normalized[] = array(
				'type'  => $type,
				'slug'  => $slug,
				// merge_settings() writes the name unconditionally, and a
				// preset with no label reads as an empty swatch in the editor.
				'name'  => isset( $token['name'] ) && '' !== trim( (string) $token['name'] )
					? sanitize_text_field( (string) $token['name'] )
					: ucwords( str_replace( '-', ' ', $slug ) ),
				'value' => isset( $token['value'] ) ? (string) $token['value'] : '',
			);
		}

		/*
		 * Reported rather than silently dropped. An agent that proposed a
		 * token which turns out to exist needs to know the site already had
		 * an answer, so it references that slug instead of inventing a
		 * near-duplicate beside it.
		 */
		$missing_keys = array();
		foreach ( Pattern_Builder_Cloud_Tokens::missing( $normalized ) as $token ) {
			$missing_keys[] = $token['type'] . '|' . $token['slug'];
		}

		$skipped = array();
		foreach ( $normalized as $token ) {
			if ( ! in_array( $token['type'] . '|' . $token['slug'], $missing_keys, true ) ) {
				$skipped[] = array(
					'type' => $token['type'],
					'slug' => $token['slug'],
				);
			}
		}

		$written = Pattern_Builder_Cloud_Tokens::apply( $normalized, $destination );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'written'     => $written,
			'skipped'     => $skipped,
			'destination' => $destination,
		);
	}

	/**
	 * How to send bytes, in a shape an agent can act on without being told
	 * twice.
	 *
	 * Returned by `find-media` and `add-asset` alike, because the one thing
	 * an ability cannot do is take a file: abilities are JSON in and JSON out,
	 * so a JPEG would have to be base64 inside that JSON — which means the
	 * agent reading the bytes into its own context and paying for them there.
	 * Naming the route in the *output* of the abilities that deal in media is
	 * what stops it being rediscovered on every task: an agent looking for an
	 * image is told, in the same answer, how to add one.
	 *
	 * @return array
	 */
	private function upload_instructions() {
		return array(
			'note'      => __( 'To add a JPEG, PNG, WebP, AVIF or GIF, POST the file to this route. Abilities cannot carry binary, and this route takes the bytes as the request body — so the file goes straight from disk to the site and never has to be read into your context or base64-encoded.', 'pattern-builder' ),
			'route'     => rest_url( Pattern_Builder_Assets::REST_NAMESPACE . '/assets' ),
			'method'    => 'POST',
			'headers'   => array(
				'Content-Disposition' => 'attachment; filename="hero.webp"',
				'Content-Type'        => __( 'the file\'s own mime type, e.g. image/webp', 'pattern-builder' ),
			),
			'query'     => array(
				'destination' => __( '"theme" (default) writes the active theme\'s assets/images; "media" adds a media library attachment.', 'pattern-builder' ),
				'alt'         => __( 'Alternative text. Recorded on a media library attachment.', 'pattern-builder' ),
			),
			'example'   => 'curl -u "$WP_USER:$WP_APP_PASSWORD" '
				. '-H \'Content-Disposition: attachment; filename="hero.webp"\' '
				. '-H \'Content-Type: image/webp\' '
				. '--data-binary @hero.webp '
				. '"' . rest_url( Pattern_Builder_Assets::REST_NAMESPACE . '/assets' ) . '?destination=theme"',
			'returns'   => __( 'The stored file, with a "reference" holding exactly what to put in the pattern markup — a PHP template tag for a theme asset, a URL for a media library one.', 'pattern-builder' ),
			'limits'    => sprintf(
				/* translators: 1: the longest edge kept, 2: the server's upload limit. */
				__( 'Images over %1$dpx on the longest edge are resized down to it. The server accepts uploads up to %2$s.', 'pattern-builder' ),
				(int) apply_filters( 'pattern_builder_max_asset_dimension', Pattern_Builder_Assets::MAX_DIMENSION ),
				size_format( wp_max_upload_size() )
			),
			'multipart' => __( 'A multipart form works too — the first file field is taken, whatever it is named.', 'pattern-builder' ),
		);
	}

	/**
	 * What this site already has to illustrate a pattern with.
	 *
	 * Two places, because a pattern can reference either and only one of them
	 * has a core route: the media library, and the files already sitting in
	 * the theme's own `assets/images` — which is where every image a theme
	 * pattern points at lives, since saving a theme pattern localises its
	 * images into the theme.
	 */
	private function register_find_media() {
		wp_register_ability(
			'pattern-builder/find-media',
			array(
				'label'               => __( 'Find media', 'pattern-builder' ),
				'description'         => __( 'Lists the images this site can already illustrate a pattern with: media library attachments, and the files in the active theme\'s assets/images directory. Each result carries the exact reference to put in pattern markup. Call this before adding an image — the site usually already has one — and read the "upload" block in the answer for how to add a file that it does not.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Match against title, filename and alt text.',
						),
						'type'     => array(
							'type'        => 'string',
							'description' => 'A mime type or prefix, e.g. "image" (default) or "image/webp". "any" for everything in the media library.',
						),
						'source'   => array(
							'type'        => 'string',
							'enum'        => array( 'all', 'media', 'theme' ),
							'description' => 'Where to look. Defaults to both.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'How many media library items to return. Defaults to 20.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'media'  => array(
							'type'        => 'array',
							'description' => 'Media library attachments.',
							'items'       => array( 'type' => 'object' ),
						),
						'theme'  => array(
							'type'        => 'array',
							'description' => 'Files in the theme\'s assets/images directory.',
							'items'       => array( 'type' => 'object' ),
						),
						'upload' => array(
							'type'        => 'object',
							'description' => 'How to add a file this site does not have.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_find_media' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * List the site's images.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute_find_media( $input = array() ) {
		$found = Pattern_Builder_Assets::find(
			array(
				'search'   => isset( $input['search'] ) ? (string) $input['search'] : '',
				'type'     => isset( $input['type'] ) ? (string) $input['type'] : 'image',
				'source'   => isset( $input['source'] ) ? (string) $input['source'] : 'all',
				'per_page' => isset( $input['per_page'] ) ? (int) $input['per_page'] : 20,
			)
		);

		$found['upload'] = $this->upload_instructions();

		return $found;
	}

	/**
	 * Add an image a pattern needs, in the two forms that fit in JSON.
	 *
	 * An SVG is text, so an agent can author one outright and it arrives
	 * here whole. A URL is a fetch the site performs, which covers everything
	 * already published somewhere. Bytes are the third case and cannot come
	 * this way at all, so the description and the answer both name the route
	 * that takes them.
	 */
	private function register_add_asset() {
		wp_register_ability(
			'pattern-builder/add-asset',
			array(
				'label'               => __( 'Add an asset', 'pattern-builder' ),
				'description'         => __( 'Stores an image for a pattern to reference, either as SVG markup you supply or by fetching a URL, and answers with the exact reference to put in the pattern. Writes to the active theme\'s assets/images, or to the media library. A JPEG, PNG, WebP or AVIF you hold as a file cannot travel through an ability — POST it to /pattern-builder/v1/assets instead, with the bytes as the request body and a Content-Disposition header naming the file; call find-media for the full instructions and an example.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'svg'         => array(
							'type'        => 'string',
							'description' => 'SVG markup. Scripts, external references and event handlers are stripped.',
						),
						'url'         => array(
							'type'        => 'string',
							'description' => 'A URL for this site to fetch. Use for an image already published somewhere.',
						),
						'filename'    => array(
							'type'        => 'string',
							'description' => 'The filename to store under. Required with "svg"; taken from the URL otherwise.',
						),
						'destination' => array(
							'type'        => 'string',
							'enum'        => array( 'theme', 'media' ),
							'description' => '"theme" (default) writes the active theme\'s assets/images, so the file travels with the theme — which is what a theme pattern needs. "media" adds a media library attachment. SVG can only go to the theme, because WordPress does not accept SVG uploads.',
						),
						'alt'         => array(
							'type'        => 'string',
							'description' => 'Alternative text, recorded on a media library attachment.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'destination' => array( 'type' => 'string' ),
						'filename'    => array( 'type' => 'string' ),
						'url'         => array( 'type' => 'string' ),
						'reference'   => array(
							'type'        => 'string',
							'description' => 'What to put in the pattern markup.',
						),
						'width'       => array( 'type' => 'integer' ),
						'height'      => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_add_asset' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						// A second call with the same file stores a second
						// copy rather than replacing the first, so this is
						// not idempotent and must not be marked so.
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Store an SVG or a fetched URL.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_add_asset( $input ) {
		$svg         = isset( $input['svg'] ) ? (string) $input['svg'] : '';
		$url         = isset( $input['url'] ) ? (string) $input['url'] : '';
		$filename    = isset( $input['filename'] ) ? (string) $input['filename'] : '';
		$destination = ( isset( $input['destination'] ) && 'media' === $input['destination'] ) ? 'media' : 'theme';
		$alt         = isset( $input['alt'] ) ? (string) $input['alt'] : '';

		if ( '' === $svg && '' === $url ) {
			return new \WP_Error(
				'pb_asset_nothing_given',
				sprintf(
					/* translators: %s: the upload route. */
					__( 'Give either "svg" markup or a "url" to fetch. To send a file you hold, POST its bytes to %s instead.', 'pattern-builder' ),
					rest_url( Pattern_Builder_Assets::REST_NAMESPACE . '/assets' )
				),
				array( 'status' => 400 )
			);
		}

		if ( '' !== $svg && '' !== $url ) {
			return new \WP_Error(
				'pb_asset_ambiguous',
				__( 'Give "svg" or "url", not both.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( '' !== $url ) {
			return Pattern_Builder_Assets::apply_alt(
				Pattern_Builder_Assets::store_from_url( $url, $destination, $filename ),
				$alt
			);
		}

		if ( '' === $filename ) {
			return new \WP_Error(
				'pb_asset_no_filename',
				__( 'An SVG needs a "filename" to store it under.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		// Give it the extension it is, whatever the caller called it.
		if ( 'svg' !== strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			$filename .= '.svg';
		}

		return Pattern_Builder_Assets::apply_alt(
			Pattern_Builder_Assets::store( $filename, $svg, $destination ),
			$alt
		);
	}

	/**
	 * Draw a placeholder rather than shipping a pattern with no image.
	 *
	 * A pattern under construction needs something in its image slots, and
	 * the alternatives are both bad: a remote placeholder service means the
	 * pattern renders a request to somebody else's server on every view, and
	 * an empty image block reads as broken. This draws an SVG locally, which
	 * costs no bytes over the wire and scales to whatever the layout asks.
	 */
	private function register_add_placeholder_image() {
		wp_register_ability(
			'pattern-builder/add-placeholder-image',
			array(
				'label'               => __( 'Add a placeholder image', 'pattern-builder' ),
				'description'         => __( 'Draws a plain placeholder image at the size you ask for and stores it in the active theme\'s assets/images, answering with the reference to put in the pattern. Use it to fill a pattern\'s image slots without pointing at a remote placeholder service, which would make every page view fetch from somebody else\'s server. The file is an SVG, so it costs nothing to transfer and scales to any layout.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'width'    => array(
							'type'        => 'integer',
							'description' => 'Width in pixels. Defaults to 1200.',
						),
						'height'   => array(
							'type'        => 'integer',
							'description' => 'Height in pixels. Defaults to 800.',
						),
						'label'    => array(
							'type'        => 'string',
							'description' => 'Text drawn in the middle. Defaults to the dimensions.',
						),
						'filename' => array(
							'type'        => 'string',
							'description' => 'Filename to store under. Defaults to placeholder-{width}x{height}.svg.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'filename'  => array( 'type' => 'string' ),
						'url'       => array( 'type' => 'string' ),
						'reference' => array(
							'type'        => 'string',
							'description' => 'What to put in the pattern markup.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_add_placeholder_image' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Draw and store a placeholder.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_add_placeholder_image( $input = array() ) {
		$width  = isset( $input['width'] ) ? (int) $input['width'] : 1200;
		$height = isset( $input['height'] ) ? (int) $input['height'] : 800;

		$svg = Pattern_Builder_Assets::placeholder_svg(
			array(
				'width'  => $width,
				'height' => $height,
				'label'  => isset( $input['label'] ) ? (string) $input['label'] : '',
			)
		);

		$filename = isset( $input['filename'] ) && '' !== (string) $input['filename']
			? (string) $input['filename']
			: 'placeholder-' . $width . 'x' . $height . '.svg';

		if ( 'svg' !== strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			$filename .= '.svg';
		}

		// Theme only: WordPress does not accept SVG in the media library, and
		// a placeholder belongs with the pattern that uses it in any case.
		return Pattern_Builder_Assets::store( $filename, $svg, 'theme' );
	}

	/**
	 * What typefaces can be installed, so `add-font` is not a guess.
	 */
	private function register_list_fonts() {
		wp_register_ability(
			'pattern-builder/list-fonts',
			array(
				'label'               => __( 'List installable fonts', 'pattern-builder' ),
				'description'         => __( 'Lists the font families available to install from the Google Fonts collection WordPress ships, filtered by name or category. Call this to confirm a family exists and how its name is spelled before calling add-font.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Substring of the family name.',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'A category slug: sans-serif, serif, display, handwriting, monospace.',
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'How many to return. Defaults to 20.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'families' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_fonts' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->read_annotations(),
			)
		);
	}

	/**
	 * Search the font collection.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_list_fonts( $input = array() ) {
		$families = Pattern_Builder_Fonts::search(
			isset( $input['search'] ) ? (string) $input['search'] : '',
			isset( $input['category'] ) ? (string) $input['category'] : '',
			isset( $input['limit'] ) ? (int) $input['limit'] : 20
		);

		if ( is_wp_error( $families ) ) {
			return $families;
		}

		return array( 'families' => $families );
	}

	/**
	 * Install a typeface and register it as a preset.
	 *
	 * Both halves matter and only one is obvious. The files make the font
	 * available; the `fontFamily` preset is what actually renders it, since
	 * `wp_print_font_faces()` builds its `@font-face` rules from the merged
	 * theme.json rather than from the font library's own posts. A font
	 * installed without the preset is a font nothing can use.
	 */
	private function register_add_font() {
		wp_register_ability(
			'pattern-builder/add-font',
			array(
				'label'               => __( 'Add a font', 'pattern-builder' ),
				'description'         => __( 'Installs a font family from the Google Fonts collection WordPress ships — the files are copied to this site and served from it, never fetched from Google at render time — and registers it as a fontFamily preset so a pattern can reference it by slug. Writes to the active theme (theme.json plus assets/fonts, so the font travels with the theme) or to this site (Global Styles plus the font library). Call list-fonts first to confirm the family name. Font files can only be installed from the collection this way; to self-host a licensed font you hold, add the files to the theme and register the preset with add-design-tokens.', 'pattern-builder' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'family'      => array(
							'type'        => 'string',
							'description' => 'The family name as the collection lists it, e.g. "Fraunces".',
						),
						'weights'     => array(
							'type'        => 'array',
							'description' => 'Weights to install, e.g. ["400","700"]. Defaults to 400 and 700. A variable font covering the weight is installed once and serves the range.',
							'items'       => array( 'type' => 'string' ),
						),
						'styles'      => array(
							'type'        => 'array',
							'description' => 'Styles to install. Defaults to ["normal"].',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'normal', 'italic' ),
							),
						),
						'destination' => array(
							'type'        => 'string',
							'enum'        => array( 'theme', 'user' ),
							'description' => '"theme" (default) writes theme.json and assets/fonts, so the font is part of the theme; "user" writes Global Styles and the site\'s font library, which stays in the database and is manageable in the editor.',
						),
					),
					'required'             => array( 'family' ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'family'      => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'fontFamily'  => array( 'type' => 'string' ),
						'destination' => array( 'type' => 'string' ),
						'faces'       => array(
							'type'        => 'array',
							'description' => 'The files installed.',
							'items'       => array( 'type' => 'object' ),
						),
						'reference'   => array(
							'type'        => 'object',
							'description' => 'How to reference the font: the block attribute, the class, and the CSS custom property.',
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_add_font' ),
				'permission_callback' => array( $this, 'can_write' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						// Installing the same family twice leaves the same
						// files and the same preset: the preset is never
						// overwritten and a duplicate library face is skipped.
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Install a font family.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_add_font( $input ) {
		$family = isset( $input['family'] ) ? trim( (string) $input['family'] ) : '';

		if ( '' === $family ) {
			return new \WP_Error(
				'pb_font_no_family',
				__( 'Name the font family to install.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		return Pattern_Builder_Fonts::install(
			$family,
			isset( $input['weights'] ) ? (array) $input['weights'] : array(),
			isset( $input['styles'] ) ? (array) $input['styles'] : array(),
			( isset( $input['destination'] ) && 'user' === $input['destination'] ) ? 'user' : 'theme'
		);
	}

	/**
	 * Build constructor args, falling back to an existing pattern's values.
	 *
	 * @param array                 $input    Ability input.
	 * @param Abstract_Pattern|null $existing Pattern being replaced, if any.
	 * @return array
	 */
	private function pattern_args( $input, $existing = null ) {
		$fallback = function ( $key, $fallback_value ) use ( $existing ) {
			if ( $existing && isset( $existing->$key ) ) {
				return $existing->$key;
			}
			return $fallback_value;
		};

		$args = array(
			'title'       => isset( $input['title'] ) ? (string) $input['title'] : $fallback( 'title', '' ),
			'content'     => (string) $input['content'],
			'description' => isset( $input['description'] ) ? (string) $input['description'] : $fallback( 'description', '' ),
			'categories'  => isset( $input['categories'] ) ? array_map( 'sanitize_title', (array) $input['categories'] ) : $fallback( 'categories', array() ),
			'keywords'    => isset( $input['keywords'] ) ? array_map( 'sanitize_text_field', (array) $input['keywords'] ) : $fallback( 'keywords', array() ),
			'synced'      => isset( $input['synced'] ) ? (bool) $input['synced'] : $fallback( 'synced', false ),
			'source'      => 'theme',
		);

		if ( isset( $input['name'] ) && '' !== $input['name'] ) {
			$args['name'] = (string) $input['name'];
		}
		if ( isset( $input['viewportWidth'] ) ) {
			$args['viewportWidth'] = (int) $input['viewportWidth'];
		}

		return $args;
	}

	/**
	 * Find a pattern of either kind by the id an agent supplied.
	 *
	 * @param string $id Namespaced name or post ID.
	 * @return Abstract_Pattern|\WP_Error
	 */
	private function find( $id ) {
		if ( '' === $id ) {
			return new \WP_Error( 'pb_pattern_not_found', __( 'No pattern identifier was given.', 'pattern-builder' ), array( 'status' => 404 ) );
		}

		if ( ctype_digit( $id ) ) {
			$post = get_post( (int) $id );
			if ( $post && 'wp_block' === $post->post_type ) {
				return Abstract_Pattern::from_post( $post );
			}
		}

		$store   = new Pattern_File_Store();
		$pattern = $store->find_theme_pattern( $id );

		if ( ! $pattern ) {
			return new \WP_Error(
				'pb_pattern_not_found',
				/* translators: %s: pattern identifier. */
				sprintf( __( 'No pattern named %s on this site.', 'pattern-builder' ), $id ),
				array( 'status' => 404 )
			);
		}

		return $pattern;
	}

	/**
	 * A pattern as an agent should see it, without its markup.
	 *
	 * @param Abstract_Pattern $pattern Pattern.
	 * @return array
	 */
	private function summarize( $pattern ) {
		return array(
			'id'          => $pattern->id,
			'name'        => $pattern->name,
			'title'       => $pattern->title,
			'description' => $pattern->description,
			'categories'  => is_array( $pattern->categories ) ? $pattern->categories : array(),
			'keywords'    => is_array( $pattern->keywords ) ? $pattern->keywords : array(),
			'source'      => $pattern->source,
			'synced'      => (bool) $pattern->synced,
		);
	}
}
