/**
 * Tests for sorting registered binding sources into pickable and typed.
 *
 * The shape tests run against a plain map, because that part is this plugin's
 * own logic. The rest run against the real `@wordpress/blocks` registry, since
 * what they check is an assumption about WordPress itself: that a source's own
 * `getFieldsList` can be called with nothing but a post type.
 */

import {
	getBlockBindingsSources,
	registerBlockBindingsSource,
} from '@wordpress/blocks';
import { select } from '@wordpress/data';

import { OVERRIDES_SOURCE } from '../utils/bindings';
import {
	OPAQUE_ARG,
	collectBindingSources,
} from '../components/bindings/use-binding-sources';

const byName = ( sources, name ) =>
	sources.find( ( source ) => source.name === name );

describe( 'collectBindingSources', () => {
	/**
	 * Fields keyed by their own args key — the shape WordPress 6.8 returns.
	 */
	const keyed = {
		label: 'Keyed Fields',
		usesContext: [ 'postType' ],
		getFieldsList: ( { context } ) =>
			context.postType === 'product'
				? { sku: { label: 'SKU', type: 'string' } }
				: { subtitle: { label: 'Subtitle', type: 'string' } },
	};

	/**
	 * Descriptors carrying their own args — the shape newer versions return.
	 */
	const descriptors = {
		label: 'Descriptor Fields',
		getFieldsList: () => [
			{ label: 'Post Link', type: 'string', args: { field: 'link' } },
			{ label: 'Hero Image', type: 'number', args: { field: 'hero' } },
		],
	};

	const collect = ( sources, postType = 'post' ) =>
		collectBindingSources( sources, select, postType );

	it( 'lists a source that enumerates from a post type alone', () => {
		const { listed } = collect( { 'test/keyed': keyed } );

		expect( listed ).toEqual( [
			{
				name: 'test/keyed',
				label: 'Keyed Fields',
				fields: [
					{
						label: 'Subtitle',
						type: 'string',
						args: { key: 'subtitle' },
					},
				],
			},
		] );
	} );

	it( 're-reads the fields when the post type changes', () => {
		const { listed } = collect( { 'test/keyed': keyed }, 'product' );

		expect( listed[ 0 ].fields ).toEqual( [
			{ label: 'SKU', type: 'string', args: { key: 'sku' } },
		] );
	} );

	it( 'only hands over the context the source asked for', () => {
		const seen = [];
		collect( {
			'test/asks': {
				label: 'Asks',
				usesContext: [ 'postType' ],
				getFieldsList: ( { context } ) => {
					seen.push( context );
					return null;
				},
			},
			'test/silent': {
				label: 'Silent',
				getFieldsList: ( { context } ) => {
					seen.push( context );
					return null;
				},
			},
		} );

		expect( seen ).toEqual( [ { postType: 'post' }, {} ] );
	} );

	it( 'normalizes the descriptor shape without rewriting its args', () => {
		const { listed } = collect( { 'test/descriptors': descriptors } );

		expect( listed[ 0 ].fields ).toEqual( [
			{ label: 'Post Link', type: 'string', args: { field: 'link' } },
			{ label: 'Hero Image', type: 'number', args: { field: 'hero' } },
		] );
	} );

	it( 'treats a source with no field list as one to type a key for', () => {
		const { listed, opaque } = collect( {
			'test/server-only': { label: 'Server Only' },
		} );

		expect( listed ).toEqual( [] );
		expect( opaque ).toEqual( [
			{ name: 'test/server-only', label: 'Server Only' },
		] );
	} );

	it( 'keeps a source that throws, with no fields rather than no entry', () => {
		const { listed } = collect( {
			'test/throws': {
				label: 'Throws',
				getFieldsList: () => {
					throw new Error( 'no post here' );
				},
			},
		} );

		expect( listed ).toEqual( [
			{ name: 'test/throws', label: 'Throws', fields: [] },
		] );
	} );

	it( 'falls back to the source name when it has no label', () => {
		const { opaque } = collect( { 'test/nameless': {} } );

		expect( opaque ).toEqual( [
			{ name: 'test/nameless', label: 'test/nameless' },
		] );
	} );

	it( 'leaves pattern overrides out, since the panel offers it directly', () => {
		const { listed, opaque } = collect( {
			[ OVERRIDES_SOURCE ]: { label: 'Pattern Overrides' },
		} );

		expect( listed ).toEqual( [] );
		expect( opaque ).toEqual( [] );
	} );
} );

describe( 'against the WordPress registry', () => {
	/*
	 * `core/post-meta` is the one source WordPress lets publish a field list on
	 * every version: through `@wordpress/blocks` 15.6 the store keeps
	 * `getFieldsList` only for that name unless the Gutenberg plugin is
	 * running, and 16.0 opens it to every source. Registering it under its real
	 * name is what exercises the path the post type lens depends on.
	 */
	let postMetaContext;

	beforeAll( () => {
		registerBlockBindingsSource( {
			name: 'core/post-meta',
			label: 'Post Meta',
			usesContext: [ 'postType', 'postId' ],
			getFieldsList: ( { context } ) => {
				postMetaContext = context;
				return { subtitle: { label: 'Subtitle', type: 'string' } };
			},
		} );

		registerBlockBindingsSource( {
			name: 'test/third-party',
			label: 'Third Party',
			getFieldsList: () => [
				{ label: 'Anything', type: 'string', args: { key: 'a' } },
			],
		} );
	} );

	const collect = ( postType = 'post' ) =>
		collectBindingSources( getBlockBindingsSources(), select, postType );

	it( 'enumerates post meta from the lens post type, with no post', () => {
		const source = byName( collect( 'product' ).listed, 'core/post-meta' );

		expect( source.fields ).toEqual( [
			{ label: 'Subtitle', type: 'string', args: { key: 'subtitle' } },
		] );
		expect( postMetaContext ).toEqual( { postType: 'product' } );
		expect( postMetaContext.postId ).toBeUndefined();
	} );

	it( 'offers a third-party source whichever list WordPress allows it', () => {
		const { listed, opaque } = collect();

		expect(
			byName( listed, 'test/third-party' ) ||
				byName( opaque, 'test/third-party' )
		).toBeDefined();
	} );

	it( 'reads every source WordPress has registered, not a fixed list', () => {
		const { listed, opaque } = collect();
		const offered = [ ...listed, ...opaque ].map(
			( source ) => source.name
		);

		Object.keys( getBlockBindingsSources() )
			.filter( ( name ) => name !== OVERRIDES_SOURCE )
			.forEach( ( name ) => expect( offered ).toContain( name ) );
	} );
} );

describe( 'OPAQUE_ARG', () => {
	it( 'is the argument name core documents for a server-side source', () => {
		expect( OPAQUE_ARG ).toBe( 'key' );
	} );
} );
