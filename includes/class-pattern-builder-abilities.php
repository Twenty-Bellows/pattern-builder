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
		$this->register_create_pattern();
		$this->register_update_pattern();
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
