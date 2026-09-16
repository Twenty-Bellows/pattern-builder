<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- camelCase properties intentionally mirror the JS AbstractPattern class.

namespace TwentyBellows\PatternBuilder;

/**
 * Value object representing a single block pattern.
 */
class Abstract_Pattern {
	/**
	 * The headers a pattern file's comment block carries: the property each one sets,
	 * keyed to the name `get_file_data()` looks for. Every other line of that comment is
	 * kept verbatim in `$additionalMetadata`, so the two cannot drift apart.
	 */
	const FILE_HEADERS = array(
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
		'origin'        => 'Origin',
		'cloud'         => 'Cloud',
	);

	/**
	 * Pattern identity.
	 *
	 * @var string|int|null
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
	 * Intended viewport width when previewing the pattern, in pixels.
	 *
	 * @var int|null
	 */
	public $viewportWidth; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Absolute filesystem path to the pattern PHP file (theme patterns only).
	 *
	 * @var string|null
	 */
	public $filePath; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * The cloud pattern this one was first copied from, or '' when it is original work
	 * here.
	 *
	 * @var string
	 */
	public $origin;

	/**
	 * The name of this pattern's copy on the cloud — `{handle}/{collection}/{slug}` — or ''
	 * when it has none.
	 *
	 * @var string
	 */
	public $cloud;

	/**
	 * The lines of the pattern file's header comment that are not headers this class
	 * reads — the `@package`/`@subpackage`/`@since` block a theme conventionally carries,
	 * a paragraph explaining what the file is for — kept verbatim so that writing the
	 * pattern back does not discard them. Always '' for a user pattern, which has no file.
	 *
	 * @var string
	 */
	public $additionalMetadata; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Constructor.
	 *
	 * @param array $args Pattern arguments.
	 */
	public function __construct( $args = array() ) {
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

		$this->viewportWidth = isset( $args['viewportWidth'] ) && '' !== $args['viewportWidth'] ? (int) $args['viewportWidth'] : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		$this->filePath           = $args['filePath'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$this->origin             = $args['origin'] ?? '';
		$this->cloud              = $args['cloud'] ?? '';
		$this->additionalMetadata = (string) ( $args['additionalMetadata'] ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		$this->id = $args['id'] ?? ( 'theme' === $this->source ? $this->name : null );
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
	 * Splits a comma-separated header value into a trimmed array.
	 *
	 * @param string $value Raw header value.
	 * @return array List of trimmed, non-empty values.
	 */
	private static function split_header_list( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
	}

	/**
	 * Creates an Abstract_Pattern from a theme pattern PHP file.
	 *
	 * @param string $pattern_file Absolute path to the pattern file.
	 * @return self
	 */
	public static function from_file( $pattern_file ) {
		$pattern_data = get_file_data( $pattern_file, self::FILE_HEADERS );

		return new self(
			array(
				'name'               => $pattern_data['slug'],
				'title'              => $pattern_data['title'],
				'description'        => $pattern_data['description'],
				'content'            => self::render_pattern( $pattern_file ),
				'filePath'           => $pattern_file,
				'categories'         => self::split_header_list( $pattern_data['categories'] ),
				'keywords'           => self::split_header_list( $pattern_data['keywords'] ),
				'blockTypes'         => self::split_header_list( $pattern_data['blockTypes'] ),
				'postTypes'          => self::split_header_list( $pattern_data['postTypes'] ),
				'templateTypes'      => self::split_header_list( $pattern_data['templateTypes'] ),
				'viewportWidth'      => $pattern_data['viewportWidth'],
				'source'             => 'theme',
				'synced'             => in_array( strtolower( trim( $pattern_data['synced'] ) ), array( 'yes', 'true', '1', 'on' ), true ),
				'inserter'           => 'no' !== strtolower( trim( $pattern_data['inserter'] ) ),
				'origin'             => trim( $pattern_data['origin'] ),
				'cloud'              => trim( $pattern_data['cloud'] ),
				'additionalMetadata' => self::additional_metadata_from_file( $pattern_file ),
			)
		);
	}

	/**
	 * Whether a line of a pattern file's header comment sets one of the headers
	 * `from_file()` reads, and so belongs to a field of its own rather than to
	 * `$additionalMetadata`.
	 *
	 * The tolerated prefix and the case-insensitivity mirror `get_file_data()` exactly, so
	 * a line this answers false for is a line core will not read as a header either.
	 *
	 * @param string $line One line of the comment.
	 * @return bool
	 */
	public static function is_recognised_header( $line ) {
		foreach ( self::FILE_HEADERS as $header ) {
			if ( preg_match( '/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':/i', $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads the part of a pattern file's header comment that is not a recognised header.
	 *
	 * `get_file_data()` only extracts headers it is asked for by name, so the rest of that
	 * comment is invisible to it, and rebuilding the file from the pattern would drop it.
	 * Themes conventionally carry a `@package`/`@subpackage`/`@since` block there, and a
	 * hand-written pattern often carries a note beside it.
	 *
	 * @param string $pattern_file Absolute path to the pattern file.
	 * @return string The remaining lines with their leading `*` stripped, or '' if none.
	 */
	private static function additional_metadata_from_file( $pattern_file ) {
		// The same window, and the same line-ending fix, that get_file_data() applies, so
		// that the two always read the same header.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a theme's own pattern file.
		$head = file_get_contents( $pattern_file, false, null, 0, 8 * KB_IN_BYTES );

		if ( false === $head || ! preg_match( '/\/\*\*(.*?)\*\//s', str_replace( "\r", "\n", $head ), $comment ) ) {
			return '';
		}

		$lines = array();

		foreach ( explode( "\n", $comment[1] ) as $line ) {
			$line = rtrim( preg_replace( '/^\s*\*\s?/', '', $line ) );

			if ( ! self::is_recognised_header( $line ) ) {
				$lines[] = $line;
			}
		}

		// Blank lines within the block are the author's paragraph breaks and are kept; the
		// ones the opening and closing lines leave behind are not.
		return trim( implode( "\n", $lines ), "\n" );
	}

	/**
	 * Creates an Abstract_Pattern from a wp_block post.
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
			is_array( $categories ) ? $categories : array()
		);

		return new self(
			array(
				'id'          => $post->ID,
				'name'        => $post->post_name,
				'title'       => $post->post_title,
				'description' => $post->post_excerpt,
				'content'     => $post->post_content,
				'source'      => 'user',
				'synced'      => ( $metadata['wp_pattern_sync_status'][0] ?? 'synced' ) !== 'unsynced',
				'keywords'    => isset( $metadata['wp_pattern_keywords'][0] ) ? array_map( 'trim', explode( ',', $metadata['wp_pattern_keywords'][0] ) ) : array(),
				'categories'  => $categories,
				'inserter'    => true,
				'origin'      => (string) ( $metadata[ Pattern_File_Store::META_ORIGIN ][0] ?? '' ),
				'cloud'       => (string) ( $metadata[ Pattern_File_Store::META_CLOUD ][0] ?? '' ),
			)
		);
	}
}
