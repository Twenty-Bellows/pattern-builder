/**
 * A tile's cache key has to change exactly when the site's render of the
 * tile could: the pattern's markup, the markup of what it places, or the
 * design system — and nothing else, or the grid redraws for no reason.
 */

import { tileKey, tileUrls } from '../../src/utils/tileKey';

const paragraph = ( text ) =>
	`<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`;
const reference = ( name ) => `<!-- wp:pattern {"slug":"${ name }"} /-->`;

const theme = ( name, content ) => ( {
	id: name,
	name,
	source: 'theme',
	content,
} );

const resolverFor = ( patterns ) => ( dependency ) =>
	patterns.find( ( pattern ) =>
		dependency.kind === 'pattern'
			? pattern.source === 'theme' && pattern.name === dependency.id
			: pattern.source === 'user' &&
			  String( pattern.id ) === dependency.id
	)?.content;

describe( 'tileKey', () => {
	const hero = theme( 'site/hero', paragraph( 'Hero' ) );
	const cta = theme( 'site/cta', paragraph( 'Sign up' ) );
	const page = theme(
		'site/page',
		reference( 'site/hero' ) + reference( 'site/cta' )
	);

	it( 'is the same for the same inputs', () => {
		const resolve = resolverFor( [ hero, cta, page ] );

		expect( tileKey( page, resolve, 'd1' ) ).toBe(
			tileKey( page, resolve, 'd1' )
		);
	} );

	it( 'changes with the markup and with the design version', () => {
		const resolve = resolverFor( [ hero ] );
		const key = tileKey( hero, resolve, 'd1' );

		expect(
			tileKey( { ...hero, content: paragraph( 'Hero!' ) }, resolve, 'd1' )
		).not.toBe( key );
		expect( tileKey( hero, resolve, 'd2' ) ).not.toBe( key );
	} );

	it( 'follows the patterns a pattern places, at any depth', () => {
		const band = theme( 'site/band', reference( 'site/page' ) );
		const before = tileKey(
			band,
			resolverFor( [ hero, cta, page, band ] ),
			'd1'
		);
		const edited = { ...hero, content: paragraph( 'New hero' ) };

		expect(
			tileKey( band, resolverFor( [ edited, cta, page, band ] ), 'd1' )
		).not.toBe( before );
	} );

	it( 'follows a user pattern placed by id', () => {
		const note = {
			id: 7,
			name: 'note',
			source: 'user',
			content: paragraph( 'Note' ),
		};
		const holder = theme( 'site/holder', '<!-- wp:block {"ref":7} /-->' );
		const before = tileKey( holder, resolverFor( [ note, holder ] ), 'd1' );
		const edited = { ...note, content: paragraph( 'Edited note' ) };

		expect(
			tileKey( holder, resolverFor( [ edited, holder ] ), 'd1' )
		).not.toBe( before );
	} );

	it( 'is not moved by a pattern it does not place', () => {
		const other = theme( 'site/other', paragraph( 'Other' ) );
		const before = tileKey(
			page,
			resolverFor( [ hero, cta, page ] ),
			'd1'
		);

		expect(
			tileKey( page, resolverFor( [ hero, cta, page, other ] ), 'd1' )
		).toBe( before );
	} );

	it( 'survives a loop', () => {
		const a = theme( 'site/a', reference( 'site/b' ) );
		const b = theme( 'site/b', reference( 'site/a' ) );

		expect( typeof tileKey( a, resolverFor( [ a, b ] ), 'd1' ) ).toBe(
			'string'
		);
	} );
} );

describe( 'tileUrls', () => {
	const hero = theme( 'site/hero', paragraph( 'Hero' ) );
	const page = theme( 'site/page', reference( 'site/hero' ) );
	const alone = theme( 'site/alone', paragraph( 'Alone' ) );

	it( 'addresses each tile by pattern id, with its key, on the base', () => {
		const url = tileUrls( [ hero ], 'https://example.test/', 'd1' ).get(
			'site/hero'
		);

		expect( url ).toMatch(
			/^https:\/\/example\.test\/\?pattern_builder_tile=site%2Fhero&v=[a-z0-9]+$/
		);
	} );

	it( 'gives a page a new URL when a section changes, and no one else', () => {
		const before = tileUrls(
			[ hero, page, alone ],
			'https://example.test/',
			'd1'
		);
		const after = tileUrls(
			[ { ...hero, content: paragraph( 'New hero' ) }, page, alone ],
			'https://example.test/',
			'd1'
		);

		expect( after.get( 'site/hero' ) ).not.toBe(
			before.get( 'site/hero' )
		);
		expect( after.get( 'site/page' ) ).not.toBe(
			before.get( 'site/page' )
		);
		expect( after.get( 'site/alone' ) ).toBe( before.get( 'site/alone' ) );
	} );
} );
