/**
 * Opens a pattern for editing from wherever the user is.
 */

import { resolveSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * The Appearance → Pattern Builder browse screen.
 *
 * @return {string} Admin URL.
 */
export function getBrowseUrl() {
	return (
		window.patternBuilderSettings?.adminEditorUrl ||
		window.patternBuilderAdmin?.adminUrl ||
		'themes.php?page=pattern-builder'
	);
}

/**
 * The Appearance → Pattern Builder edit-mode URL for a theme pattern — the page hosts the
 * WordPress editor (edit-post) bound to the pattern's entity.
 *
 * @param {Object} pattern The pattern.
 * @return {string} Admin URL.
 */
export function getAdminEditorUrl( pattern ) {
	return (
		getBrowseUrl() +
		'&pattern=' +
		encodeURIComponent( pattern.id ?? pattern.name ?? '' ) +
		( pattern.source === 'theme' ? '' : '&type=user' ) +
		'&back=' +
		encodeURIComponent( window.location.href )
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
 * @param {Function?} onNavigateToEntityRecord The block editor's navigation callback, if
 *                                             any.
 */
export function navigateToPattern( pattern, onNavigateToEntityRecord ) {
	const postType = pattern.source === 'theme' ? 'pb_pattern' : 'wp_block';
	if ( onNavigateToEntityRecord && ! isSiteEditorScreen() ) {
		resolveSelect( coreStore )
			.getEntityRecord( 'postType', postType, pattern.id )
			.finally( () => {
				onNavigateToEntityRecord( {
					postId: pattern.id,
					postType,
				} );
			} );
		return;
	}

	window.location.href = getAdminEditorUrl( pattern );
}
