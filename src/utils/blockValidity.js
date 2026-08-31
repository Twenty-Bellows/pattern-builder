/**
 * Ask the editor's own validator whether markup is valid.
 *
 * WordPress decides a block is valid by re-running the block type's `save()`
 * and diffing the result against the stored markup. `save()` is JavaScript —
 * `WP_Block_Type` has no equivalent, and PHP's `serialize_block()` replays what
 * it parsed rather than regenerating it — so no server can make this call. The
 * markup renders perfectly on the front end and reads as "unexpected or invalid
 * content" in every editor that opens it.
 *
 * Here in wp-admin the real validator is already loaded, so a pattern can be
 * checked against it before it is sent anywhere.
 */

import { parse } from '@wordpress/blocks';

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
 * A block whose type isn't registered here resolves to `core/missing` and is
 * left alone: that means a plugin this site doesn't have, which is the
 * service's allowlist to rule on, not a fault in the markup.
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
		// Markup the parser cannot read at all is the service's to refuse;
		// this check is only about what the editor would call invalid.
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
 * A sentence naming what is invalid, for a notice.
 *
 * @param {Array} invalid Output of findInvalidBlocks().
 * @return {string} The block names, deduplicated, comma separated.
 */
export function describeInvalidBlocks( invalid ) {
	const counted = new Map();

	for ( const block of invalid ) {
		counted.set( block.title, ( counted.get( block.title ) || 0 ) + 1 );
	}

	return [ ...counted ]
		.map( ( [ title, count ] ) =>
			count > 1 ? `${ title } (${ count })` : title
		)
		.join( ', ' );
}
