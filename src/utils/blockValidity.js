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
 *
 * Core answers two different questions and this asks both. `parse()` is
 * tolerant: every block keeps its old `save()` implementations for backward
 * compatibility (`core/paragraph` has six), and markup matching any of them is
 * accepted and migrated. `validateBlock()` is strict — is this what the block
 * writes *today*? The gap between them is where a pattern quietly rots, so
 * only the first blocks an upload while the second is worth saying out loud.
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
 * Blocks an editor would silently rewrite, and settings it would throw away.
 *
 * Neither of these makes a block invalid, so neither blocks an upload — but
 * both are wrong in a way nothing else reports. Markup matching a deprecated
 * save opens without a murmur and is migrated, while the file that everybody
 * else renders still lacks what the current block writes, nearly always a
 * block-supports class: `{"backgroundColor":"primary"}` with no
 * `has-primary-background-color` renders with no background at all. Worse, a
 * migration reads the markup as authoritative and can drop the attribute
 * outright — a heading with `"fontSize":"xx-large"` and no matching class
 * comes back with no font size, perfectly self-consistent, silently plainer
 * than it was written.
 *
 * @param {string} markup Block markup.
 * @return {Array<{name: string, title: string, reason: string}>} In document order.
 */
export function findOutdatedBlocks( markup ) {
	if ( typeof markup !== 'string' || ! markup.trim() ) {
		return [];
	}

	// Older WordPress has no `validateBlock` export; the hard check above is
	// the baseline and this is the extra.
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
			// Invented by a migration, so there is nothing of it on disk.
			block.originalContent === undefined ||
			/*
			 * A bound block takes its content from the binding source at
			 * render rather than from the markup, and core reserves room in
			 * the saved output for that value, so the file and a save
			 * computed from the file's attributes are not comparable.
			 */
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
 * Core moves things as well as dropping them. Block library 10.5 turned text
 * alignment from a paragraph's `align` and a heading's `textAlign` into a
 * typography support, and its migration relocates the value to
 * `style.typography.textAlign` rather than discarding it. The setting is
 * intact, so that is not a loss and must not be reported as one.
 *
 * Only values distinctive enough not to collide are followed: a bare `2` or
 * `true` would match something unrelated and turn this into noise.
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
 * Walks the authored tree beside the parsed one. Where a migration reshaped
 * the tree the two stop corresponding, and this stops rather than guess:
 * silence is better than a false alarm on somebody's upload button.
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
				// `content` on core/pattern belongs to the synced-pattern
				// runtime rather than to core, which drops it here.
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
