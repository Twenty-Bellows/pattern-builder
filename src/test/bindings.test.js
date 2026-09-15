/**
 * Tests for reading and writing a block's `metadata.bindings`.
 */

import {
	DEFAULT_ATTRIBUTE,
	MODE,
	OVERRIDES_SOURCE,
	applyRows,
	bindEverything,
	bindNothing,
	expandBindings,
	getAttributeType,
	getBindingMode,
	hasDefaultOverrides,
	isSameBinding,
	normalizeFields,
	withBindings,
} from '../utils/bindings';

const BUTTON = [ 'url', 'text', 'linkTarget', 'rel' ];
const HEADING = [ 'content' ];

const overridden = { source: OVERRIDES_SOURCE };
const subtitle = {
	source: 'core/post-meta',
	args: { key: 'subtitle' },
};

describe( 'expandBindings', () => {
	it( 'turns __default into one override per supported attribute', () => {
		expect(
			expandBindings( { [ DEFAULT_ATTRIBUTE ]: overridden }, BUTTON )
		).toEqual( {
			url: overridden,
			text: overridden,
			linkTarget: overridden,
			rel: overridden,
		} );
	} );

	it( 'keeps an explicit binding that sits alongside __default', () => {
		const expanded = expandBindings(
			{ [ DEFAULT_ATTRIBUTE ]: overridden, url: subtitle },
			BUTTON
		);

		expect( expanded.url ).toEqual( subtitle );
		expect( expanded.text ).toEqual( overridden );
	} );

	it( 'leaves unsupported attributes out of the view', () => {
		expect(
			expandBindings( { content: subtitle, legacy: subtitle }, HEADING )
		).toEqual( { content: subtitle } );
	} );

	it( 'returns nothing for a block with no bindings', () => {
		expect( expandBindings( undefined, HEADING ) ).toEqual( {} );
	} );
} );

describe( 'getBindingMode', () => {
	it( 'reads no bindings as static', () => {
		expect( getBindingMode( undefined, HEADING ) ).toBe( MODE.STATIC );
		expect( getBindingMode( {}, HEADING ) ).toBe( MODE.STATIC );
	} );

	it( 'reads __default as overrides', () => {
		expect(
			getBindingMode( { [ DEFAULT_ATTRIBUTE ]: overridden }, BUTTON )
		).toBe( MODE.OVERRIDES );
	} );

	it( 'reads a fully expanded block as overrides too', () => {
		const expanded = {
			url: overridden,
			text: overridden,
			linkTarget: overridden,
			rel: overridden,
		};

		expect( getBindingMode( expanded, BUTTON ) ).toBe( MODE.OVERRIDES );
	} );

	it( 'reads a partly overridden block as dynamic', () => {
		expect( getBindingMode( { text: overridden }, BUTTON ) ).toBe(
			MODE.DYNAMIC
		);
	} );

	it( 'reads any source other than overrides as dynamic', () => {
		expect( getBindingMode( { content: subtitle }, HEADING ) ).toBe(
			MODE.DYNAMIC
		);
	} );

	it( 'reads __default plus a source binding as dynamic', () => {
		expect(
			getBindingMode(
				{ [ DEFAULT_ATTRIBUTE ]: overridden, url: subtitle },
				BUTTON
			)
		).toBe( MODE.DYNAMIC );
	} );
} );

describe( 'applyRows', () => {
	it( 'collapses an all-overridable block back to __default', () => {
		expect(
			applyRows(
				null,
				{
					url: overridden,
					text: overridden,
					linkTarget: overridden,
					rel: overridden,
				},
				BUTTON
			)
		).toEqual( { [ DEFAULT_ATTRIBUTE ]: overridden } );
	} );

	it( 'writes attributes explicitly when they differ', () => {
		expect(
			applyRows( null, { text: overridden, url: subtitle }, BUTTON )
		).toEqual( { text: overridden, url: subtitle } );
	} );

	it( 'drops the attributes left out of the rows', () => {
		expect(
			applyRows(
				{ [ DEFAULT_ATTRIBUTE ]: overridden },
				{ text: overridden },
				BUTTON
			)
		).toEqual( { text: overridden } );
	} );

	it( 'returns undefined when nothing is bound', () => {
		expect(
			applyRows( { [ DEFAULT_ATTRIBUTE ]: overridden }, {}, BUTTON )
		).toBeUndefined();
	} );

	it( 'preserves bindings on attributes it does not manage', () => {
		expect(
			applyRows( { legacy: subtitle }, { content: overridden }, HEADING )
		).toEqual( { legacy: subtitle, [ DEFAULT_ATTRIBUTE ]: overridden } );
	} );

	it( 'round-trips an expanded block back to how it was written', () => {
		const original = { [ DEFAULT_ATTRIBUTE ]: overridden };
		const expanded = expandBindings( original, BUTTON );

		expect( applyRows( original, expanded, BUTTON ) ).toEqual( original );
	} );
} );

describe( 'bindEverything / bindNothing', () => {
	it( 'binds every supported attribute as one __default', () => {
		expect( bindEverything( null, BUTTON ) ).toEqual( {
			[ DEFAULT_ATTRIBUTE ]: overridden,
		} );
	} );

	it( 'unbinds everything it manages', () => {
		expect( bindNothing( { content: subtitle }, HEADING ) ).toBeUndefined();
	} );

	it( 'still leaves foreign bindings alone when unbinding', () => {
		expect( bindNothing( { legacy: subtitle }, HEADING ) ).toEqual( {
			legacy: subtitle,
		} );
	} );
} );

describe( 'hasDefaultOverrides', () => {
	it( 'only counts __default bound to pattern overrides', () => {
		expect(
			hasDefaultOverrides( { [ DEFAULT_ATTRIBUTE ]: overridden } )
		).toBe( true );
		expect(
			hasDefaultOverrides( { [ DEFAULT_ATTRIBUTE ]: subtitle } )
		).toBe( false );
		expect( hasDefaultOverrides( undefined ) ).toBe( false );
	} );
} );

describe( 'withBindings', () => {
	it( 'keeps the rest of the metadata', () => {
		expect(
			withBindings(
				{ name: 'headline', bindings: {} },
				{ content: subtitle }
			)
		).toEqual( { name: 'headline', bindings: { content: subtitle } } );
	} );

	it( 'removes the bindings key when there are none', () => {
		expect( withBindings( { name: 'headline' }, undefined ) ).toEqual( {
			name: 'headline',
		} );
	} );

	it( 'returns undefined rather than an empty metadata object', () => {
		expect(
			withBindings( { bindings: { content: subtitle } }, undefined )
		).toBeUndefined();
	} );
} );

describe( 'getAttributeType', () => {
	const blockType = {
		attributes: {
			content: { type: 'rich-text' },
			url: { type: 'string' },
			id: { type: 'number' },
		},
	};

	it( 'reports rich-text as string, the way core matches fields', () => {
		expect( getAttributeType( blockType, 'content' ) ).toBe( 'string' );
	} );

	it( 'reports other types unchanged', () => {
		expect( getAttributeType( blockType, 'url' ) ).toBe( 'string' );
		expect( getAttributeType( blockType, 'id' ) ).toBe( 'number' );
	} );

	it( 'reports nothing for an attribute it cannot find', () => {
		expect( getAttributeType( blockType, 'nope' ) ).toBeUndefined();
		expect( getAttributeType( undefined, 'url' ) ).toBeUndefined();
	} );
} );

describe( 'normalizeFields', () => {
	it( 'reads the keyed object WordPress 6.8 returns', () => {
		expect(
			normalizeFields( {
				subtitle: { label: 'Subtitle', type: 'string' },
				deck: { type: 'string' },
			} )
		).toEqual( [
			{ label: 'Subtitle', type: 'string', args: { key: 'subtitle' } },
			{ label: 'deck', type: 'string', args: { key: 'deck' } },
		] );
	} );

	it( 'reads the descriptor array newer versions return', () => {
		expect(
			normalizeFields( [
				{
					label: 'Post Link',
					type: 'string',
					args: { field: 'link' },
					default: 'unused',
				},
			] )
		).toEqual( [
			{ label: 'Post Link', type: 'string', args: { field: 'link' } },
		] );
	} );

	it( 'skips descriptors with no args to bind to', () => {
		expect( normalizeFields( [ { label: 'Broken' } ] ) ).toEqual( [] );
	} );

	it( 'reads an absent list as no fields', () => {
		expect( normalizeFields( undefined ) ).toEqual( [] );
		expect( normalizeFields( null ) ).toEqual( [] );
	} );
} );

describe( 'isSameBinding', () => {
	it( 'matches a source and its args', () => {
		expect( isSameBinding( subtitle, { ...subtitle } ) ).toBe( true );
	} );

	it( 'separates two fields of the same source', () => {
		expect(
			isSameBinding( subtitle, {
				source: 'core/post-meta',
				args: { key: 'deck' },
			} )
		).toBe( false );
	} );

	it( 'treats missing args as no args', () => {
		expect(
			isSameBinding( overridden, { source: OVERRIDES_SOURCE } )
		).toBe( true );
	} );
} );
