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
	USER_INPUT_ONLY,
	collectBindingSources,
} from '../components/bindings/use-binding-sources';

const POST_META_SOURCE = 'core/post-meta';

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

	const collect = ( sources, postType = 'post', declared ) =>
		collectBindingSources( sources, select, postType, declared );

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
		 * WordPress 6.8 kept `getFieldsList` only for this name unless the
		 * Gutenberg plugin was running, a restriction @wordpress/blocks 15.7
		 * lifted. Registering under the real name exercises the path the lens
		 * uses on every version.
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

describe( 'fields declared through the PHP filter', () => {
	const collect = ( sources, declared ) =>
		collectBindingSources( sources, select, 'post', declared );

	it( 'gives a source with no field list something to offer', () => {
		const sources = collect(
			{ 'acme/field': { label: 'Acme Field' } },
			{
				'acme/field': [
					{
						label: 'Hero link',
						args: { key: 'hero_link' },
						type: 'string',
					},
				],
			}
		);

		expect( sources[ 0 ].fields ).toEqual( [
			{ label: 'Hero link', type: 'string', args: { key: 'hero_link' } },
		] );
	} );

	it( 'adds to the fields a source published itself', () => {
		const sources = collect(
			{
				'test/partial': {
					label: 'Partial',
					getFieldsList: () => [
						{
							label: 'Published',
							args: { key: 'a' },
							type: 'string',
						},
					],
				},
			},
			{
				'test/partial': [
					{ label: 'Declared', args: { key: 'b' }, type: 'string' },
				],
			}
		);

		expect( sources[ 0 ].fields.map( ( field ) => field.label ) ).toEqual( [
			'Published',
			'Declared',
		] );
	} );

	it( 'ignores a declaration for a source that is not registered', () => {
		const sources = collect(
			{ 'test/only': { label: 'Only' } },
			{ 'test/absent': [ { label: 'X', args: { key: 'x' } } ] }
		);

		expect( sources.map( ( source ) => source.name ) ).toEqual( [
			'test/only',
		] );
	} );

	it( 'drops a declared field with no args to bind to', () => {
		const sources = collect(
			{ 'acme/field': { label: 'Acme Field' } },
			{ 'acme/field': [ { label: 'Broken' } ] }
		);

		expect( sources[ 0 ].fields ).toEqual( [] );
	} );
} );
