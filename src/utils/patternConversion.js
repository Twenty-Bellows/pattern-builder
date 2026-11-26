/**
 * What the editor has to catch up on after a pattern is converted between theme and user.
 */

import { dispatch, select } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { createBlock } from '@wordpress/blocks';

import { repointBlocks } from './repointBlocks';

/**
 * How a pattern record is referenced from other markup.
 *
 * @param {Object} pattern A pattern record: `{ id, name, source }`.
 * @return {import('./repointBlocks').PatternAddress} Its address.
 */
export function addressOf( pattern ) {
	return pattern.source === 'theme'
		? { type: 'slug', value: pattern.name }
		: { type: 'ref', value: Number( pattern.id ) };
}

/**
 * Keeps the list of synced theme patterns the page was loaded with true to a conversion.
 *
 * The runtime reads that list every time a `core/pattern` renders and keeps a reference to
 * a slug on it; any other slug it replaces with the pattern's blocks. So a page holding a
 * reference to a pattern that became a synced theme pattern a moment ago would have the
 * reference flattened, and saved that way.
 *
 * @param {Object}  from     The pattern's address before.
 * @param {Object}  to       Its address now.
 * @param {boolean} isSynced Whether the pattern is synced.
 */
function updateSyncedSlugs( from, to, isSynced ) {
	const settings =
		window.syncedPatternsForThemes ?? ( window.patternBuilder ??= {} );
	const without = ( settings.syncedPatterns ?? [] ).filter(
		( slug ) => slug !== from.value && slug !== to.value
	);

	settings.syncedPatterns =
		to.type === 'slug' && isSynced ? [ ...without, to.value ] : without;
}

/**
 * Brings the editor's own copies up to date with a conversion the server just made.
 *
 * The server rewrote every stored reference. Three things in this editor still describe
 * the site as it was: the posts it has loaded, which would save the old reference back; its
 * lists of patterns, where the converted pattern is still offered under the kind it no
 * longer is; and the list of which theme patterns are synced.
 *
 * @param {Object}   from           The pattern's address before, from addressOf().
 * @param {Object}   to             Its address now.
 * @param {Object[]} repointedPosts The rows the server rewrote: `{ id, postType }`.
 * @param {boolean}  isSynced       Whether the converted pattern is synced.
 */
export function syncEditorAfterConversion(
	from,
	to,
	repointedPosts = [],
	isSynced = false
) {
	updateSyncedSlugs( from, to, isSynced );

	const { getEntityRecordEdits } = select( coreStore );
	const {
		editEntityRecord,
		invalidateResolution,
		invalidateResolutionForStoreSelector,
	} = dispatch( coreStore );

	repointedPosts.forEach( ( { id, postType } ) => {
		// A post with unsaved edits holds its blocks as an edit, which wins over whatever
		// is fetched; one without is re-read from the server the next time it is asked for.
		const blocks = getEntityRecordEdits( 'postType', postType, id )?.blocks;
		if ( blocks ) {
			const moved = repointBlocks( blocks, from, to, createBlock );
			if ( moved !== blocks ) {
				editEntityRecord(
					'postType',
					postType,
					id,
					{ blocks: moved },
					{ undoIgnore: true }
				);
			}
		}

		invalidateResolution( 'getEntityRecord', [ 'postType', postType, id ] );
	} );

	// The inserter reads user patterns through a query this code cannot name exactly, so
	// every list is re-read; a conversion is rare enough for that to be the simple answer.
	invalidateResolutionForStoreSelector( 'getEntityRecords' );
	invalidateResolutionForStoreSelector( 'getBlockPatterns' );
}
