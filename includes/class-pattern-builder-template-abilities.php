<?php
/**
 * Abilities for the theme's templates and template parts.
 *
 * @package PatternBuilder
 */

namespace TwentyBellows\PatternBuilder;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What an agent can read of the theme's templates and template parts, and what it can ask
 * this site to write, change or remove.
 */
class Pattern_Builder_Template_Abilities {
	/**
	 * Hook the component into WordPress.
	 */
	public function __construct() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * The names of every ability registered here.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array(
			'pattern-builder/list-templates',
			'pattern-builder/get-template',
			'pattern-builder/create-template',
			'pattern-builder/update-template',
			'pattern-builder/delete-template',
		);
	}

	/**
	 * Register every ability.
	 */
	public function register_abilities() {
		$this->register_list_templates();
		$this->register_get_template();
		$this->register_create_template();
		$this->register_update_template();
		$this->register_delete_template();
	}

	/**
	 * Templates are the theme's structure, which core's own templates endpoint keeps to
	 * whoever may edit the theme, for reading as well as writing.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Register one ability, its description ending with the guide to read before the first
	 * call when it has one.
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability arguments.
	 * @param array  $annotations readonly, destructive and idempotent, which select the HTTP
	 * method: readonly is GET, destructive and idempotent together are DELETE, anything else
	 * POST.
	 */
	private function register( $name, $args, $annotations ) {
		if ( isset( Pattern_Builder_Abilities::GUIDES[ $name ] ) ) {
			$args['description'] .= ' ' . Pattern_Builder_Abilities::guide_line( Pattern_Builder_Abilities::GUIDES[ $name ] );
		}

		$args['category']            = Pattern_Builder_Abilities::CATEGORY;
		$args['permission_callback'] = array( $this, 'can_manage' );
		$args['meta']                = array(
			'show_in_rest' => true,
			'annotations'  => array_merge( $annotations, array( 'openWorldHint' => false ) ),
			'mcp'          => array( 'public' => true ),
		);

		wp_register_ability( $name, $args );
	}

	/**
	 * The input every ability takes to name one template.
	 *
	 * @return array
	 */
	private function identity_properties() {
		return array(
			'type' => array(
				'type'        => 'string',
				'enum'        => array_keys( Pattern_Builder_Template_Store::POST_TYPES ),
				'description' => '"template" for a whole-page template (templates/*.html), "template-part" for a part a template places, such as a header or footer (parts/*.html).',
			),
			'slug' => array(
				'type'        => 'string',
				'description' => 'The template\'s slug, which is its filename without .html: "single", "front-page", "header".',
				'minLength'   => 1,
			),
		);
	}

	/**
	 * The metadata a write may set, which lives in theme.json beside the file.
	 *
	 * @return array
	 */
	private function metadata_properties() {
		return array(
			'title'     => array(
				'type'        => 'string',
				'description' => 'A title for a custom template or a part, as the Site Editor lists it. WordPress\'s own template types (index, single, page, archive and the rest of the hierarchy) keep the title WordPress gives them.',
			),
			'area'      => array(
				'type'        => 'string',
				'enum'        => array_values( wp_list_pluck( get_allowed_block_template_part_areas(), 'area' ) ),
				'description' => 'Template parts only: the area the part fills, which is how the Site Editor groups it and which patterns it offers for it. "header" and "footer" are the usual ones; "uncategorized" is any other.',
			),
			'postTypes' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Custom templates only: the post types an editor can pick this template for, e.g. ["page"]. An empty list makes it available to every post type.',
			),
		);
	}

	/**
	 * The output schema of a template summary.
	 *
	 * @return array
	 */
	private function template_schema() {
		return array(
			'type'        => 'object',
			'description' => 'A template: type, slug, title, description, area (parts) or postTypes (custom templates), and where what renders comes from. source is "theme" for a theme file, "custom" for a Site Editor customization saved in the database, which renders in place of the file, or "plugin" for one a plugin registered. theme names the theme the file is in, the active one or its parent; path is the file inside it; hasThemeFile and customized say which of the two exist. hash pins a later update-template or delete-template to this version.',
		);
	}

	/**
	 * List every template or template part.
	 */
	private function register_list_templates() {
		$this->register(
			'pattern-builder/list-templates',
			array(
				'label'            => __( 'List templates', 'pattern-builder' ),
				'description'      => __( 'Returns the templates and template parts that resolve on this site, the way WordPress resolves them: a Site Editor customization saved in the database renders in place of the theme\'s file, a child theme\'s file in place of its parent\'s, and a template a plugin registered when the theme has none. Each says which of those it is. Templates are listed without their markup; read one with get-template. Listing template parts also returns the areas a part can fill.', 'pattern-builder' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'type' => array(
							'type'        => 'string',
							'enum'        => array_keys( Pattern_Builder_Template_Store::POST_TYPES ),
							'description' => 'Only templates, or only template parts. Both when omitted.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'templates' => array(
							'type'  => 'array',
							'items' => $this->template_schema(),
						),
						'areas'     => array(
							'type'        => 'array',
							'description' => 'The areas a template part can fill, each with area, label and description.',
							'items'       => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback' => array( $this, 'execute_list_templates' ),
			),
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * List templates.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute_list_templates( $input = array() ) {
		$store = new Pattern_Builder_Template_Store();
		$types = isset( $input['type'] ) ? array( (string) $input['type'] ) : array_keys( Pattern_Builder_Template_Store::POST_TYPES );

		$templates = array();
		foreach ( $types as $type ) {
			foreach ( $store->all( $type ) as $record ) {
				$templates[] = $record;
			}
		}

		$answer = array( 'templates' => $templates );
		if ( in_array( 'template-part', $types, true ) ) {
			$answer['areas'] = array();
			foreach ( get_allowed_block_template_part_areas() as $area ) {
				$answer['areas'][] = array(
					'area'        => $area['area'],
					'label'       => $area['label'],
					'description' => $area['description'],
				);
			}
		}

		return $answer;
	}

	/**
	 * Read one template with its markup.
	 */
	private function register_get_template() {
		$this->register(
			'pattern-builder/get-template',
			array(
				'label'            => __( 'Get a template', 'pattern-builder' ),
				'description'      => __( 'Returns one template or template part with its markup, as it renders: a Site Editor customization when there is one, otherwise the theme file as it is on disk, otherwise a plugin\'s. The answer carries a hash of everything update-template replaces: send it back as update-template\'s or delete-template\'s expectedHash, and the write is refused if the template changed after you read it.', 'pattern-builder' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => $this->identity_properties(),
					'required'             => array( 'type', 'slug' ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'template' => $this->template_schema(),
					),
				),
				'execute_callback' => array( $this, 'execute_get_template' ),
			),
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Read a template.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_get_template( $input ) {
		$type   = (string) $input['type'];
		$slug   = (string) $input['slug'];
		$record = ( new Pattern_Builder_Template_Store() )->find( $type, $slug );

		if ( null === $record ) {
			return Pattern_Builder_Template_Store::not_found( $type, $slug );
		}

		$record['hash'] = self::hash( $record );

		return array( 'template' => $record );
	}

	/**
	 * Write a new template file.
	 */
	private function register_create_template() {
		$this->register(
			'pattern-builder/create-template',
			array(
				'label'            => __( 'Create a template', 'pattern-builder' ),
				'description'      => __( 'Writes finished block markup into the active theme as a new template (templates/{slug}.html) or template part (parts/{slug}.html). A slug from WordPress\'s template hierarchy, such as "single", "page-about" or "archive-product", is used wherever the hierarchy reaches it; any other slug is a custom template an editor picks for a page. If the parent theme or a plugin already provides the slug, the new file takes its place, and the answer says what it replaced. Supply markup you have already validated with a JavaScript block validator, since no server can run a block\'s save(). What the site does refuse, by name, is what PHP can see: everything create-pattern refuses, PHP of any kind, and a core/template-part that resolves to nothing, places itself or names another theme. Store a part before the templates that place it. The answer carries warnings for what will not travel with the theme: a menu or synced pattern referenced by its database ID, and images placed in the template rather than in a pattern.', 'pattern-builder' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						$this->identity_properties(),
						array(
							'content' => array(
								'type'        => 'string',
								'description' => 'The template\'s block markup, complete and already validated.',
								'minLength'   => 1,
							),
						),
						$this->metadata_properties()
					),
					'required'             => array( 'type', 'slug', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'template' => $this->template_schema(),
						'replaces' => array(
							'type'        => 'object',
							'description' => 'What rendered for this slug before, which the new file now takes the place of: a parent theme\'s template or a plugin\'s. Absent when nothing did.',
						),
						'warnings' => $this->warnings_schema(),
					),
				),
				'execute_callback' => array( $this, 'execute_create_template' ),
			),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * Create a template.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_create_template( $input ) {
		$type    = (string) $input['type'];
		$slug    = (string) $input['slug'];
		$content = (string) $input['content'];
		$store   = new Pattern_Builder_Template_Store();

		$before = $store->find( $type, $slug );
		if ( null !== $before && ( $before['customized'] || ( $before['hasThemeFile'] && get_stylesheet() === $before['theme'] ) ) ) {
			return new WP_Error(
				'pb_template_exists',
				sprintf(
					/* translators: %s: template slug. */
					__( 'The active theme already has %s. Read it with get-template and change it with update-template.', 'pattern-builder' ),
					$slug
				),
				array( 'status' => 409 )
			);
		}

		$checked = Pattern_Builder_Template_Checks::check( $type, $slug, $content );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$saved = $store->save( $type, $slug, $content, $input );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$answer = array( 'template' => $this->summary( $saved['template'] ) );
		if ( null !== $before ) {
			unset( $before['content'] );
			$answer['replaces'] = $before;
		}

		return $this->with_warnings( $answer, array_merge( $saved['warnings'], Pattern_Builder_Template_Checks::warnings( $content ) ) );
	}

	/**
	 * Replace a template's markup or metadata.
	 */
	private function register_update_template() {
		$this->register(
			'pattern-builder/update-template',
			array(
				'label'            => __( 'Update a template', 'pattern-builder' ),
				'description'      => __( 'Replaces a template\'s or template part\'s markup, and its title, area or post types when given, writing the result into the active theme\'s file. Read it with get-template first and send that hash as expectedHash: if it changed after you read it, nothing is written and the answer carries the current hash. To change part of it, send edits instead of content: fragments of the markup get-template returned, each replaced in place, all or none. When what you read was a Site Editor customization, the file takes it over and the customization is removed, as the Site Editor\'s reset removes it, so the template renders from the theme from then on; the answer says so as folded. Updating a template that comes from the parent theme writes the active theme\'s own copy. A template only a plugin provides is the plugin\'s: give the theme its own with create-template. The same checks as create-template refuse what PHP can see, and the same warnings come back.', 'pattern-builder' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						$this->identity_properties(),
						array(
							'content'      => array(
								'type'        => 'string',
								'description' => 'The whole new markup, complete and already validated.',
								'minLength'   => 1,
							),
							'edits'        => array(
								'type'        => 'array',
								'description' => 'Change part of the markup instead of sending all of it as content: each edit replaces old with new, in order, on one copy of the markup get-template returned. Each old must match exactly once unless replaceAll is set; a fragment that matches nothing, or more than once, refuses the whole write. Send content or edits, never both, and send expectedHash with edits.',
								'items'       => array(
									'type'                 => 'object',
									'properties'           => array(
										'old'        => array(
											'type'      => 'string',
											'minLength' => 1,
										),
										'new'        => array( 'type' => 'string' ),
										'replaceAll' => array( 'type' => 'boolean' ),
									),
									'required'             => array( 'old', 'new' ),
									'additionalProperties' => false,
								),
							),
							'expectedHash' => array(
								'type'        => 'string',
								'description' => 'The hash get-template returned for the version you read. Optional with content, required with edits; without it, a change made after your read is overwritten without a word.',
							),
						),
						$this->metadata_properties()
					),
					'required'             => array( 'type', 'slug' ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'template' => $this->template_schema(),
						'changed'  => array(
							'type'        => 'boolean',
							'description' => 'False when this write left the template exactly as it was, which usually means the change you meant did not happen. Folding a customization into the file, or writing the active theme\'s own copy of a parent\'s template, counts as a change.',
						),
						'folded'   => array(
							'type'        => 'boolean',
							'description' => 'True when a Site Editor customization was folded into the file and removed.',
						),
						'warnings' => $this->warnings_schema(),
					),
				),
				'execute_callback' => array( $this, 'execute_update_template' ),
			),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Update a template.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_update_template( $input ) {
		$type     = (string) $input['type'];
		$slug     = (string) $input['slug'];
		$store    = new Pattern_Builder_Template_Store();
		$existing = $store->find( $type, $slug );

		if ( null === $existing ) {
			return Pattern_Builder_Template_Store::not_found( $type, $slug );
		}

		if ( 'plugin' === $existing['source'] ) {
			return new WP_Error(
				'pb_template_is_read_only',
				sprintf(
					/* translators: %s: template slug. */
					__( '%s is registered by a plugin, which owns it, and nothing is written back to a plugin. Give the theme its own with create-template: a theme file of the same slug renders in its place.', 'pattern-builder' ),
					$slug
				),
				array( 'status' => 409 )
			);
		}

		$before  = self::hash( $existing );
		$refused = self::refuse_if_changed( $before, $input );
		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		$content = $this->content_to_write( $input, $existing );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$checked = Pattern_Builder_Template_Checks::check( $type, $slug, $content );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$saved = $store->save( $type, $slug, $content, $input );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$summary  = $this->summary( $saved['template'] );
		$warnings = $saved['warnings'];
		// Markup this write left alone is not this write's to judge.
		if ( (string) $saved['template']['content'] !== (string) $existing['content'] ) {
			$warnings = array_merge( $warnings, Pattern_Builder_Template_Checks::warnings( $content ) );
		}

		return $this->with_warnings(
			array(
				'template' => $summary,
				'changed'  => $before !== $summary['hash'],
				'folded'   => $saved['folded'],
			),
			$warnings
		);
	}

	/**
	 * Remove a template or template part.
	 */
	private function register_delete_template() {
		$this->register(
			'pattern-builder/delete-template',
			array(
				'label'            => __( 'Delete a template', 'pattern-builder' ),
				'description'      => __( 'Removes a template or template part from the active theme: its file, any Site Editor customization of it, and its entry in theme.json. What renders afterwards is whatever is left (the parent theme\'s file of the same slug, a plugin\'s, or nothing, in which case WordPress falls back through the template hierarchy) and the answer says which. Refused for the index template when the theme has no other, since a theme without templates/index.html is no longer a block theme; for a template that only the parent theme or a plugin provides; and for a template part that templates, other parts or patterns still place, which are listed so you can change them first. Send the hash get-template returned as expectedHash to be sure you remove the version you read.', 'pattern-builder' ),
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						$this->identity_properties(),
						array(
							'expectedHash' => array(
								'type'        => 'string',
								'description' => 'The hash get-template returned. When the template no longer matches it, nothing is removed.',
							),
						)
					),
					'required'             => array( 'type', 'slug' ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'removed' => array(
							'type'        => 'object',
							'description' => 'file: the theme file removed, or "" when there was none of the active theme\'s own; customization: whether a Site Editor customization was removed.',
						),
						'now'     => array(
							'type'        => array( 'object', 'null' ),
							'description' => 'What renders for this slug now, from the parent theme or a plugin, or null when nothing does.',
						),
					),
				),
				'execute_callback' => array( $this, 'execute_delete_template' ),
			),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Delete a template.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public function execute_delete_template( $input ) {
		$type     = (string) $input['type'];
		$slug     = (string) $input['slug'];
		$store    = new Pattern_Builder_Template_Store();
		$existing = $store->find( $type, $slug );

		if ( null === $existing ) {
			return Pattern_Builder_Template_Store::not_found( $type, $slug );
		}

		$refused = self::refuse_if_changed( self::hash( $existing ), $input );
		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		// Only a removal that leaves the slug resolving to nothing strands the places using it.
		$owns = $existing['customized'] || ( $existing['hasThemeFile'] && get_stylesheet() === $existing['theme'] );
		if ( 'template-part' === $type && $owns && ! $store->has_fallback( $type, $slug ) ) {
			$places = $this->places_using_part( $store, $slug );
			if ( $places ) {
				return new WP_Error(
					'pb_template_part_in_use',
					sprintf(
						/* translators: 1: part slug, 2: the places that use it, comma separated. */
						__( 'The %1$s part is still placed by %2$s, and a reference to a part that is gone renders as nothing. Change those first.', 'pattern-builder' ),
						$slug,
						implode( ', ', wp_list_pluck( $places, 'name' ) )
					),
					array(
						'status' => 409,
						'usedBy' => $places,
					)
				);
			}
		}

		$deleted = $store->delete( $type, $slug );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		if ( null !== $deleted['now'] ) {
			unset( $deleted['now']['content'] );
		}

		return $deleted;
	}

	/**
	 * Every template, other part and theme pattern that places a part.
	 *
	 * @param Pattern_Builder_Template_Store $store The store.
	 * @param string                         $slug Part slug.
	 * @return array[] Each with kind and name.
	 */
	private function places_using_part( $store, $slug ) {
		$places = array();

		foreach ( array_keys( Pattern_Builder_Template_Store::POST_TYPES ) as $type ) {
			foreach ( array_keys( $store->all( $type ) ) as $other ) {
				if ( 'template-part' === $type && $other === $slug ) {
					continue;
				}

				$record = $store->find( $type, $other );
				if ( null !== $record && self::places_part( $record['content'], $slug ) ) {
					$places[] = array(
						'kind' => $type,
						'name' => $other,
					);
				}
			}
		}

		foreach ( ( new Pattern_File_Store() )->get_theme_patterns() as $pattern ) {
			if ( self::places_part( (string) $pattern->content, $slug ) ) {
				$places[] = array(
					'kind' => 'pattern',
					'name' => (string) $pattern->name,
				);
			}
		}

		return $places;
	}

	/**
	 * Whether markup places a part of the active theme.
	 *
	 * @param string $markup Block markup.
	 * @param string $slug Part slug.
	 * @return bool
	 */
	private static function places_part( $markup, $slug ) {
		if ( false === strpos( (string) $markup, 'template-part' ) ) {
			return false;
		}

		$blocks = parse_blocks( (string) $markup );
		while ( $blocks ) {
			$block = array_shift( $blocks );
			if ( 'core/template-part' === $block['blockName'] ) {
				$attrs = is_array( $block['attrs'] ) ? $block['attrs'] : array();
				if ( isset( $attrs['slug'] ) && $slug === $attrs['slug'] && ( ! isset( $attrs['theme'] ) || get_stylesheet() === $attrs['theme'] ) ) {
					return true;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks = array_merge( $blocks, $block['innerBlocks'] );
			}
		}

		return false;
	}

	/**
	 * The markup a write stores: content as sent, or edits applied to what is there.
	 *
	 * @param array $input Ability input.
	 * @param array $existing The template as it is.
	 * @return string|WP_Error
	 */
	private function content_to_write( $input, $existing ) {
		$has_content = isset( $input['content'] ) && '' !== (string) $input['content'];
		$has_edits   = ! empty( $input['edits'] );

		if ( $has_content && $has_edits ) {
			return new WP_Error(
				'pb_content_and_edits',
				__( 'Send the whole markup as content or change part of it with edits, not both: nothing was written.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_content ) {
			return (string) $input['content'];
		}

		if ( ! $has_edits ) {
			// Metadata alone is a write of the markup that is already there.
			return (string) $existing['content'];
		}

		if ( ! isset( $input['expectedHash'] ) || '' === (string) $input['expectedHash'] ) {
			return new WP_Error(
				'pb_edits_need_a_hash',
				__( 'Edits need expectedHash, the hash get-template returned with the markup they were cut from: a fragment only means something against that version. Nothing was written.', 'pattern-builder' ),
				array( 'status' => 400 )
			);
		}

		return Pattern_Builder_Abilities::apply_edits( (string) $existing['content'], (array) $input['edits'] );
	}

	/**
	 * The refusal for a write pinned to a version that is no longer there.
	 *
	 * @param string $current The template's hash now.
	 * @param array  $input Ability input.
	 * @return true|WP_Error
	 */
	private static function refuse_if_changed( $current, $input ) {
		if ( ! isset( $input['expectedHash'] ) || '' === (string) $input['expectedHash'] || hash_equals( $current, (string) $input['expectedHash'] ) ) {
			return true;
		}

		return new WP_Error(
			'pb_template_changed',
			__( 'This template has changed since the version you read, so nothing was written. Read it again with get-template, make your change on what is there now, and send that hash as expectedHash.', 'pattern-builder' ),
			array(
				'status'      => 409,
				'currentHash' => $current,
			)
		);
	}

	/**
	 * A fingerprint of everything that renders and everything update-template replaces, so a
	 * customization saved, folded or reset in between changes it too.
	 *
	 * @param array $record The template, with content.
	 * @return string
	 */
	public static function hash( $record ) {
		$fields = array();
		foreach ( array( 'type', 'slug', 'source', 'theme', 'title', 'area', 'postTypes', 'content' ) as $key ) {
			$fields[ $key ] = isset( $record[ $key ] ) ? $record[ $key ] : null;
		}

		return hash( 'sha256', (string) wp_json_encode( $fields ) );
	}

	/**
	 * A stored template as a write answers with it: no markup, and its hash.
	 *
	 * @param array $record The template, with content.
	 * @return array
	 */
	private function summary( $record ) {
		$record['hash'] = self::hash( $record );
		unset( $record['content'] );

		return $record;
	}

	/**
	 * Add warnings to a write's answer, when there are any.
	 *
	 * @param array $answer The answer.
	 * @param array $warnings List of { code, message }.
	 * @return array
	 */
	private function with_warnings( $answer, $warnings ) {
		if ( $warnings ) {
			$answer['warnings'] = $warnings;
		}

		return $answer;
	}

	/**
	 * The output schema the writes share for warnings.
	 *
	 * @return array
	 */
	private function warnings_schema() {
		return array(
			'type'        => 'array',
			'description' => 'Things worth fixing that did not stop the write, each with a code and a message: a menu or synced pattern referenced by its database ID, images placed in the template rather than in a pattern, markup that will not lay out as written, and metadata that could not be recorded in theme.json.',
			'items'       => array( 'type' => 'object' ),
		);
	}
}
