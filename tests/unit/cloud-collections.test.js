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
	slugProblem,
	suggestSlug,
	summarizeInstall,
	unionTokens,
	needsNewerWordPress,
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

describe( 'suggestSlug', () => {
	it( 'turns a name into a slug worth suggesting', () => {
		expect( suggestSlug( 'Starter Sections' ) ).toBe( 'starter-sections' );
		expect( suggestSlug( 'Héros & Co.' ) ).toBe( 'heros-co' );
		expect( suggestSlug( '  Spaced  Out  ' ) ).toBe( 'spaced-out' );
	} );

	it( 'drops a leading number, because a slug starts with a letter', () => {
		expect( suggestSlug( '2024 Landing Pages' ) ).toBe( 'landing-pages' );
	} );

	it( 'gives back nothing when there is nothing to work with', () => {
		expect( suggestSlug( '###' ) ).toBe( '' );
		expect( suggestSlug( '' ) ).toBe( '' );
		expect( suggestSlug( null ) ).toBe( '' );
	} );
} );

describe( 'slugProblem', () => {
	it( 'passes a slug the service would accept', () => {
		expect( slugProblem( 'heroes' ) ).toBe( '' );
		expect( slugProblem( 'landing-pages-2024' ) ).toBe( '' );
	} );

	it( 'names what is wrong', () => {
		expect( slugProblem( '' ) ).toContain( 'Give the collection a slug' );
		expect( slugProblem( 'ab' ) ).toContain( 'between' );
		expect( slugProblem( 'a'.repeat( 33 ) ) ).toContain( 'between' );
		expect( slugProblem( 'Heroes' ) ).toContain( 'lower-case' );
		expect( slugProblem( '2024-heroes' ) ).toContain(
			'starts with a letter'
		);
		expect( slugProblem( 'hero--big' ) ).toContain( 'single hyphens' );
		expect( slugProblem( 'personal' ) ).toContain( 'Personal collection' );
	} );
} );

describe( 'needsNewerWordPress', () => {
	it( 'passes a pattern that names no version', () => {
		expect( needsNewerWordPress( {}, '6.8' ) ).toBe( '' );
		expect( needsNewerWordPress( { minWordPress: '' }, '6.8' ) ).toBe( '' );
		expect( needsNewerWordPress( { minWordPress: '  ' }, '6.8' ) ).toBe(
			''
		);
	} );

	it( 'passes what this site already runs, and anything older', () => {
		expect( needsNewerWordPress( { minWordPress: '6.8' }, '6.8' ) ).toBe(
			''
		);
		expect( needsNewerWordPress( { minWordPress: '6.8' }, '7.1' ) ).toBe(
			''
		);
		expect( needsNewerWordPress( { minWordPress: '6.9' }, '6.10' ) ).toBe(
			''
		);
	} );

	it( 'names the version a pattern needs when this site is older', () => {
		expect( needsNewerWordPress( { minWordPress: '6.9' }, '6.8' ) ).toBe(
			'6.9'
		);
		expect( needsNewerWordPress( { minWordPress: '7.1' }, '6.9' ) ).toBe(
			'7.1'
		);
	} );

	it( 'compares segment by segment, not as a decimal', () => {
		// 6.10 is newer than 6.9, which a numeric comparison gets backwards.
		expect( needsNewerWordPress( { minWordPress: '6.10' }, '6.9' ) ).toBe(
			'6.10'
		);
		expect( needsNewerWordPress( { minWordPress: '6.9' }, '6.10' ) ).toBe(
			''
		);
	} );

	it( 'does not count a release suffix as older', () => {
		// A site on 7.2-RC1 has the 7.2 blocks; only the release number
		// counts, or every RC would be refused its own release's patterns.
		expect(
			needsNewerWordPress( { minWordPress: '7.2' }, '7.2-RC1' )
		).toBe( '' );
		expect(
			needsNewerWordPress( { minWordPress: '7.2' }, '7.2-alpha-59999' )
		).toBe( '' );
	} );

	it( 'leaves it to the server when this site version is unknown', () => {
		expect( needsNewerWordPress( { minWordPress: '99.0' }, '' ) ).toBe(
			''
		);
		expect(
			needsNewerWordPress( { minWordPress: '99.0' }, undefined )
		).toBe( '' );
	} );
} );
