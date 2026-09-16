/**
 * The browse grid's tiles are drawn by the site itself: each is a front-end render of the
 * pattern (Pattern_Builder_Preview::serve_tile()), framed and scaled like a cloud preview.
 */

import { parse } from '@wordpress/block-serialization-default-parser';
import { addQueryArgs } from '@wordpress/url';

/**
 * The patterns a piece of markup places: `core/pattern` references by name, and
 * `core/block` references to user patterns by post id.
 *
 * @param {string} content Block markup.
 * @return {Array<{kind: string, id: string}>} What it places.
 */
function dependenciesOf( content ) {
	const found = [];
	const walk = ( blocks ) => {
		for ( const block of blocks ) {
			if ( block.blockName === 'core/pattern' && block.attrs?.slug ) {
				found.push( {
					kind: 'pattern',
					id: String( block.attrs.slug ),
				} );
			} else if ( block.blockName === 'core/block' && block.attrs?.ref ) {
				found.push( { kind: 'block', id: String( block.attrs.ref ) } );
			}
			if ( block.innerBlocks?.length ) {
				walk( block.innerBlocks );
			}
		}
	};
	walk( parse( content || '' ) );
	return found;
}

/**
 * Two polynomial string hashes over prime moduli — about 62 bits between them — as base 36.
 *
 * @param {string} text The text.
 * @return {string} The hash.
 */
function hash( text ) {
	let a = 7;
	let b = 11;
	for ( let i = 0; i < text.length; i++ ) {
		const ch = text.charCodeAt( i );
		a = ( a * 31 + ch ) % 2147483647;
		b = ( b * 131 + ch ) % 4294967291;
	}
	return a.toString( 36 ) + b.toString( 36 );
}

/**
 * A tile's cache key: a hash of everything its render depends on.
 *
 * @param {Object}   pattern       The pattern, with its `content`.
 * @param {Function} resolve       Called with `{ kind, id }` for each pattern placed;
 *                                 returns its markup, or undefined when it is not on this site.
 * @param {string}   designVersion What every tile depends on.
 * @return {string} The key.
 */
export function tileKey( pattern, resolve, designVersion ) {
	const parts = [ designVersion || '', pattern.content || '' ];
	const seen = new Set();

	const visit = ( content ) => {
		for ( const dependency of dependenciesOf( content ) ) {
			const key = `${ dependency.kind }:${ dependency.id }`;
			if ( seen.has( key ) ) {
				continue;
			}
			seen.add( key );

			const markup = resolve( dependency );
			parts.push( key, markup ?? '' );
			if ( markup ) {
				visit( markup );
			}
		}
	};

	visit( pattern.content || '' );

	return hash( JSON.stringify( parts ) );
}

/**
 * The tile URL of every pattern in a listing, by pattern id.
 *
 * @param {Array}  patterns      Every local pattern: `id`, `name`, `source`, `content`.
 * @param {string} base          Where tiles are drawn (the site's front end).
 * @param {string} designVersion What every tile depends on.
 * @return {Map} Pattern id => tile URL.
 */
export function tileUrls( patterns, base, designVersion ) {
	const byName = new Map();
	const byId = new Map();
	for ( const pattern of patterns || [] ) {
		if ( pattern.source === 'theme' ) {
			byName.set( pattern.name, pattern.content );
		} else {
			byId.set( String( pattern.id ), pattern.content );
		}
	}

	const resolve = ( dependency ) =>
		dependency.kind === 'pattern'
			? byName.get( dependency.id )
			: byId.get( dependency.id );

	const urls = new Map();
	for ( const pattern of patterns || [] ) {
		urls.set(
			pattern.id,
			addQueryArgs( base, {
				pattern_builder_tile: String( pattern.id ),
				v: tileKey( pattern, resolve, designVersion ),
			} )
		);
	}
	return urls;
}
