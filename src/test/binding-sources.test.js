/**
 * Tests for collecting the registered binding sources.
 *
 * The shape tests run against a plain map. The rest run against the real
 * `@wordpress/blocks` registry, because what they check is an assumption about
 * WordPress: that a source's own `getFieldsList` can be called with nothing
 * but a post type.
 */

import {
	getBlockBindingsSources,
	registerBlockBindingsSource,
} from '@wordpress/blocks';
import { select } from '@wordpress/data';

import { OVERRIDES_SOURCE } from '../utils/bindings';
import {
	DEFAULT_ARG,
	POST_META_SOURCE,
	USER_INPUT_ONLY,
	collectBindingSources,
} from '../components/bindings/use-binding-sources';

const byName = ( sources, name ) =>
	sources.find( ( source ) => source.name === name );

describe( 'collectBindingSources', () => {
	const keyed = {
		label: 'Keyed Fields',
		usesContext: [ 'postType' ],
		getFieldsList: ( { context } ) =>
			context.postType === 'product'
				? { sku: { label: 'SKU', type: 'string' } }
				: { subtitle: { label: 'Subtitle', type: 'string' } },
	};

	const collect = ( sources, postType = 'post' ) =>
		collectBindingSources( sources, select, postType );

	it( 'offers nothing at all when no post type is chosen', () => {
		expect( collect( { 'test/keyed': keyed }, USER_INPUT_ONLY ) ).toEqual(
			[]
		);
	} );

	it( 'lists a source that enumerates from a post type alone', () => {
		expect( collect( { 'test/keyed': keyed } ) ).toEqual( [
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
		expect(
			collect( { 'test/keyed': keyed }, 'product' )[ 0 ].fields
		).toEqual( [ { label: 'SKU', type: 'string', args: { key: 'sku' } } ] );
	} );

	it( 'hands the post type to a source that never declared it', () => {
		let seen;
		collect( {
			'test/silent': {
				label: 'Silent',
				getFieldsList: ( { context } ) => {
					seen = context;
					return null;
				},
			},
		} );

		expect( seen ).toEqual( { postType: 'post' } );
	} );

	it( 'normalizes the descriptor shape without rewriting its args', () => {
		const sources = collect( {
			'test/descriptors': {
				label: 'Descriptors',
				getFieldsList: () => [
					{
						label: 'Post Link',
						type: 'string',
						args: { field: 'link' },
					},
				],
			},
		} );

		expect( sources[ 0 ].fields ).toEqual( [
			{ label: 'Post Link', type: 'string', args: { field: 'link' } },
		] );
	} );

	it( 'keeps a source that publishes no field list, with no fields', () => {
		expect(
			collect( { 'test/server-only': { label: 'Server Only' } } )
		).toEqual( [
			{ name: 'test/server-only', label: 'Server Only', fields: [] },
		] );
	} );

	it( 'keeps a source that throws, with no fields rather than no entry', () => {
		expect(
			collect( {
				'test/throws': {
					label: 'Throws',
					getFieldsList: () => {
						throw new Error( 'no post here' );
					},
				},
			} )
		).toEqual( [ { name: 'test/throws', label: 'Throws', fields: [] } ] );
	} );

	it( 'falls back to the source name when it has no label', () => {
		expect( collect( { 'test/nameless': {} } ) ).toEqual( [
			{ name: 'test/nameless', label: 'test/nameless', fields: [] },
		] );
	} );

	it( 'leaves pattern overrides out, since the panel offers it directly', () => {
		expect(
			collect( { [ OVERRIDES_SOURCE ]: { label: 'Pattern Overrides' } } )
		).toEqual( [] );
	} );
} );

describe( 'against the WordPress registry', () => {
	let postMetaContext;

	beforeAll( () => {
		/*
		 * Through @wordpress/blocks 15.6 the store keeps `getFieldsList` only
		 * for this name unless the Gutenberg plugin is running, so registering
		 * it under its real name is what exercises the path the lens uses.
		 */
		registerBlockBindingsSource( {
			name: POST_META_SOURCE,
			label: 'Post Meta',
			usesContext: [ 'postType', 'postId' ],
			getFieldsList: ( { context } ) => {
				postMetaContext = context;
				return { subtitle: { label: 'Subtitle', type: 'string' } };
			},
		} );

		registerBlockBindingsSource( {
			name: 'test/no-fields-here',
			label: 'No Fields Here',
			getFieldsList: () => [],
		} );
	} );

	const collect = ( postType = 'post' ) =>
		collectBindingSources( getBlockBindingsSources(), select, postType );

	it( 'enumerates post meta from the lens post type, with no post', () => {
		const source = byName( collect( 'product' ), POST_META_SOURCE );

		expect( source.fields ).toEqual( [
			{ label: 'Subtitle', type: 'string', args: { key: 'subtitle' } },
		] );
		expect( postMetaContext ).toEqual( { postType: 'product' } );
		expect( postMetaContext.postId ).toBeUndefined();
	} );

	it( 'still offers a source that published nothing', () => {
		const source = byName( collect(), 'test/no-fields-here' );

		expect( source ).toBeDefined();
		expect( source.fields ).toEqual( [] );
	} );

	it( 'reads every source WordPress has registered, not a fixed list', () => {
		const offered = collect().map( ( source ) => source.name );

		Object.keys( getBlockBindingsSources() )
			.filter( ( name ) => name !== OVERRIDES_SOURCE )
			.forEach( ( name ) => expect( offered ).toContain( name ) );
	} );
} );

describe( 'DEFAULT_ARG', () => {
	it( 'is the argument name core documents for a server-side source', () => {
		expect( DEFAULT_ARG ).toBe( 'key' );
	} );
} );
