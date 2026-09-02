/**
 * The collection arithmetic the cloud tabs share, kept free of React so it
 * can be tested on its own: which tokens a whole collection needs, which of
 * its patterns to skip, what the results add up to, and which collection an
 * upload should default to.
 */

/**
 * The `{owner}/{slug}` key a collection is addressed by.
 *
 * @param {Object} collection A collection summary.
 * @return {string} The key, or '' when the summary is incomplete.
 */
export function collectionKey( collection ) {
	if ( ! collection || ! collection.slug ) {
		return '';
	}
	return `${ collection.owner }/${ collection.slug }`;
}

/**
 * Whether two collection references name the same collection.
 *
 * @param {Object} a A collection summary or link-map entry.
 * @param {Object} b Another.
 * @return {boolean} Whether they match.
 */
export function sameCollection( a, b ) {
	const ka = collectionKey( a );
	return ka !== '' && ka === collectionKey( b );
}

/**
 * The union of the design tokens a set of patterns references — what a
 * whole-collection install has to check once rather than once per pattern.
 * A token is identified by its type and slug; the first value seen wins,
 * since the destination's own definition wins over any of them anyway.
 *
 * @param {Array} patterns Pattern summaries, each with a `tokens` list.
 * @return {Array} The distinct tokens.
 */
export function unionTokens( patterns ) {
	const seen = new Map();
	( patterns || [] ).forEach( ( pattern ) => {
		( pattern.tokens || [] ).forEach( ( token ) => {
			const key = `${ token.type }:${ token.slug }`;
			if ( ! seen.has( key ) ) {
				seen.set( key, token );
			}
		} );
	} );
	return Array.from( seen.values() );
}

/**
 * Which of a collection's patterns to install and which to skip: one the
 * link map says is already installed from this collection is skipped.
 *
 * @param {Array}  patterns   Pattern summaries with an `installed` field.
 * @param {Object} collection The collection they belong to.
 * @return {{toInstall: Array, skipped: Array}} The plan.
 */
export function planInstall( patterns, collection ) {
	const toInstall = [];
	const skipped = [];
	( patterns || [] ).forEach( ( pattern ) => {
		const installed = pattern.installed;
		if (
			installed &&
			installed.collection &&
			sameCollection( installed.collection, collection )
		) {
			skipped.push( pattern );
		} else {
			toInstall.push( pattern );
		}
	} );
	return { toInstall, skipped };
}

/**
 * What a run of per-pattern results adds up to.
 *
 * @param {Array} results Entries of { pattern, status, message? }.
 * @return {{installed: number, skipped: number, failed: Array}} The totals
 *         and the failures, each as { title, message }.
 */
export function summarizeInstall( results ) {
	const summary = { installed: 0, skipped: 0, failed: [] };
	( results || [] ).forEach( ( result ) => {
		if ( result.status === 'installed' ) {
			summary.installed++;
		} else if ( result.status === 'skipped' ) {
			summary.skipped++;
		} else if ( result.status === 'failed' ) {
			summary.failed.push( {
				title: result.pattern?.title || '',
				message: result.message || '',
			} );
		}
	} );
	return summary;
}

/**
 * How many of a collection's patterns the link map says are installed here.
 *
 * @param {Object} links      The link map, localKey => entry.
 * @param {Object} collection The collection.
 * @return {number} The count.
 */
export function installedFromCollection( links, collection ) {
	return Object.values( links || {} ).filter( ( link ) =>
		sameCollection( link.collection, collection )
	).length;
}

/**
 * The collection an upload should offer first: the one used last, when it
 * still exists; otherwise Personal; otherwise the first there is.
 *
 * @param {Array}  collections The account's collections.
 * @param {number} lastUsedId  The id used last, or 0.
 * @return {Object|null} The collection, or null when there are none.
 */
export function pickDefaultCollection( collections, lastUsedId ) {
	const list = collections || [];
	if ( ! list.length ) {
		return null;
	}
	const lastUsed = list.find( ( item ) => item.id === lastUsedId );
	if ( lastUsed ) {
		return lastUsed;
	}
	return list.find( ( item ) => item.personal ) || list[ 0 ];
}

/**
 * Whether a collection is one the account is told nothing about on upload:
 * with only Personal, nothing is asked.
 *
 * @param {Array} collections The account's collections.
 * @return {boolean} Whether an upload should ask which collection.
 */
export function shouldAskForCollection( collections ) {
	return ( collections || [] ).length > 1;
}

/**
 * Whether a collection is listed publicly.
 *
 * @param {Object} collection A collection summary.
 * @return {boolean} Whether its patterns are, or will be, public.
 */
export function isListed( collection ) {
	return (
		collection?.visibility === 'public' ||
		collection?.visibility === 'premium'
	);
}
