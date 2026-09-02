/**
 * The dependency walk: what a pattern references, in what order it has to
 * be carried, and how its references are renamespaced on the way up.
 */

import {
	referencesOf,
	treeOf,
	treeProblem,
	rewriteReferences,
} from '../../src/utils/patternTree';

const reference = ( name ) => `<!-- wp:pattern {"slug":"${ name }"} /-->`;
const paragraph = ( text ) =>
	`<!-- wp:paragraph -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->`;

/**
 * A resolver over a plain map of name → content.
 *
 * @param {Object} patterns Name → markup.
 * @return {Function} A resolver for treeOf.
 */
const resolverFor = ( patterns ) => ( name ) =>
	patterns[ name ] ? { name, content: patterns[ name ] } : null;

describe( 'referencesOf', () => {
	it( 'finds references at any depth, in document order', () => {
		const content = `<!-- wp:group --><div class="wp-block-group">
			${ reference( 'studio-a/heroes/hero' ) }
			<!-- wp:columns --><div class="wp-block-columns">
			${ reference( 'studio-a/heroes/cta' ) }
			</div><!-- /wp:columns -->
			</div><!-- /wp:group -->`;

		expect( referencesOf( content ) ).toEqual( [
			'studio-a/heroes/hero',
			'studio-a/heroes/cta',
		] );
	} );

	it( 'names each referenced pattern once', () => {
		const content = [
			reference( 'a/b/hero' ),
			reference( 'a/b/hero' ),
			reference( 'a/b/cta' ),
		].join( '\n' );

		expect( referencesOf( content ) ).toEqual( [ 'a/b/hero', 'a/b/cta' ] );
	} );

	it( 'finds nothing in a pattern that references nothing', () => {
		expect( referencesOf( paragraph( 'Hello' ) ) ).toEqual( [] );
		expect( referencesOf( '' ) ).toEqual( [] );
		expect( referencesOf( null ) ).toEqual( [] );
	} );
} );

describe( 'treeOf', () => {
	it( 'returns the tree leaves first, with the root last', () => {
		const resolve = resolverFor( {
			'me/set/page': [
				reference( 'me/set/hero' ),
				reference( 'me/set/cta' ),
			].join( '\n' ),
			'me/set/hero': reference( 'me/set/logos' ),
			'me/set/cta': paragraph( 'Sign up' ),
			'me/set/logos': paragraph( 'Logos' ),
		} );

		const tree = treeOf( 'me/set/page', resolve );

		expect( tree.missing ).toEqual( [] );
		expect( tree.cycle ).toBeNull();
		expect( tree.order.map( ( item ) => item.name ) ).toEqual( [
			'me/set/logos',
			'me/set/hero',
			'me/set/cta',
			'me/set/page',
		] );
	} );

	it( 'carries a shared dependency once', () => {
		const resolve = resolverFor( {
			'me/set/page': [
				reference( 'me/set/one' ),
				reference( 'me/set/two' ),
			].join( '\n' ),
			'me/set/one': reference( 'me/set/shared' ),
			'me/set/two': reference( 'me/set/shared' ),
			'me/set/shared': paragraph( 'Shared' ),
		} );

		const names = treeOf( 'me/set/page', resolve ).order.map(
			( item ) => item.name
		);

		expect(
			names.filter( ( name ) => name === 'me/set/shared' )
		).toHaveLength( 1 );
		expect( names.indexOf( 'me/set/shared' ) ).toBeLessThan(
			names.indexOf( 'me/set/one' )
		);
	} );

	it( 'collects every missing dependency rather than stopping at the first', () => {
		const resolve = resolverFor( {
			'me/set/page': [
				reference( 'me/set/gone' ),
				reference( 'me/set/also-gone' ),
			].join( '\n' ),
		} );

		const tree = treeOf( 'me/set/page', resolve );

		expect( tree.missing ).toEqual( [ 'me/set/gone', 'me/set/also-gone' ] );
		expect( treeProblem( tree ) ).toContain( 'me/set/gone' );
		expect( treeProblem( tree ) ).toContain( 'me/set/also-gone' );
	} );

	it( 'names a loop instead of walking it forever', () => {
		const resolve = resolverFor( {
			'me/set/a': reference( 'me/set/b' ),
			'me/set/b': reference( 'me/set/c' ),
			'me/set/c': reference( 'me/set/a' ),
		} );

		const tree = treeOf( 'me/set/a', resolve );

		expect( tree.cycle ).toEqual( [
			'me/set/a',
			'me/set/b',
			'me/set/c',
			'me/set/a',
		] );
		expect( treeProblem( tree ) ).toContain( 'me/set/a → me/set/b' );
	} );

	it( 'is one pattern when nothing is referenced', () => {
		const resolve = resolverFor( { 'me/set/solo': paragraph( 'Alone' ) } );
		const tree = treeOf( 'me/set/solo', resolve );

		expect( tree.order.map( ( item ) => item.name ) ).toEqual( [
			'me/set/solo',
		] );
		expect( treeProblem( tree ) ).toBe( '' );
	} );
} );

describe( 'rewriteReferences', () => {
	it( 'renamespaces a reference and keeps the pattern name', () => {
		const content = reference( 'mytheme/hero' );

		expect(
			rewriteReferences( content, 'studio-a/heroes', [ 'mytheme/hero' ] )
		).toBe( reference( 'studio-a/heroes/hero' ) );
	} );

	it( 'leaves everything else in the markup alone', () => {
		const content = [
			paragraph( 'A page about mytheme/hero, in words.' ),
			reference( 'mytheme/hero' ),
			'<!-- wp:image {"url":"https://example.test/mytheme/hero.png"} /-->',
		].join( '\n' );

		const rewritten = rewriteReferences( content, 'studio-a/heroes', [
			'mytheme/hero',
		] );

		expect( rewritten ).toContain( 'A page about mytheme/hero, in words.' );
		expect( rewritten ).toContain(
			'https://example.test/mytheme/hero.png'
		);
		expect( rewritten ).toContain( '"slug":"studio-a/heroes/hero"' );
	} );

	it( 'rewrites every occurrence of every reference', () => {
		const content = [
			reference( 'mytheme/hero' ),
			reference( 'mytheme/cta' ),
			reference( 'mytheme/hero' ),
		].join( '\n' );

		const rewritten = rewriteReferences( content, 'me/set', [
			'mytheme/hero',
			'mytheme/cta',
		] );

		expect( rewritten ).not.toContain( 'mytheme/' );
		expect( rewritten.match( /"slug":"me\/set\/hero"/g ) ).toHaveLength(
			2
		);
	} );

	it( 'renamespaces an already-namespaced reference', () => {
		expect(
			rewriteReferences(
				reference( 'studio-a/heroes/hero' ),
				'studio-b/mine',
				[ 'studio-a/heroes/hero' ]
			)
		).toBe( reference( 'studio-b/mine/hero' ) );
	} );
} );
