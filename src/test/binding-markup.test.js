/**
 * Round-trips real pattern markup through the panel's logic.
 *
 * Registers the core blocks so parse and serialize behave exactly as they do
 * in the editor: what is checked is that opening a pattern and changing
 * nothing leaves the file identical, and that a change writes the block
 * comment WordPress will resolve.
 */

import { parse, serialize } from '@wordpress/blocks';

import {
	FALLBACK_SUPPORTED_ATTRIBUTES,
	MODE,
	OVERRIDES_SOURCE,
	applyRows,
	bindEverything,
	collectBindableBlocks,
	expandBindings,
	getBindingMode,
	needsRows,
	withBindings,
} from '../utils/bindings';

beforeAll( () => {
	/*
	 * `@wordpress/block-library` reaches `@wordpress/sync`, which reads
	 * `TextEncoder` at module scope; jsdom does not expose it. Setting it
	 * before the require keeps the polyfill to this suite rather than the
	 * shared Jest config.
	 */
	global.TextEncoder = global.TextEncoder || require( 'util' ).TextEncoder;
	global.TextDecoder = global.TextDecoder || require( 'util' ).TextDecoder;

	require( '@wordpress/block-library' ).registerCoreBlocks();
} );

const OVERRIDDEN_HEADING = `<!-- wp:heading {"metadata":{"name":"headline","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
<h2 class="wp-block-heading">A headline</h2>
<!-- /wp:heading -->`;

const NESTED = `<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph {"metadata":{"name":"eyebrow"}} -->
<p>Eyebrow</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"name":"cta"}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Read more</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->`;

const bindableIn = ( markup ) =>
	collectBindableBlocks( parse( markup ), FALLBACK_SUPPORTED_ATTRIBUTES );

/**
 * Rewrites one bindable block's bindings and returns the resulting markup.
 *
 * Mirrors what the card does on a row change.
 *
 * @param {string}   markup Block markup.
 * @param {Function} change Called with the expanded rows; returns the new rows.
 * @param {number}   index  Which bindable block to change.
 * @return {string} The markup after the change.
 */
function editBindings( markup, change, index = 0 ) {
	const blocks = parse( markup );
	const { block, supported } = collectBindableBlocks(
		blocks,
		FALLBACK_SUPPORTED_ATTRIBUTES
	)[ index ];
	const metadata = block.attributes?.metadata;
	const rows = change( expandBindings( metadata?.bindings, supported ) );

	block.attributes = {
		...block.attributes,
		metadata: withBindings(
			metadata,
			applyRows( metadata?.bindings, rows, supported )
		),
	};

	return serialize( blocks );
}

describe( 'collectBindableBlocks', () => {
	it( 'finds bindable blocks nested inside layout blocks', () => {
		expect(
			bindableIn( NESTED ).map( ( { block } ) => block.name )
		).toEqual( [ 'core/paragraph', 'core/button' ] );
	} );

	it( 'skips layout blocks, which WordPress will not resolve', () => {
		const names = bindableIn( NESTED ).map( ( { block } ) => block.name );

		expect( names ).not.toContain( 'core/group' );
		expect( names ).not.toContain( 'core/buttons' );
	} );
} );

describe( 'markup the panel writes', () => {
	it( 'is unchanged by opening an overridden block and changing nothing', () => {
		expect( editBindings( OVERRIDDEN_HEADING, ( rows ) => rows ) ).toBe(
			OVERRIDDEN_HEADING
		);
	} );

	it( 'keeps __default rather than expanding it into the file', () => {
		const after = editBindings( OVERRIDDEN_HEADING, ( rows ) => rows );

		expect( after ).toContain( '"__default"' );
		expect( after ).not.toContain( '"content":{"source"' );
	} );

	it( 'writes a source binding on one attribute and an override on another', () => {
		const after = editBindings(
			NESTED,
			( rows ) => ( {
				...rows,
				text: { source: OVERRIDES_SOURCE },
				url: { source: 'acf/field', args: { key: 'hero_link' } },
			} ),
			1
		);

		expect( after ).toContain(
			'"bindings":{"url":{"source":"acf/field","args":{"key":"hero_link"}},"text":{"source":"core/pattern-overrides"}}'
		);
		expect( after ).not.toContain( '__default' );
	} );

	it( 'collapses back to __default once every attribute is overridable', () => {
		const after = editBindings(
			NESTED,
			() => ( {
				url: { source: OVERRIDES_SOURCE },
				text: { source: OVERRIDES_SOURCE },
				linkTarget: { source: OVERRIDES_SOURCE },
				rel: { source: OVERRIDES_SOURCE },
			} ),
			1
		);

		expect( after ).toContain(
			'"bindings":{"__default":{"source":"core/pattern-overrides"}}'
		);
	} );

	it( 'removes the bindings key entirely when nothing is bound', () => {
		const after = editBindings( OVERRIDDEN_HEADING, () => ( {} ) );

		expect( after ).not.toContain( 'bindings' );
		expect( after ).toContain( '"metadata":{"name":"headline"}' );
	} );

	it( 'binds everything the way the panel always has', () => {
		const plain = `<!-- wp:heading {"metadata":{"name":"headline"}} -->
<h2 class="wp-block-heading">A headline</h2>
<!-- /wp:heading -->`;

		const blocks = parse( plain );
		const { block, supported } = collectBindableBlocks(
			blocks,
			FALLBACK_SUPPORTED_ATTRIBUTES
		)[ 0 ];

		block.attributes = {
			...block.attributes,
			metadata: withBindings(
				block.attributes.metadata,
				bindEverything( undefined, supported )
			),
		};

		expect( serialize( blocks ) ).toBe( OVERRIDDEN_HEADING );
	} );
} );

describe( 'what the panel shows for that markup', () => {
	const stateOf = ( markup, index = 0 ) => {
		const { block, supported } = bindableIn( markup )[ index ];
		const bindings = block.attributes?.metadata?.bindings;

		return {
			rows: needsRows( bindings, supported ),
			mode: getBindingMode( bindings, supported ),
		};
	};

	it( 'shows an overridden block on the radio, not per attribute', () => {
		expect( stateOf( OVERRIDDEN_HEADING ) ).toEqual( {
			rows: false,
			mode: MODE.OVERRIDES,
		} );
	} );

	it( 'shows a named but unbound block as static', () => {
		expect( stateOf( NESTED ) ).toEqual( {
			rows: false,
			mode: MODE.STATIC,
		} );
	} );

	it( 'forces rows for a block bound to another source', () => {
		const markup = editBindings(
			NESTED,
			( rows ) => ( {
				...rows,
				url: { source: 'acf/field', args: { key: 'hero_link' } },
			} ),
			1
		);

		expect( stateOf( markup, 1 ).rows ).toBe( true );
	} );
} );
