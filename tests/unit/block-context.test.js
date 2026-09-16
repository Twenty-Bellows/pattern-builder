/**
 * Taking the post out of a block's context.
 *
 * `core/post-content` reads `postId` and `postType` and, finding them, renders the post
 * they name — or refuses, when that post is the document being edited. Taking them away
 * is how a theme pattern gets the placeholder core's own pattern editor shows.
 */

import { withoutPost } from '../../src/utils/blockContext';

describe( 'withoutPost', () => {
	it( 'takes the post out and leaves the rest alone', () => {
		expect(
			withoutPost( {
				postId: 12,
				postType: 'pb_pattern',
				templateSlug: 'page',
			} )
		).toEqual( { templateSlug: 'page' } );
	} );

	it( 'leaves a context that never carried a post unchanged', () => {
		expect( withoutPost( { templateSlug: 'page' } ) ).toEqual( {
			templateSlug: 'page',
		} );
	} );

	it( 'does not mutate what it is given', () => {
		const context = { postId: 12, postType: 'pb_pattern' };

		withoutPost( context );

		expect( context ).toEqual( { postId: 12, postType: 'pb_pattern' } );
	} );

	it( 'survives no context at all', () => {
		expect( withoutPost( undefined ) ).toEqual( {} );
	} );
} );
