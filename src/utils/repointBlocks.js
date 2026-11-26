/**
 * Moving the references to a pattern inside a tree of blocks the editor is holding.
 *
 * The server rewrites what is stored when a pattern is converted. A post already open in
 * this editor, with edits of its own, holds its blocks in memory and would save the old
 * reference straight back over that rewrite; this is the same move made on those blocks.
 */

/**
 * @typedef {Object} PatternAddress How a pattern is referenced.
 * @property {'slug'|'ref'}  type  `slug` for a theme pattern (core/pattern), `ref` for a
 *                                 user pattern (core/block).
 * @property {string|number} value The pattern's name, or its post ID.
 */

const BLOCK_OF = { slug: 'core/pattern', ref: 'core/block' };

/**
 * Whether a block is a reference to the pattern at an address.
 *
 * @param {Object}         block   A block.
 * @param {PatternAddress} address The address.
 * @return {boolean} Whether the block places that pattern.
 */
function isReferenceTo( block, address ) {
	if ( block?.name !== BLOCK_OF[ address.type ] ) {
		return false;
	}

	const named = block.attributes?.[ address.type ];

	// A ref arrives as a number from the parser and can be a string in hand-written markup.
	return address.type === 'ref'
		? Number( named ) === Number( address.value )
		: named === address.value;
}

/**
 * Points every reference to one pattern at another address, at any depth.
 *
 * Every other attribute, the slots the place fills among them, moves across as it is. A tree
 * that holds no such reference comes back as the same array, so a caller can tell nothing
 * changed.
 *
 * @param {Object[]}       blocks      The blocks.
 * @param {PatternAddress} from        The address the pattern had.
 * @param {PatternAddress} to          The address it has now.
 * @param {Function}       createBlock `createBlock` from `@wordpress/blocks`.
 * @return {Object[]} The blocks, with the references moved.
 */
export function repointBlocks( blocks, from, to, createBlock ) {
	let changed = false;

	const moved = ( blocks || [] ).map( ( block ) => {
		if ( isReferenceTo( block, from ) ) {
			changed = true;
			const { slug, ref, ...rest } = block.attributes;

			return createBlock( BLOCK_OF[ to.type ], {
				[ to.type ]: to.type === 'ref' ? Number( to.value ) : to.value,
				...rest,
			} );
		}

		const innerBlocks = repointBlocks(
			block.innerBlocks,
			from,
			to,
			createBlock
		);
		if ( innerBlocks === block.innerBlocks ) {
			return block;
		}

		changed = true;
		return { ...block, innerBlocks };
	} );

	return changed ? moved : blocks;
}
