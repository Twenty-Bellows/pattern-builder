/**
 * Round-trips block markup through the panel's logic.
 *
 * The unit tests cover the binding rules on plain objects. These cover the
 * claim those rules exist to make: that opening a pattern in this panel and
 * changing nothing leaves its bindings exactly as they were, and that a change
 * writes the block comment WordPress will resolve.
 *
 * The block types are registered here rather than pulled from
 * `@wordpress/block-library`, which reaches the whole block editor and an
 * untransformable dependency with it. Their attributes mirror the real ones —
 * including `metadata`, which core adds by filter — so what is exercised is
 * `@wordpress/blocks`' own parse and serialize of the attribute this panel
 * writes, without the weight of every core block's markup.
 */

import { parse, registerBlockType, serialize } from '@wordpress/blocks';

import {
	FALLBACK_SUPPORTED_ATTRIBUTES,
	MODE,
	OVERRIDES_SOURCE,
	applyRows,
	bindEverything,
	collectBindableBlocks,
	expandBindings,
	getBindingMode,
	withBindings,
} from '../utils/bindings';

const METADATA = { type: 'object' };

beforeAll( () => {
	registerBlockType( 'core/paragraph', {
		title: 'Paragraph',
		category: 'text',
		attributes: { metadata: METADATA },
		save: () => null,
	} );

	registerBlockType( 'core/heading', {
		title: 'Heading',
		category: 'text',
		attributes: { metadata: METADATA },
		save: () => null,
	} );

	registerBlockType( 'core/button', {
		title: 'Button',
		category: 'design',
		attributes: {
			url: { type: 'string' },
			linkTarget: { type: 'string' },
			rel: { type: 'string' },
			metadata: METADATA,
		},
		save: () => null,
	} );

	[ 'core/group', 'core/buttons' ].forEach( ( name ) =>
		registerBlockType( name, {
			title: name,
			category: 'design',
			attributes: {},
			save: () => null,
		} )
	);
} );

/**
 * The bindable blocks of some markup, as the panel would list them.
 *
 * @param {string} markup Block markup.
 * @return {Array<{block: Object, supported: string[]}>} The bindable blocks.
 */
const bindableIn = ( markup ) =>
	collectBindableBlocks( parse( markup ), FALLBACK_SUPPORTED_ATTRIBUTES );

/**
 * Rewrites one bindable block's bindings and returns the resulting markup.
 *
 * Mirrors what `BindableBlockCard` does on a row change: expand the block's
 * bindings into the per-attribute view, change one, write it back through
 * `applyRows`.
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

const OVERRIDDEN_HEADING =
	'<!-- wp:heading {"metadata":{"name":"headline","bindings":{"__default":{"source":"core/pattern-overrides"}}}} /-->';

const BUTTON = '<!-- wp:button {"metadata":{"name":"cta"}} /-->';

const NESTED = `<!-- wp:group -->
<!-- wp:paragraph {"metadata":{"name":"eyebrow"}} /-->
<!-- wp:buttons -->
<!-- wp:button {"metadata":{"name":"cta"}} /-->
<!-- /wp:buttons -->
<!-- /wp:group -->`;

describe( 'collectBindableBlocks', () => {
	it( 'finds bindable blocks nested inside layout blocks', () => {
		expect(
			bindableIn( NESTED ).map( ( { block } ) => block.name )
		).toEqual( [ 'core/paragraph', 'core/button' ] );
	} );

	it( 'reports the attributes each block type can bind', () => {
		const found = bindableIn( NESTED );

		expect( found[ 0 ].supported ).toEqual( [ 'content' ] );
		expect( found[ 1 ].supported ).toEqual( [
			'url',
			'text',
			'linkTarget',
			'rel',
		] );
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
		const after = editBindings( BUTTON, ( rows ) => ( {
			...rows,
			text: { source: OVERRIDES_SOURCE },
			url: { source: 'acf/field', args: { key: 'hero_link' } },
		} ) );

		expect( after ).toContain(
			'"bindings":{"url":{"source":"acf/field","args":{"key":"hero_link"}},"text":{"source":"core/pattern-overrides"}}'
		);
		expect( after ).not.toContain( '__default' );
	} );

	it( 'collapses back to __default once every attribute is overridable', () => {
		const after = editBindings( BUTTON, () => ( {
			url: { source: OVERRIDES_SOURCE },
			text: { source: OVERRIDES_SOURCE },
			linkTarget: { source: OVERRIDES_SOURCE },
			rel: { source: OVERRIDES_SOURCE },
		} ) );

		expect( after ).toContain(
			'"bindings":{"__default":{"source":"core/pattern-overrides"}}'
		);
	} );

	it( 'removes the bindings key entirely when nothing is bound', () => {
		const after = editBindings( OVERRIDDEN_HEADING, () => ( {} ) );

		expect( after ).not.toContain( 'bindings' );
		expect( after ).toContain( '"metadata":{"name":"headline"}' );
	} );

	it( 'leaves no empty metadata behind on an unnamed block', () => {
		const markup =
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"acf/field","args":{"key":"x"}}}}} /-->';

		expect( editBindings( markup, () => ( {} ) ) ).toBe(
			'<!-- wp:paragraph /-->'
		);
	} );

	it( 'binds everything the way the panel always has', () => {
		const blocks = parse(
			'<!-- wp:heading {"metadata":{"name":"headline"}} /-->'
		);
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

describe( 'the mode the panel opens on', () => {
	const modeOf = ( markup, index = 0 ) => {
		const { block, supported } = bindableIn( markup )[ index ];

		return getBindingMode(
			block.attributes?.metadata?.bindings,
			supported
		);
	};

	it( 'shows an overridden block as overridable, not as per-attribute', () => {
		expect( modeOf( OVERRIDDEN_HEADING ) ).toBe( MODE.OVERRIDES );
	} );

	it( 'shows a named but unbound block as static', () => {
		expect( modeOf( NESTED ) ).toBe( MODE.STATIC );
	} );

	it( 'shows a block with one source binding as dynamic', () => {
		const markup = editBindings( BUTTON, ( rows ) => ( {
			...rows,
			url: { source: 'acf/field', args: { key: 'hero_link' } },
		} ) );

		expect( modeOf( markup ) ).toBe( MODE.DYNAMIC );
	} );

	it( 'still reads a hand-expanded block as overridable', () => {
		expect(
			modeOf(
				'<!-- wp:heading {"metadata":{"name":"headline","bindings":{"content":{"source":"core/pattern-overrides"}}}} /-->'
			)
		).toBe( MODE.OVERRIDES );
	} );
} );
