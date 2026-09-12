/**
 * Ask the editor's own validator whether markup is valid.
 */

import { parse, validateBlock } from '@wordpress/blocks';
import { parse as parseRaw } from '@wordpress/block-serialization-default-parser';

/**
 * Walk a parsed tree, innermost blocks included.
 *
 * @param {Array} blocks Parsed blocks.
 * @param {Array} found  Accumulator.
 * @return {Array} Every block in the tree.
 */
function flatten( blocks, found = [] ) {
	for ( const block of blocks ) {
		found.push( block );
		if ( block.innerBlocks?.length ) {
			flatten( block.innerBlocks, found );
		}
	}
	return found;
}

/**
 * The blocks in some markup that the editor considers invalid.
 *
 * @param {string} markup Block markup.
 * @return {Array<{name: string, title: string}>} Invalid blocks, in document order.
 */
export function findInvalidBlocks( markup ) {
	if ( typeof markup !== 'string' || ! markup.trim() ) {
		return [];
	}

	let parsed;
	try {
		parsed = parse( markup );
	} catch {
		return [];
	}

	return flatten( parsed )
		.filter(
			( block ) =>
				block.name &&
				block.name !== 'core/missing' &&
				block.isValid === false
		)
		.map( ( block ) => ( {
			name: block.name,
			title: block.name.replace( /^core\//, '' ),
		} ) );
}

/**
 * Blocks an editor would silently rewrite, and settings it would throw away.
 *
 * @param {string} markup Block markup.
 * @return {Array<{name: string, title: string, reason: string}>} In document order.
 */
export function findOutdatedBlocks( markup ) {
	if ( typeof markup !== 'string' || ! markup.trim() ) {
		return [];
	}
	if ( typeof validateBlock !== 'function' ) {
		return [];
	}

	let parsed;
	let authored;
	try {
		parsed = parse( markup );
		authored = parseRaw( markup );
	} catch {
		return [];
	}

	const found = [];
	const describe = ( block, reason ) => ( {
		name: block.name,
		title: block.name.replace( /^core\//, '' ),
		reason,
	} );

	for ( const block of flatten( parsed ) ) {
		if (
			! block.name ||
			block.name === 'core/missing' ||
			block.isValid === false ||
			block.originalContent === undefined ||
			block.attributes?.metadata?.bindings
		) {
			continue;
		}

		let current = true;
		try {
			[ current ] = validateBlock( block );
		} catch {
			continue;
		}

		if ( ! current ) {
			found.push( describe( block, 'old-form' ) );
		}
	}

	for ( const loss of attributeLosses( authored, parsed ) ) {
		found.push( { ...loss, reason: 'dropped-attribute' } );
	}

	return found;
}

/**
 * Did the value turn up somewhere else in the attributes?
 *
 * @param {*}      value      The authored value.
 * @param {Object} attributes Attributes after parsing.
 * @return {boolean} Whether the value is still in there somewhere.
 */
function survivedElsewhere( value, attributes ) {
	const json = JSON.stringify( value );
	if ( ! json || ! /^["[{]/.test( json ) || json.length < 4 ) {
		return false;
	}
	return JSON.stringify( attributes ?? {} ).includes( json );
}

/**
 * Attributes written into the markup that did not survive parsing.
 *
 * @param {Array} authored Blocks from the raw serialization parser.
 * @param {Array} parsed   Blocks from the full parser.
 * @param {Array} found    Accumulator.
 * @return {Array} Blocks that lost attributes.
 */
function attributeLosses( authored, parsed, found = [] ) {
	const wrote = authored.filter( ( block ) => block.blockName );
	const got = parsed.filter(
		( block ) => block.name && block.name !== 'core/freeform'
	);

	if ( wrote.length !== got.length ) {
		return found;
	}

	for ( let i = 0; i < wrote.length; i++ ) {
		if ( wrote[ i ].blockName !== got[ i ].name ) {
			return found;
		}

		const dropped = Object.keys( wrote[ i ].attrs || {} ).filter(
			( key ) =>
				! ( got[ i ].name === 'core/pattern' && key === 'content' ) &&
				got[ i ].attributes?.[ key ] === undefined &&
				! survivedElsewhere(
					wrote[ i ].attrs[ key ],
					got[ i ].attributes
				)
		);

		if ( dropped.length ) {
			found.push( {
				name: got[ i ].name,
				title: got[ i ].name.replace( /^core\//, '' ),
				dropped,
			} );
		}

		attributeLosses(
			wrote[ i ].innerBlocks || [],
			got[ i ].innerBlocks || [],
			found
		);
	}

	return found;
}

/**
 * A sentence naming some blocks, for a notice.
 *
 * @param {Array} blocks Output of findInvalidBlocks() or findOutdatedBlocks().
 * @return {string} The block names, deduplicated, comma separated.
 */
export function describeBlocks( blocks ) {
	const counted = new Map();

	for ( const block of blocks ) {
		counted.set( block.title, ( counted.get( block.title ) || 0 ) + 1 );
	}

	return [ ...counted ]
		.map( ( [ title, count ] ) =>
			count > 1 ? `${ title } (${ count })` : title
		)
		.join( ', ' );
}
