/**
 * The block picker's vocabulary: the field talks in block titles, the
 * pattern file records block names, and the two have to survive the round
 * trip — including a block this site does not have.
 */

import {
	getOfferableBlockTypes,
	getBlockChoices,
	tokenToBlockName,
} from '../../src/components/BlockTypePicker';

const type = ( name, title, extra = {} ) => ( { name, title, ...extra } );

describe( 'getOfferableBlockTypes', () => {
	it( 'keeps blocks that can be inserted on their own', () => {
		const kept = getOfferableBlockTypes( [
			type( 'core/cover', 'Cover' ),
			type( 'core/query', 'Query Loop' ),
		] );

		expect( kept.map( ( item ) => item.name ) ).toEqual( [
			'core/cover',
			'core/query',
		] );
	} );

	it( 'drops blocks that only live inside another block', () => {
		const kept = getOfferableBlockTypes( [
			type( 'core/columns', 'Columns' ),
			type( 'core/column', 'Column', { parent: [ 'core/columns' ] } ),
			type( 'core/list-item', 'List item', {
				ancestor: [ 'core/list' ],
			} ),
		] );

		expect( kept.map( ( item ) => item.name ) ).toEqual( [
			'core/columns',
		] );
	} );

	it( 'drops blocks the inserter itself hides', () => {
		const kept = getOfferableBlockTypes( [
			type( 'core/post-template', 'Post Template', {
				supports: { inserter: false },
			} ),
			type( 'core/post-content', 'Content', { supports: {} } ),
		] );

		expect( kept.map( ( item ) => item.name ) ).toEqual( [
			'core/post-content',
		] );
	} );
} );

describe( 'getBlockChoices', () => {
	it( 'labels a block with its title, sorted', () => {
		expect(
			getBlockChoices( [
				type( 'core/query', 'Query Loop' ),
				type( 'core/cover', 'Cover' ),
			] )
		).toEqual( [
			{ name: 'core/cover', label: 'Cover' },
			{ name: 'core/query', label: 'Query Loop' },
		] );
	} );

	it( 'tells two blocks with the same title apart', () => {
		expect(
			getBlockChoices( [
				type( 'core/gallery', 'Gallery' ),
				type( 'acme/gallery', 'Gallery' ),
				type( 'core/cover', 'Cover' ),
			] )
		).toEqual( [
			{ name: 'core/cover', label: 'Cover' },
			{ name: 'acme/gallery', label: 'Gallery (acme/gallery)' },
			{ name: 'core/gallery', label: 'Gallery (core/gallery)' },
		] );
	} );
} );

describe( 'tokenToBlockName', () => {
	const choices = getBlockChoices( [
		type( 'core/cover', 'Cover' ),
		type( 'core/query', 'Query Loop' ),
	] );

	it( 'reads a suggested label back as a block name', () => {
		expect( tokenToBlockName( 'Query Loop', choices ) ).toBe(
			'core/query'
		);
	} );

	it( 'is not case sensitive, and ignores stray spacing', () => {
		expect( tokenToBlockName( '  cover ', choices ) ).toBe( 'core/cover' );
	} );

	it( 'accepts a block name typed straight in — the block may belong to another site', () => {
		expect( tokenToBlockName( 'woocommerce/cart', choices ) ).toBe(
			'woocommerce/cart'
		);
	} );

	it( 'refuses anything that is neither', () => {
		expect( tokenToBlockName( 'something else', choices ) ).toBeNull();
		expect( tokenToBlockName( 'core/', choices ) ).toBeNull();
		expect( tokenToBlockName( '/cover', choices ) ).toBeNull();
	} );
} );
