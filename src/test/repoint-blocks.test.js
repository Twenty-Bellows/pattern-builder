/**
 * Tests for moving the references to a pattern inside a block tree.
 */

import { repointBlocks } from '../utils/repointBlocks';

const createBlock = ( name, attributes ) => ( {
	clientId: 'new',
	name,
	attributes,
	innerBlocks: [],
} );

const block = ( name, attributes = {}, innerBlocks = [] ) => ( {
	clientId: name,
	name,
	attributes,
	innerBlocks,
} );

describe( 'repointBlocks', () => {
	const theme = { type: 'slug', value: 'acme/hero' };
	const user = { type: 'ref', value: 42 };

	it( 'turns a slug reference into a ref and keeps what it fills', () => {
		const content = { headline: { content: 'Filled here' } };
		const blocks = [
			block( 'core/pattern', { slug: 'acme/hero', content } ),
		];

		expect( repointBlocks( blocks, theme, user, createBlock ) ).toEqual( [
			createBlock( 'core/block', { ref: 42, content } ),
		] );
	} );

	it( 'turns a ref into a slug reference', () => {
		const blocks = [ block( 'core/block', { ref: 42 } ) ];

		expect( repointBlocks( blocks, user, theme, createBlock ) ).toEqual( [
			createBlock( 'core/pattern', { slug: 'acme/hero' } ),
		] );
	} );

	it( 'finds a reference at any depth and leaves its siblings alone', () => {
		const sibling = block( 'core/paragraph' );
		const other = block( 'core/block', { ref: 7 } );
		const blocks = [
			block( 'core/group', {}, [
				sibling,
				block( 'core/columns', {}, [
					block( 'core/block', { ref: '42' } ),
				] ),
			] ),
			other,
		];

		const moved = repointBlocks( blocks, user, theme, createBlock );

		expect( moved[ 0 ].innerBlocks[ 1 ].innerBlocks[ 0 ].name ).toBe(
			'core/pattern'
		);
		expect( moved[ 0 ].innerBlocks[ 0 ] ).toBe( sibling );
		expect( moved[ 1 ] ).toBe( other );
	} );

	it( 'hands back the same array when nothing references the pattern', () => {
		const blocks = [
			block( 'core/pattern', { slug: 'acme/footer' } ),
			block( 'core/group', {}, [ block( 'core/block', { ref: 420 } ) ] ),
		];

		expect( repointBlocks( blocks, theme, user, createBlock ) ).toBe(
			blocks
		);
		expect( repointBlocks( blocks, user, theme, createBlock ) ).toBe(
			blocks
		);
	} );
} );
