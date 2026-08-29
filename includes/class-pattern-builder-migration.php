<?php
namespace TwentyBellows\PatternBuilder;

/**
 * One-time upgrade from Pattern Builder 1.x to 2.0.
 *
 * Version 1 mirrored every theme pattern into a `tbell_pattern_block` post and
 * wrote `<!-- wp:block {"ref":N} /-->` references pointing at those posts.
 * Version 2 has no mirror posts, so those references would render nothing.
 *
 * The migration runs in strict order — the mirror rows are the only map from
 * ref ID back to pattern slug, so they must still exist while references are
 * rewritten:
 *
 * 1. Rewrite `wp:block` refs that point at mirror posts to
 *    `<!-- wp:pattern {"slug":"…"} /-->` — in theme pattern files and in post
 *    content.
 * 2. Delete the mirror posts (pure derived cache; the pattern files hold the
 *    content).
 * 3. Remove the custom capabilities v1 granted on activation.
 *
 * The routine is idempotent: with no mirror rows left it does nothing.
 */
class Pattern_Builder_Migration {

	/**
	 * Option storing the plugin version the database was last migrated to.
	 */
	const VERSION_OPTION = 'pattern_builder_version';

	/**
	 * Option storing the last migration's report.
	 */
	const REPORT_OPTION = 'pattern_builder_migration_report';

	/**
	 * Capabilities v1 granted (including the misspelled grants it checked).
	 */
	const V1_CAPABILITIES = array(
		'read_tbell_pattern_block',
		'edit_tbell_pattern_blocks',
		'delete_tbell_pattern_block',
		'delete_tbell_pattern_blocks',
	);

	/**
	 * Constructor: hooks the upgrade check.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
		add_action( 'admin_notices', array( $this, 'render_report_notice' ) );
	}

	/**
	 * Runs the migration once per version.
	 *
	 * @return void
	 */
	public function maybe_migrate() {
		$stored = get_option( self::VERSION_OPTION, '0' );

		if ( version_compare( $stored, '2.0.0', '>=' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			// Wait for a user who could have used v1's editing tools.
			return;
		}

		$report = $this->migrate();

		update_option( self::REPORT_OPTION, $report, false );
		update_option( self::VERSION_OPTION, PATTERN_BUILDER_VERSION );
	}

	/**
	 * Performs the v1 → v2 migration.
	 *
	 * @return array Report of what was rewritten and removed.
	 */
	public function migrate() {
		global $wpdb;

		$report = array(
			'rewritten_files' => array(),
			'rewritten_posts' => array(),
			'deleted_mirrors' => 0,
			'time'            => time(),
		);

		// The mirror rows are the ID → slug map; read them before anything else.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mirrors = $wpdb->get_results(
			"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'tbell_pattern_block'"
		);

		$ref_map = array();

		foreach ( $mirrors as $mirror ) {
			// v1 encoded '/' as '-x-x-' to fit post_name.
			$ref_map[ (int) $mirror->ID ] = str_replace( '-x-x-', '/', $mirror->post_name );
		}

		if ( $ref_map ) {
			$report['rewritten_files'] = $this->rewrite_theme_files( $ref_map );
			$report['rewritten_posts'] = $this->rewrite_post_content( $ref_map );

			foreach ( array_keys( $ref_map ) as $mirror_id ) {
				if ( wp_delete_post( $mirror_id, true ) ) {
					++$report['deleted_mirrors'];
				}
			}
		}

		$this->remove_v1_capabilities();
		$this->flush_v1_transients();

		return $report;
	}

	/**
	 * Rewrites mirror-post refs inside theme pattern files.
	 *
	 * Operates on the raw file bytes — pattern files contain PHP, so they are
	 * never run through the block parser here.
	 *
	 * @param array $ref_map Mirror post ID => pattern slug.
	 * @return string[] Paths of the files that changed.
	 */
	private function rewrite_theme_files( array $ref_map ) {
		$rewritten   = array();
		$directories = array( get_stylesheet_directory() . '/patterns' );

		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$directories[] = get_template_directory() . '/patterns';
		}

		foreach ( array_filter( $directories, 'is_dir' ) as $directory ) {
			$files = glob( $directory . '/*.php' );

			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$contents = file_get_contents( $file );

				if ( false === $contents || false === strpos( $contents, 'wp:block' ) ) {
					continue;
				}

				$updated = $this->rewrite_refs( $contents, $ref_map );

				if ( $updated === $contents ) {
					continue;
				}

				$result = Pattern_Builder_Security::safe_file_write(
					$file,
					$updated,
					array(
						get_stylesheet_directory() . '/patterns',
						get_template_directory() . '/patterns',
					)
				);

				if ( ! is_wp_error( $result ) ) {
					$rewritten[] = $file;
				}
			}
		}

		return $rewritten;
	}

	/**
	 * Rewrites mirror-post refs inside stored post content.
	 *
	 * @param array $ref_map Mirror post ID => pattern slug.
	 * @return int[] IDs of the posts that changed.
	 */
	private function rewrite_post_content( array $ref_map ) {
		global $wpdb;

		$rewritten = array();
		$batch     = 100;
		$last_id   = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts}
					WHERE ID > %d
					AND post_content LIKE %s
					AND post_type NOT IN ( 'revision', 'tbell_pattern_block' )
					ORDER BY ID ASC
					LIMIT %d",
					$last_id,
					'%<!-- wp:block%',
					$batch
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->ID;
				$updated = $this->rewrite_refs( $row->post_content, $ref_map );

				if ( $updated === $row->post_content ) {
					continue;
				}

				wp_update_post(
					array(
						'ID'           => $row->ID,
						'post_content' => $updated,
					)
				);

				$rewritten[] = (int) $row->ID;
			}
		}

		return $rewritten;
	}

	/**
	 * Rewrites every `wp:block` comment whose ref is a mirror post.
	 *
	 * Attributes other than `ref` — a `content` overrides object, alignment,
	 * class names — are preserved on the resulting `wp:pattern` block.
	 *
	 * @param string $content Block markup (may contain PHP; treated as text).
	 * @param array  $ref_map Mirror post ID => pattern slug.
	 * @return string The rewritten markup.
	 */
	public function rewrite_refs( $content, array $ref_map ) {
		return preg_replace_callback(
			'/<!--\s*wp:block\s+({.*?})\s*\/?-->/s',
			function ( $matches ) use ( $ref_map ) {
				$attributes = json_decode( $matches[1], true );

				if ( ! is_array( $attributes ) || ! isset( $attributes['ref'] ) ) {
					return $matches[0];
				}

				$ref = (int) $attributes['ref'];

				if ( ! isset( $ref_map[ $ref ] ) ) {
					return $matches[0];
				}

				unset( $attributes['ref'] );

				// `slug` leads so the serialized block reads naturally.
				$attributes = array_merge( array( 'slug' => $ref_map[ $ref ] ), $attributes );

				return '<!-- wp:pattern ' . serialize_block_attributes( $attributes ) . ' /-->';
			},
			$content
		);
	}

	/**
	 * Removes the capabilities v1 granted to roles on activation.
	 *
	 * @return void
	 */
	private function remove_v1_capabilities() {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::V1_CAPABILITIES as $capability ) {
				if ( $role->has_cap( $capability ) ) {
					$role->remove_cap( $capability );
				}
			}
		}
	}

	/**
	 * Deletes transients other Pattern Builder / companion versions left behind.
	 *
	 * @return void
	 */
	private function flush_v1_transients() {
		Synced_Patterns::flush();

		$theme = wp_get_theme();
		if ( $theme->exists() ) {
			$theme->delete_pattern_cache();
		}
	}

	/**
	 * Shows a one-time summary of what the upgrade changed.
	 *
	 * @return void
	 */
	public function render_report_notice() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$report = get_option( self::REPORT_OPTION );

		if ( ! is_array( $report ) || ! empty( $report['acknowledged'] ) ) {
			return;
		}

		$changed = $report['deleted_mirrors'] || $report['rewritten_files'] || $report['rewritten_posts'];

		// Only ever show the notice once.
		$report['acknowledged'] = true;
		update_option( self::REPORT_OPTION, $report, false );

		if ( ! $changed ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Pattern Builder 2.0:', 'pattern-builder' ),
			esc_html(
				sprintf(
					/* translators: 1: number of removed mirror posts, 2: number of rewritten posts, 3: number of rewritten files. */
					__( 'Cleaned up %1$d pattern mirror posts and rewrote pattern references in %2$d posts and %3$d theme files. Theme pattern files are now the single source of truth.', 'pattern-builder' ),
					(int) $report['deleted_mirrors'],
					count( (array) $report['rewritten_posts'] ),
					count( (array) $report['rewritten_files'] )
				)
			)
		);
	}
}
