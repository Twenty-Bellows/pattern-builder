/**
 * Opens a pattern for editing from wherever the user is. Every path leads to
 * the WordPress editor.
 *
 * Theme patterns are `pb_pattern` entities (string IDs, file-backed). The post
 * editor can swap any entity into its canvas via `onNavigateToEntityRecord`,
 * but the Site Editor's canvas routing is hard-coded to core's entities — from
 * there (or anywhere else) theme patterns open under Appearance → Pattern
 * Builder, whose edit mode hosts core's edit-post editor bound to the entity.
 *
 * User patterns are plain `wp_block` posts and every editor knows them: the
 * post editor swaps them in-context, the Site Editor edits them in place via
 * its /wp_block route, and everywhere else links into that Site Editor canvas.
 */

import { resolveSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * The Appearance → Pattern Builder edit-mode URL for a theme pattern — the
 * page hosts the WordPress editor (edit-post) bound to the pattern's entity.
 *
 * @param {Object} pattern The pattern.
 * @return {string} Admin URL.
 */
export function getAdminEditorUrl( pattern ) {
	const base =
		window.patternBuilderSettings?.adminEditorUrl ||
		window.patternBuilderAdmin?.adminUrl ||
		'themes.php?page=pattern-builder';

	return (
		base +
		'&pattern=' +
		encodeURIComponent( pattern.id ?? pattern.name ?? '' ) +
		// The editor's back button returns exactly here (validated
		// server-side before use).
		'&back=' +
		encodeURIComponent( window.location.href )
	);
}

/**
 * The Site Editor URL that edits a user pattern in its canvas.
 *
 * @param {Object} pattern The pattern.
 * @return {string} Site Editor URL.
 */
export function getSiteEditorUrl( pattern ) {
	return (
		'site-editor.php?p=' +
		encodeURIComponent( '/wp_block/' + pattern.id ) +
		'&canvas=edit'
	);
}

/**
 * Whether this screen is the Site Editor.
 *
 * @return {boolean} Whether the Site Editor is running.
 */
function isSiteEditorScreen() {
	return window.location.pathname.endsWith( 'site-editor.php' );
}

/**
 * Navigates to a pattern's editing surface.
 *
 * @param {Object}    pattern                  The pattern (an AbstractPattern or REST record).
 * @param {Function?} onNavigateToEntityRecord The block editor's navigation callback, if any.
 */
export function navigateToPattern( pattern, onNavigateToEntityRecord ) {
	const isTheme = pattern.source === 'theme';

	if ( ! isTheme ) {
		if ( onNavigateToEntityRecord ) {
			// In the post editor this swaps the entity in-context; in the
			// Site Editor it navigates to the /wp_block route in place.
			onNavigateToEntityRecord( {
				postId: pattern.id,
				postType: 'wp_block',
			} );
			return;
		}

		window.location.href = getSiteEditorUrl( pattern );
		return;
	}

	if ( onNavigateToEntityRecord && ! isSiteEditorScreen() ) {
		/*
		 * Resolve the entity before the editor swaps to it. Swapping to a
		 * record that is still loading lets the (momentarily empty) canvas
		 * commit an empty-content edit that then shadows the real content.
		 */
		resolveSelect( coreStore )
			.getEntityRecord( 'postType', 'pb_pattern', pattern.id )
			.finally( () => {
				onNavigateToEntityRecord( {
					postId: pattern.id,
					postType: 'pb_pattern',
				} );
			} );
		return;
	}

	window.location.href = getAdminEditorUrl( pattern );
}
