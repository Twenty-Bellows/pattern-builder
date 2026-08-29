<?php
/**
 * Uninstall routine for Pattern Builder.
 *
 * Removes everything the plugin (including version 1.x) stored: options,
 * transients, the capabilities v1 granted, and any leftover
 * `tbell_pattern_block` mirror posts. Theme pattern files are content the
 * user authored — they are never touched.
 *
 * @package Pattern_Builder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pattern_builder_version' );
delete_option( 'pattern_builder_migration_report' );

// Synced-pattern lookup transients (one per theme the plugin ran under).
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '\_transient\_pattern\_builder\_synced\_%'
	OR option_name LIKE '\_transient\_timeout\_pattern\_builder\_synced\_%'"
);

// Capabilities granted by version 1.x.
$v1_capabilities = array(
	'read_tbell_pattern_block',
	'edit_tbell_pattern_blocks',
	'delete_tbell_pattern_block',
	'delete_tbell_pattern_blocks',
);

foreach ( wp_roles()->role_objects as $pattern_builder_role ) {
	foreach ( $v1_capabilities as $capability ) {
		if ( $pattern_builder_role->has_cap( $capability ) ) {
			$pattern_builder_role->remove_cap( $capability );
		}
	}
}

// Mirror posts left behind by version 1.x (harmless, but they are ours).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$mirror_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tbell_pattern_block'" );

foreach ( $mirror_ids as $mirror_id ) {
	wp_delete_post( (int) $mirror_id, true );
}
