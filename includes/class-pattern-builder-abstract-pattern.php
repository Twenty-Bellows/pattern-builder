<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- camelCase properties intentionally mirror the JS AbstractPattern class.

namespace TwentyBellows\PatternBuilder;

/**
 * Value object representing a single block pattern.
 *
 * Property names intentionally use camelCase to mirror the JavaScript AbstractPattern class,
 * keeping PHP and JS representations symmetrical and reducing mapping friction.
 */
class Abstract_Pattern {

	/**
	 * Post ID (tbell_pattern_block or wp_block).
	 *
	 * @var int|null
	 */
	public $id;

	/**
	 * Pattern slug (namespaced, e.g. "theme-slug/pattern-name").
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Human-readable pattern title.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Short description shown in the inserter.
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Raw block markup content.
	 *
	 * @var string
	 */
	public $content;

	/**
	 * Array of category slugs.
	 *
	 * @var array
	 */
	public $categories;

	/**
	 * Array of keyword strings.
	 *
	 * @var array
	 */
	public $keywords;

	/**
	 * Array of block type slugs this pattern applies to.
	 *
	 * @var array
	 */
	public $blockTypes; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Array of template type slugs this pattern applies to.
	 *
	 * @var array
	 */
	public $templateTypes; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Array of post type slugs this pattern applies to.
	 *
	 * @var array
	 */
	public $postTypes; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Optional viewport width (in pixels) used for pattern preview rendering.
	 *
	 * @var int|null
	 */
	public $viewportWidth; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Pattern source: 'theme' or 'user'.
	 *
	 * @var string
	 */
	public $source;

	/**
	 * Whether the pattern is synced.
	 *
	 * @var bool
	 */
	public $synced;

	/**
	 * Whether the pattern appears in the block inserter.
	 *
	 * @var bool
	 */
	public $inserter;

	/**
	 * Absolute filesystem path to the pattern PHP file (theme patterns only).
	 *
	 * @var string|null
	 */
	public $filePath; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Constructor.
	 *
	 * @param array $args Pattern arguments.
	 */
	public function __construct( $args = array() ) {
		$this->id = $args['id'] ?? null;

		$this->title = $args['title'];

		$this->name = $args['name'] ?? sanitize_title( $args['title'] );

		$this->description = $args['description'] ?? '';
		$this->content     = $args['content'] ?? '';

		$this->source   = $args['source'] ?? 'theme';
		$this->synced   = $args['synced'] ?? false;
		$this->inserter = $args['inserter'] ?? true;

		$this->categories = $args['categories'] ?? array();
		$this->keywords   = $args['keywords'] ?? array();

		$this->blockTypes    = $args['blockTypes'] ?? array(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$this->templateTypes = $args['templateTypes'] ?? array(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$this->postTypes     = $args['postTypes'] ?? array(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		$viewport_width      = $args['viewportWidth'] ?? null;
		$this->viewportWidth = is_numeric( $viewport_width ) ? (int) $viewport_width : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		$this->filePath = $args['filePath'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}

	/**
	 * Renders a pattern PHP file using output buffering.
	 *
	 * @param string $pattern_file Absolute path to the pattern file.
	 * @return string Rendered pattern content.
	 */
	private static function render_pattern( $pattern_file ) {
		ob_start();
		include $pattern_file;
		return ob_get_clean();
	}

	/**
	 * Creates an Abstract_Pattern from a theme pattern PHP file.
	 *
	 * @param string $pattern_file Absolute path to the pattern file.
	 * @return self
	 */
	public static function from_file( $pattern_file ) {
		$pattern_data = get_file_data(
			$pattern_file,
			array(
				'title'         => 'Title',
				'slug'          => 'Slug',
				'description'   => 'Description',
				'viewportWidth' => 'Viewport Width',
				'inserter'      => 'Inserter',
				'categories'    => 'Categories',
				'keywords'      => 'Keywords',
				'blockTypes'    => 'Block Types',
				'postTypes'     => 'Post Types',
				'templateTypes' => 'Template Types',
				'synced'        => 'Synced',
			)
		);

		$new = new self(
			array(
				'name'          => $pattern_data['slug'],
				'title'         => $pattern_data['title'],
				'description'   => $pattern_data['description'],
				'content'       => self::render_pattern( $pattern_file ),
				'filePath'      => $pattern_file,
				'categories'    => '' === $pattern_data['categories'] ? array() : array_map( 'trim', explode( ',', $pattern_data['categories'] ) ),
				'keywords'      => '' === $pattern_data['keywords'] ? array() : array_map( 'trim', explode( ',', $pattern_data['keywords'] ) ),
				'blockTypes'    => '' === $pattern_data['blockTypes'] ? array() : array_map( 'trim', explode( ',', $pattern_data['blockTypes'] ) ),
				'postTypes'     => '' === $pattern_data['postTypes'] ? array() : array_map( 'trim', explode( ',', $pattern_data['postTypes'] ) ),
				'templateTypes' => '' === $pattern_data['templateTypes'] ? array() : array_map( 'trim', explode( ',', $pattern_data['templateTypes'] ) ),
				'viewportWidth' => $pattern_data['viewportWidth'],
				'source'        => 'theme',
				'synced'        => 'yes' === $pattern_data['synced'],
				'inserter'      => 'no' !== $pattern_data['inserter'],
			)
		);

		return $new;
	}

	/**
	 * Creates an Abstract_Pattern from a registered block pattern array.
	 *
	 * @param array $pattern The registered pattern array from WP_Block_Patterns_Registry.
	 * @return self
	 */
	public static function from_registry( $pattern ) {
		return new self(
			array(
				'name'          => $pattern['name'],
				'title'         => $pattern['title'],
				'description'   => $pattern['description'],
				'content'       => $pattern['content'],
				'categories'    => $pattern['categories'],
				'keywords'      => $pattern['keywords'],
				'source'        => 'theme',
				'synced'        => false,
				'blockTypes'    => $pattern['blockTypes'],
				'templateTypes' => $pattern['templateTypes'],
				'postTypes'     => $pattern['postTypes'],
				'inserter'      => $pattern['inserter'],
				'filePath'      => $pattern['filePath'],
			)
		);
	}

	/**
	 * Creates an Abstract_Pattern from a WP_Post object (wp_block or tbell_pattern_block).
	 *
	 * @param \WP_Post $post The post object.
	 * @return self
	 */
	public static function from_post( $post ) {
		$metadata   = get_post_meta( $post->ID );
		$categories = wp_get_object_terms( $post->ID, 'wp_pattern_category' );
		$categories = array_map(
			function ( $category ) {
				return $category->slug;
			},
			$categories
		);

		$slug = Pattern_Builder_Controller::format_pattern_slug_from_post( $post->post_name );

		return new self(
			array(
				'id'            => $post->ID,
				'name'          => $slug,
				'title'         => $post->post_title,
				'description'   => $post->post_excerpt,
				'content'       => $post->post_content,
				'source'        => ( 'tbell_pattern_block' === $post->post_type ) ? 'theme' : 'user',
				'synced'        => ( $metadata['wp_pattern_sync_status'][0] ?? 'synced' ) !== 'unsynced',

				'blockTypes'    => isset( $metadata['wp_pattern_block_types'][0] ) ? explode( ',', $metadata['wp_pattern_block_types'][0] ) : array(),
				'templateTypes' => isset( $metadata['wp_pattern_template_types'][0] ) ? explode( ',', $metadata['wp_pattern_template_types'][0] ) : array(),
				'postTypes'     => isset( $metadata['wp_pattern_post_types'][0] ) ? explode( ',', $metadata['wp_pattern_post_types'][0] ) : array(),

				'keywords'      => isset( $metadata['wp_pattern_keywords'][0] ) ? explode( ',', $metadata['wp_pattern_keywords'][0] ) : array(),
				'categories'    => $categories,
				'inserter'      => isset( $metadata['wp_pattern_inserter'][0] ) ? ( 'no' !== $metadata['wp_pattern_inserter'][0] ) : true,
			)
		);
	}
}
