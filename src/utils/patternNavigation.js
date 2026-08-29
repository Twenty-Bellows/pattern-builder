/**
 * Opens a pattern for editing from wherever the user is.
 *
 * Theme patterns are `pb_pattern` entities (string IDs, file-backed). The post
 * editor can swap any entity into its canvas via `onNavigateToEntityRecord`,
 * but the Site Editor's canvas routing is hard-coded to core's entities — from
 * there (or anywhere else) theme patterns open in Pattern Builder's own
 * full-screen editor under Appearance → Pattern Builder.
 *
 * User patterns are plain `wp_block` posts and every editor knows them.
 */

import { resolveSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * The Pattern Builder admin editor URL for a theme pattern.
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
		encodeURIComponent( pattern.id ?? pattern.name ?? '' )
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
			onNavigateToEntityRecord( {
				postId: pattern.id,
				postType: 'wp_block',
			} );
			return;
		}

		window.location.href = `post.php?post=${ pattern.id }&action=edit`;
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
