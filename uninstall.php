<?php
/**
 * Uninstall routine for Pattern Builder.
 *
 * @package Pattern_Builder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pattern_builder_version' );
delete_option( 'pattern_builder_migration_report' );
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '\_transient\_pattern\_builder\_synced\_%'
	OR option_name LIKE '\_transient\_timeout\_pattern\_builder\_synced\_%'"
);
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
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$mirror_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tbell_pattern_block'" );

foreach ( $mirror_ids as $mirror_id ) {
	wp_delete_post( (int) $mirror_id, true );
}
