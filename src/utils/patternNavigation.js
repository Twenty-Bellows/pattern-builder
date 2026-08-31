/**
 * Opens a pattern for editing from wherever the user is. Every pattern —
 * theme (`pb_pattern`, string IDs, file-backed) or user (`wp_block`) — is
 * edited in the post editor, because the Site Editor's canvas routing is
 * hard-coded to core's entities and so can never host a theme pattern.
 * Editing both kinds in the same editor is worth more than the Site Editor's
 * nicer canvas for the half of them that could use it.
 *
 * A post editor already on screen swaps the pattern into its canvas in place;
 * everywhere else — the Site Editor included — the pattern opens under
 * Appearance → Pattern Builder, whose edit mode hosts that same editor bound
 * to the pattern's entity.
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
		( pattern.source === 'theme' ? '' : '&type=user' ) +
		// The editor's back button returns exactly here (validated
		// server-side before use).
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
 * @param {Function?} onNavigateToEntityRecord The block editor's navigation callback, if any.
 */
export function navigateToPattern( pattern, onNavigateToEntityRecord ) {
	const postType = pattern.source === 'theme' ? 'pb_pattern' : 'wp_block';

	// The Site Editor supplies this callback too, but there it navigates its
	// own canvas — which theme patterns can't enter — so it is only used
	// where it swaps the entity in place: a post editor.
	if ( onNavigateToEntityRecord && ! isSiteEditorScreen() ) {
		/*
		 * Resolve the entity before the editor swaps to it. Swapping to a
		 * record that is still loading lets the (momentarily empty) canvas
		 * commit an empty-content edit that then shadows the real content.
		 */
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
