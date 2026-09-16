/**
 * The collection arithmetic the cloud tabs share, kept free of React so it can be tested on
 * its own: which tokens a whole collection needs, which of its patterns to skip, what the
 * results add up to, and which collection an upload should default to.
 */

import { __, sprintf } from '@wordpress/i18n';

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
 * The union of the design tokens a set of patterns references — what a whole-collection
 * install has to check once rather than once per pattern.
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
 * Which of a collection's patterns to install and which to skip: one already here under its
 * cloud name is skipped.
 *
 * @param {Array} patterns Pattern summaries with an `installed` field.
 * @return {{toInstall: Array, skipped: Array}} The plan.
 */
export function planInstall( patterns ) {
	const toInstall = [];
	const skipped = [];
	( patterns || [] ).forEach( ( pattern ) => {
		( pattern.installed ? skipped : toInstall ).push( pattern );
	} );
	return { toInstall, skipped };
}

/**
 * What a run of per-pattern results adds up to.
 *
 * @param {Array} results Entries of { pattern, status, message?
 * @return {{installed: number, skipped: number, failed: Array}} The totals and the
 * failures, each as { title, message }.
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
 * How many of a collection's patterns are installed here: the cloud names this site's
 * patterns answer to that sit under the collection's namespace.
 *
 * @param {Array}  names      Cloud names, as /cloud/installed lists them.
 * @param {Object} collection The collection, with its `{handle}/{slug}` namespace.
 * @return {number} The count.
 */
export function installedFromCollection( names, collection ) {
	if ( ! collection?.namespace ) {
		return 0;
	}
	const prefix = `${ collection.namespace }/`;
	return ( names || [] ).filter( ( name ) => name.startsWith( prefix ) )
		.length;
}

/**
 * The collection an upload should offer first: the one used last, when it still exists;
 * otherwise Personal; otherwise the first there is.
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
 * Whether a collection is one the account is told nothing about on upload: with only
 * Personal, nothing is asked.
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

/**
 * The shape a slug has to have: lower-case letters, numbers and single hyphens, starting
 * with a letter.
 */
const SLUG_SHAPE = /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/;
const SLUG_MIN = 3;
const SLUG_MAX = 32;

/**
 * A slug suggested from a collection's name, for a field the author is free to overwrite.
 *
 * @param {string} name The collection's name.
 * @return {string} A slug, or '' when nothing usable is left.
 */
export function suggestSlug( name ) {
	return String( name || '' )
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' )
		.replace( /^[0-9]+-?/, '' )
		.slice( 0, SLUG_MAX )
		.replace( /-+$/, '' );
}

/**
 * What is wrong with a slug, in a sentence, or '' when nothing is.
 *
 * @param {string} slug The slug as typed.
 * @return {string} The problem, ready to show.
 */
export function slugProblem( slug ) {
	const value = String( slug || '' ).trim();

	if ( value === '' ) {
		return __( 'Give the collection a slug.', 'pattern-builder' );
	}
	if ( value === 'personal' ) {
		return __(
			'Every account already has a Personal collection.',
			'pattern-builder'
		);
	}
	if ( value.length < SLUG_MIN || value.length > SLUG_MAX ) {
		return sprintf(
			/* translators: 1: minimum length, 2: maximum length. */
			__(
				'A slug is between %1$d and %2$d characters.',
				'pattern-builder'
			),
			SLUG_MIN,
			SLUG_MAX
		);
	}
	if ( ! SLUG_SHAPE.test( value ) ) {
		return __(
			'A slug uses lower-case letters, numbers and single hyphens, and starts with a letter.',
			'pattern-builder'
		);
	}

	return '';
}

/**
 * Compare two WordPress version numbers, segment by segment.
 *
 * @param {string} a First version.
 * @param {string} b Second version.
 * @return {number} Negative when a < b, positive when a > b, 0 when equal.
 */
function compareVersions( a, b ) {
	const left = String( a ).split( '.' ).map( Number );
	const right = String( b ).split( '.' ).map( Number );

	for ( let i = 0; i < Math.max( left.length, right.length ); i++ ) {
		const one = left[ i ] || 0;
		const two = right[ i ] || 0;
		if ( one !== two ) {
			return one - two;
		}
	}

	return 0;
}

/**
 * The WordPress version a pattern needs, when this site is older than it.
 *
 * @param {Object} pattern     A cloud pattern summary.
 * @param {string} siteVersion This site's WordPress version.
 * @return {string} The version needed, or '' when this site can render it.
 */
export function needsNewerWordPress( pattern, siteVersion ) {
	const needs = String( pattern?.minWordPress || '' ).trim();
	if ( ! needs ) {
		return '';
	}
	const here = String( siteVersion || '' ).replace( /[-+].*$/, '' );
	if ( ! here ) {
		return '';
	}

	return compareVersions( here, needs ) < 0 ? needs : '';
}
