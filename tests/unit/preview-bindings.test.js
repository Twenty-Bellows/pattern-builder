/**
 * The read half of `core/pattern-overrides`, which the browse screen
 * registers because no editor boots there to do it.
 */

// The module reaches for the block editor's store descriptor and nothing
// else from the package, which jest cannot load (it pulls in stylesheets).
jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

import { getPatternOverrideValues } from '../../src/admin/preview-bindings';

const selectWith = ( attributes ) => () => ( {
	getBlockAttributes: () => attributes,
} );

const values = ( { attributes, overrides, bindings } ) =>
	getPatternOverrideValues( {
		select: selectWith( attributes ),
		clientId: 'abc',
		context: { 'pattern/overrides': overrides },
		bindings,
	} );

describe( 'getPatternOverrideValues', () => {
	const bindings = { content: { source: 'core/pattern-overrides' } };
	const attributes = {
		content: 'The headline for this page',
		metadata: { name: 'headline' },
	};

	it( 'supplies the words the reference carries', () => {
		expect(
			values( {
				attributes,
				overrides: { headline: { content: 'Start free.' } },
				bindings,
			} )
		).toEqual( { content: 'Start free.' } );
	} );

	it( "keeps the pattern's own copy when the slot is not filled", () => {
		expect(
			values( { attributes, overrides: { other: {} }, bindings } )
		).toEqual( { content: 'The headline for this page' } );

		expect(
			values( { attributes, overrides: undefined, bindings } )
		).toEqual( { content: 'The headline for this page' } );
	} );

	it( 'renders a slot the page cleared as empty, not as the placeholder', () => {
		expect(
			values( {
				attributes,
				overrides: { headline: { content: '' } },
				bindings,
			} )
		).toEqual( { content: undefined } );
	} );

	it( 'answers for every bound attribute', () => {
		expect(
			values( {
				attributes: {
					text: 'Primary action',
					url: '#',
					metadata: { name: 'cta' },
				},
				overrides: { cta: { text: 'Go Pro' } },
				bindings: { text: {}, url: {} },
			} )
		).toEqual( { text: 'Go Pro', url: '#' } );
	} );

	it( 'has nothing to say about a block with no slot name', () => {
		expect(
			values( {
				attributes: { content: 'Fixed words' },
				overrides: { headline: { content: 'Start free.' } },
				bindings,
			} )
		).toEqual( { content: 'Fixed words' } );
	} );
} );
