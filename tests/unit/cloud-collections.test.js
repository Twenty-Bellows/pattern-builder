/**
 * The collection arithmetic behind the cloud tabs: the token union a
 * whole-collection save checks once, which patterns it skips, what its
 * results add up to, and which collection an upload offers first.
 */

import {
	collectionKey,
	installedFromCollection,
	pickDefaultCollection,
	planInstall,
	shouldAskForCollection,
	summarizeInstall,
	unionTokens,
} from '../../src/cloud/collections';

const starter = { owner: 2, slug: 'starter-sections', title: 'Starter' };
const other = { owner: 2, slug: 'other', title: 'Other' };

describe( 'unionTokens', () => {
	it( 'keeps one of each token across the patterns, first value winning', () => {
		const union = unionTokens( [
			{
				tokens: [
					{ type: 'color', slug: 'accent', value: '#111' },
					{ type: 'spacing', slug: '50', value: '2rem' },
				],
			},
			{ tokens: [ { type: 'color', slug: 'accent', value: '#222' } ] },
			{},
			{ tokens: [ { type: 'fontSize', slug: 'large', value: '2rem' } ] },
		] );

		expect( union ).toEqual( [
			{ type: 'color', slug: 'accent', value: '#111' },
			{ type: 'spacing', slug: '50', value: '2rem' },
			{ type: 'fontSize', slug: 'large', value: '2rem' },
		] );
	} );
} );

describe( 'planInstall', () => {
	it( 'skips only what is already installed from this collection', () => {
		const plan = planInstall(
			[
				{ id: 1, installed: null },
				{ id: 2, installed: { type: 'user', collection: starter } },
				{ id: 3, installed: { type: 'user', collection: other } },
				{ id: 4, installed: { type: 'user', collection: {} } },
			],
			starter
		);

		expect( plan.toInstall.map( ( p ) => p.id ) ).toEqual( [ 1, 3, 4 ] );
		expect( plan.skipped.map( ( p ) => p.id ) ).toEqual( [ 2 ] );
	} );
} );

describe( 'summarizeInstall', () => {
	it( 'counts installed and skipped and lists the failures', () => {
		const summary = summarizeInstall( [
			{ pattern: { title: 'A' }, status: 'installed' },
			{ pattern: { title: 'B' }, status: 'skipped' },
			{ pattern: { title: 'C' }, status: 'failed', message: 'Pro only' },
			{ pattern: { title: 'D' }, status: 'installed' },
		] );

		expect( summary.installed ).toBe( 2 );
		expect( summary.skipped ).toBe( 1 );
		expect( summary.failed ).toEqual( [
			{ title: 'C', message: 'Pro only' },
		] );
	} );
} );

describe( 'installedFromCollection', () => {
	it( 'counts the link-map entries that name the collection', () => {
		const links = {
			'user:1': { cloudId: 10, collection: starter },
			'user:2': { cloudId: 11, collection: other },
			'theme:x': { cloudId: 12, collection: { ...starter } },
			'user:3': { cloudId: 13 },
		};

		expect( installedFromCollection( links, starter ) ).toBe( 2 );
		expect( installedFromCollection( links, other ) ).toBe( 1 );
		expect( installedFromCollection( {}, starter ) ).toBe( 0 );
	} );
} );

describe( 'pickDefaultCollection', () => {
	const personal = { id: 9, personal: true, title: 'Personal' };
	const heroes = { id: 31, personal: false, title: 'Heroes' };

	it( 'offers the collection used last when it still exists', () => {
		expect( pickDefaultCollection( [ personal, heroes ], 31 ) ).toBe(
			heroes
		);
	} );

	it( 'falls back to Personal, then to the first there is', () => {
		expect( pickDefaultCollection( [ heroes, personal ], 99 ) ).toBe(
			personal
		);
		expect( pickDefaultCollection( [ heroes ], 0 ) ).toBe( heroes );
		expect( pickDefaultCollection( [], 0 ) ).toBeNull();
	} );

	it( 'asks only when there is more than Personal', () => {
		expect( shouldAskForCollection( [ personal ] ) ).toBe( false );
		expect( shouldAskForCollection( [ personal, heroes ] ) ).toBe( true );
		expect( shouldAskForCollection( null ) ).toBe( false );
	} );
} );

describe( 'collectionKey', () => {
	it( 'addresses a collection by owner and slug', () => {
		expect( collectionKey( starter ) ).toBe( '2/starter-sections' );
		expect( collectionKey( { owner: 2 } ) ).toBe( '' );
		expect( collectionKey( null ) ).toBe( '' );
	} );
} );
