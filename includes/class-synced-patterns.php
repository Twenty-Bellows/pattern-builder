<?php
/**
 * Finds the patterns a theme wants kept linked.
 *
 * @package Pattern_Builder
 */

namespace TwentyBellows\PatternBuilder;

/**
 * Reads the `Synced` header from a theme's pattern files.
 */
class Synced_Patterns {
	/**
	 * Suffix for the companion entry that puts a reference in the inserter.
	 */
	const INSERTER_SUFFIX = '--synced-instance';

	/**
	 * Prefix for the cache of synced pattern slugs.
	 */
	const CACHE_KEY_PREFIX = 'pattern_builder_synced_';

	/**
	 * Slugs of the synced patterns, once looked up.
	 *
	 * @var string[]|null
	 */
	private static $slugs = null;

	/**
	 * Lists the slugs of every pattern the active theme wants kept linked.
	 *
	 * @return string[] Pattern slugs.
	 */
	public static function get_slugs(): array {
		if ( null !== self::$slugs ) {
			return self::$slugs;
		}

		$cache_key     = self::CACHE_KEY_PREFIX . get_stylesheet();
		$can_use_cache = ! wp_is_development_mode( 'theme' );
		$slugs         = $can_use_cache ? get_transient( $cache_key ) : false;

		if ( ! is_array( $slugs ) ) {
			$slugs = self::scan_theme_patterns();

			if ( $can_use_cache ) {
				set_transient( $cache_key, $slugs, WEEK_IN_SECONDS );
			}
		}

		/**
		 * Filters the patterns that are inserted as a reference to themselves.
		 *
		 * @since 2.0.0
		 * @param string[] $slugs Pattern slugs, including namespace.
		 */
		$slugs = (array) apply_filters( 'pattern_builder_synced_patterns', $slugs );

		self::$slugs = array_values( array_unique( array_filter( $slugs, 'is_string' ) ) );

		return self::$slugs;
	}

	/**
	 * Determines whether a pattern is one the theme wants kept linked.
	 *
	 * @param mixed $slug Pattern slug, including namespace.
	 * @return bool Whether the pattern is synced.
	 */
	public static function is_synced( $slug ): bool {
		return is_string( $slug ) && in_array( $slug, self::get_slugs(), true );
	}

	/**
	 * Returns the slug of the companion pattern that inserts a reference.
	 *
	 * @param string $slug Pattern slug, including namespace.
	 * @return string The companion pattern's slug.
	 */
	public static function get_inserter_slug( string $slug ): string {
		return $slug . self::INSERTER_SUFFIX;
	}

	/**
	 * Block markup that references a pattern rather than copying it.
	 *
	 * @param string $slug Pattern slug, including namespace.
	 * @return string Block markup.
	 */
	public static function get_reference_markup( string $slug ): string {
		return '<!-- wp:pattern ' . serialize_block_attributes( array( 'slug' => $slug ) ) . ' /-->';
	}

	/**
	 * Forgets the cached lookup.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$slugs = null;

		delete_transient( self::CACHE_KEY_PREFIX . get_stylesheet() );
	}

	/**
	 * Reads the `Synced` header from the active theme's pattern files.
	 *
	 * @return string[] Pattern slugs.
	 */
	private static function scan_theme_patterns(): array {
		$slugs = array();

		foreach ( self::get_pattern_directories() as $directory ) {
			$files = glob( $directory . '/*.php' );

			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$headers = get_file_data(
					$file,
					array(
						'slug'   => 'Slug',
						'synced' => 'Synced',
					)
				);

				if ( ! empty( $headers['slug'] ) && self::header_means_yes( $headers['synced'] ) ) {
					$slugs[] = $headers['slug'];
				}
			}
		}

		return $slugs;
	}

	/**
	 * Lists the pattern directories of the active theme and its parent.
	 *
	 * @return string[] Absolute directory paths.
	 */
	private static function get_pattern_directories(): array {
		$directories = array( get_stylesheet_directory() . '/patterns' );

		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$directories[] = get_template_directory() . '/patterns';
		}

		return array_filter( $directories, 'is_dir' );
	}

	/**
	 * Reads a header value as a yes or a no.
	 *
	 * @param string $value Raw header value.
	 * @return bool Whether the header says yes.
	 */
	private static function header_means_yes( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( 'yes', 'true', '1', 'on' ), true );
	}
}
