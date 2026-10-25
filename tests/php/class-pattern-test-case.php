<?php
/**
 * Shared setup for the plugin's tests.
 *
 * @package Pattern_Builder
 */

/**
 * Registers patterns for a test and cleans them up afterwards.
 */
abstract class Pattern_Test_Case extends WP_UnitTestCase {
	/**
	 * Slugs of the patterns registered by the running test.
	 *
	 * @var string[]
	 */
	private $registered = array();

	/**
	 * Where the block themes a test builds are written.
	 */
	const TEMPLATE_THEME_ROOT = 'pattern-builder-template-themes';

	/**
	 * The theme active before a test switched to one it built, or null.
	 *
	 * @var string|null
	 */
	private $theme_before = null;

	/**
	 * Names of the block templates registered by the running test.
	 *
	 * @var string[]
	 */
	private $registered_templates = array();

	/**
	 * Sets the test up.
	 */
	public function set_up() {
		parent::set_up();

		add_filter( 'should_load_remote_block_patterns', '__return_false' );
	}

	/**
	 * Tears the test down.
	 */
	public function tear_down() {
		$registry = WP_Block_Patterns_Registry::get_instance();

		foreach ( $this->registered as $slug ) {
			if ( $registry->is_registered( $slug ) ) {
				$registry->unregister( $slug );
			}
		}

		$this->registered = array();

		foreach ( $this->registered_templates as $name ) {
			if ( WP_Block_Templates_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_template( $name );
			}
		}
		$this->registered_templates = array();

		\TwentyBellows\PatternBuilder\Synced_Patterns::flush();

		if ( null !== $this->theme_before ) {
			switch_theme( $this->theme_before );
			$this->theme_before = null;
			$this->remove_directory( $this->template_theme_root() );
			wp_clean_theme_json_cache();
		}

		parent::tear_down();
	}

	/**
	 * Build a block theme of the test's own and make it the active one, so templates are
	 * read and written where core looks for them and nowhere the suite shares.
	 *
	 * It has an index template that places a header part, the header part, and a theme.json
	 * naming the part's area. With $with_parent it is a child theme of a second one built
	 * beside it, which carries its own index, single template and header part.
	 *
	 * @param bool $with_parent Whether the active theme is a child theme.
	 * @return string The active theme's directory.
	 */
	protected function use_a_template_theme( bool $with_parent = false ): string {
		$root = $this->template_theme_root();
		$this->remove_directory( $root );

		$files = array(
			'templates/index.html' => "<!-- wp:template-part {\"slug\":\"header\"} /-->\n<!-- wp:post-content /-->\n",
			'parts/header.html'    => "<!-- wp:site-title /-->\n",
			'theme.json'           => wp_json_encode(
				array(
					'version'       => 3,
					'templateParts' => array(
						array(
							'name'  => 'header',
							'title' => 'Header',
							'area'  => 'header',
						),
					),
				),
				JSON_PRETTY_PRINT
			) . "\n",
		);

		$this->write_theme( $root . '/pb-template-test', $with_parent ? 'pb-template-parent' : '', $files );
		if ( $with_parent ) {
			$this->write_theme(
				$root . '/pb-template-parent',
				'',
				array_merge(
					$files,
					array( 'templates/single.html' => "<!-- wp:template-part {\"slug\":\"header\"} /-->\n<!-- wp:post-title /-->\n" )
				)
			);
		}

		register_theme_directory( $root );
		delete_site_transient( 'theme_roots' );
		wp_clean_themes_cache();

		if ( null === $this->theme_before ) {
			$this->theme_before = get_stylesheet();
		}
		switch_theme( 'pb-template-test' );
		wp_clean_theme_json_cache();

		return get_stylesheet_directory();
	}

	/**
	 * Registers a block template for the duration of the test, as a plugin would.
	 *
	 * @param string $name Template name, "plugin//slug".
	 * @param array  $args Template arguments.
	 * @return string The name.
	 */
	protected function register_template( string $name, array $args ): string {
		register_block_template( $name, $args );
		$this->registered_templates[] = $name;

		return $name;
	}

	/**
	 * Save a Site Editor customization of a template or part, the way the Site Editor stores
	 * one: a published row filed under the active theme.
	 *
	 * @param string $post_type wp_template or wp_template_part.
	 * @param string $slug Template slug.
	 * @param string $content Block markup.
	 * @return int Post ID.
	 */
	protected function customize_template( string $post_type, string $slug, string $content ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_name'    => $slug,
				'post_title'   => 'Customized ' . $slug,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		return $post_id;
	}

	/**
	 * Write one theme's files.
	 *
	 * @param string $directory Theme directory.
	 * @param string $parent Parent theme stylesheet, or '' for none.
	 * @param array  $files Relative path => contents.
	 * @return void
	 */
	private function write_theme( string $directory, string $parent, array $files ): void {
		$header = "/*\nTheme Name: " . basename( $directory ) . "\n" . ( '' !== $parent ? 'Template: ' . $parent . "\n" : '' ) . "*/\n";

		foreach ( array_merge( array( 'style.css' => $header ), $files ) as $relative => $contents ) {
			$path = $directory . '/' . $relative;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Where the themes a test builds live.
	 *
	 * @return string
	 */
	private function template_theme_root(): string {
		return sys_get_temp_dir() . '/' . self::TEMPLATE_THEME_ROOT;
	}

	/**
	 * Remove a directory and everything under it.
	 *
	 * @param string $directory Directory.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $entries as $entry ) {
			if ( $entry->isDir() ) {
				rmdir( $entry->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				unlink( $entry->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Registers a pattern for the duration of the test.
	 *
	 * @param string $slug Pattern slug, including namespace.
	 * @param string $content Pattern content.
	 * @param array  $properties Additional pattern properties.
	 * @return string The slug.
	 */
	protected function register_pattern( string $slug, string $content, array $properties = array() ): string {
		register_block_pattern(
			$slug,
			array_merge(
				array(
					'title'   => $slug,
					'content' => $content,
				),
				$properties
			)
		);

		$this->registered[] = $slug;

		return $slug;
	}

	/**
	 * A heading with one content slot named "headline".
	 *
	 * @param string $text The heading's default text.
	 * @return string Block markup.
	 */
	protected function bound_heading( string $text = 'Default headline' ): string {
		return '<!-- wp:heading {"metadata":{"name":"headline","bindings":{"content":{"source":"core/pattern-overrides"}}}} -->' . "\n"
			. '<h2 class="wp-block-heading">' . $text . '</h2>' . "\n"
			. '<!-- /wp:heading -->';
	}

	/**
	 * Marks a pattern as synced for the duration of a test, through the filter, as a plugin
	 * would.
	 *
	 * @param string $slug Pattern slug.
	 * @return void
	 */
	protected function mark_synced( string $slug ): void {
		add_filter(
			'pattern_builder_synced_patterns',
			static function ( $slugs ) use ( $slug ) {
				$slugs[] = $slug;

				return $slugs;
			}
		);

		\TwentyBellows\PatternBuilder\Synced_Patterns::flush();
	}

	/**
	 * Serializes a `core/pattern` block that carries content.
	 *
	 * @param string $slug Pattern slug.
	 * @param array  $content Content, keyed by slot name and then attribute name.
	 * @return string Block markup.
	 */
	protected function pattern_block( string $slug, array $content = array() ): string {
		$attributes = array( 'slug' => $slug );

		if ( ! empty( $content ) ) {
			$attributes['content'] = $content;
		}

		return '<!-- wp:pattern ' . wp_json_encode( $attributes ) . ' /-->';
	}
}
