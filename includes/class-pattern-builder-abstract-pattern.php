<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName -- camelCase properties intentionally mirror the JS AbstractPattern class.

namespace TwentyBellows\PatternBuilder;

/**
 * Value object representing a single block pattern.
 */
class Abstract_Pattern {
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
	 * Pattern source: 'theme', 'user', or 'plugin'.
	 *
	 * @var string
	 */
	public $source;

	/**
	 * What registered a plugin pattern, as a label to show — the plugin's name when one on
	 * this site answers to the pattern's namespace, the namespace itself otherwise. Always
	 * '' for a theme or user pattern.
	 *
	 * @var string
	 */
	public $plugin;

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
	 * Whether the pattern's file holds PHP this plugin can not write, which makes it a file
	 * it can read and render but must not write over. Derived from the file, never from a
	 * request, and always false for a user pattern.
	 *
	 * @var bool
	 */
	public $hasCustomPhp; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

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
		$this->plugin   = (string) ( $args['plugin'] ?? '' );
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
		$this->hasCustomPhp       = (bool) ( $args['hasCustomPhp'] ?? false ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		$this->id = $args['id'] ?? ( 'user' === $this->source ? null : $this->name );
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
				'hasCustomPhp'       => self::file_has_custom_php( $pattern_file ),
			)
		);
	}

	/**
	 * Whether a line of a pattern file's header comment sets one of the headers
	 * `from_file()` reads, and so belongs to a field of its own rather than to
	 * `$additionalMetadata`.
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
	 * The escape functions a localized write wraps a pattern's text in.
	 */
	const LOCALIZED_STRING_FUNCTIONS = array( 'wp_kses_post', 'esc_attr__' );

	/**
	 * Whether a pattern file holds PHP beyond the calls this plugin writes itself.
	 *
	 * @param string $pattern_file Absolute path to the pattern file.
	 * @return bool
	 */
	public static function file_has_custom_php( $pattern_file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a theme's own pattern file.
		$contents = file_get_contents( $pattern_file );

		if ( false === $contents ) {
			return false;
		}

		$markup_only = array( T_OPEN_TAG, T_CLOSE_TAG, T_DOC_COMMENT, T_INLINE_HTML, T_WHITESPACE );
		$tokens      = token_get_all( $contents );
		$count       = count( $tokens );

		for ( $index = 0; $index < $count; $index++ ) {
			$token = $tokens[ $index ];

			if ( is_array( $token ) && in_array( $token[0], $markup_only, true ) ) {
				continue;
			}

			$ends_at = self::written_call_ends_at( $tokens, $index );

			if ( null === $ends_at ) {
				return true;
			}

			$index = $ends_at;
		}

		return false;
	}

	/**
	 * The calls this plugin writes into pattern markup itself, as token shapes. An element is
	 * a token type, a literal character, or a type paired with the one identifier accepted in
	 * that position.
	 *
	 * @return array[]
	 */
	private static function written_call_shapes() {
		$shapes = array();

		// A localized string, as Pattern_Builder_Localization writes it, e.g.
		// `echo wp_kses_post( 'text', 'domain' );`.
		foreach ( self::LOCALIZED_STRING_FUNCTIONS as $escape ) {
			$shapes[] = array( T_ECHO, array( T_STRING, $escape ), '(', T_CONSTANT_ENCAPSED_STRING, ',', T_CONSTANT_ENCAPSED_STRING, ')', ';' );
		}

		// An image the theme carries, as Pattern_Builder_Assets::theme_reference() writes it, e.g.
		// `echo esc_url( get_stylesheet_directory_uri() . '/assets/images/square-1.svg' );`.
		$shapes[] = array( T_ECHO, array( T_STRING, 'esc_url' ), '(', array( T_STRING, 'get_stylesheet_directory_uri' ), '(', ')', '.', T_CONSTANT_ENCAPSED_STRING, ')', ';' );

		return $shapes;
	}

	/**
	 * Matches one of the calls this plugin writes, starting at the given token.
	 *
	 * @param array $tokens The file's tokens, from `token_get_all()`.
	 * @param int   $start  Index to match from.
	 * @return int|null The index the call ends at, or null when it is not one.
	 */
	private static function written_call_ends_at( array $tokens, $start ) {
		foreach ( self::written_call_shapes() as $shape ) {
			$ends_at = self::shape_ends_at( $tokens, $start, $shape );

			if ( null !== $ends_at ) {
				return $ends_at;
			}
		}

		return null;
	}

	/**
	 * Matches one token shape, skipping the whitespace between its parts.
	 *
	 * @param array $tokens The file's tokens, from `token_get_all()`.
	 * @param int   $start  Index to match from.
	 * @param array $shape  The shape to match, as `written_call_shapes()` describes one.
	 * @return int|null The index the shape ends at, or null when it does not match.
	 */
	private static function shape_ends_at( array $tokens, $start, array $shape ) {
		$index = $start;
		$count = count( $tokens );

		foreach ( $shape as $expected ) {
			while ( $index < $count && is_array( $tokens[ $index ] ) && T_WHITESPACE === $tokens[ $index ][0] ) {
				++$index;
			}

			if ( $index >= $count ) {
				return null;
			}

			$token = $tokens[ $index ];
			$type  = is_array( $token ) ? $token[0] : $token;

			$wanted = is_array( $expected ) ? $expected[0] : $expected;

			if ( $wanted !== $type ) {
				return null;
			}

			if ( is_array( $expected ) && $token[1] !== $expected[1] ) {
				return null;
			}

			++$index;
		}

		return $index - 1;
	}

	/**
	 * Reads the part of a pattern file's header comment that is not a recognised header.
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
	 * Creates an Abstract_Pattern from an entry in the block pattern registry — a pattern
	 * some plugin registered in PHP, which this site can render and copy but never write.
	 *
	 * @param array  $registered The pattern as WP_Block_Patterns_Registry reports it.
	 * @param string $plugin     A label for what registered it, or '' when it is unknown.
	 * @return self
	 */
	public static function from_registered( array $registered, $plugin = '' ) {
		return new self(
			array(
				'name'          => (string) ( $registered['name'] ?? '' ),
				'title'         => (string) ( $registered['title'] ?? '' ),
				'description'   => (string) ( $registered['description'] ?? '' ),
				'content'       => (string) ( $registered['content'] ?? '' ),
				'categories'    => array_values( (array) ( $registered['categories'] ?? array() ) ),
				'keywords'      => array_values( (array) ( $registered['keywords'] ?? array() ) ),
				'blockTypes'    => array_values( (array) ( $registered['blockTypes'] ?? array() ) ),
				'postTypes'     => array_values( (array) ( $registered['postTypes'] ?? array() ) ),
				'templateTypes' => array_values( (array) ( $registered['templateTypes'] ?? array() ) ),
				'viewportWidth' => $registered['viewportWidth'] ?? null,
				'inserter'      => false !== ( $registered['inserter'] ?? true ),
				'source'        => 'plugin',
				'plugin'        => $plugin,
				// A registered pattern has no `Synced:` header to read, and nothing to write
				// one to, so it is only ever an ordinary pattern.
				'synced'        => false,
			)
		);
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
