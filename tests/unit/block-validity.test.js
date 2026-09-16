/**
 * The upload gate: the editor's own block validation, run before a pattern leaves the site.
 */

import { registerBlockType, unregisterBlockType } from '@wordpress/blocks';

import {
	findInvalidBlocks,
	findOutdatedBlocks,
	describeBlocks,
} from '../../src/utils/blockValidity';

const BOX = 'pattern-builder-test/box';
const STACK = 'pattern-builder-test/stack';
const KEEPS = 'pattern-builder-test/keeps';
const DROPS = 'pattern-builder-test/drops';
const MOVES = 'pattern-builder-test/moves';

beforeAll( () => {
	registerBlockType( BOX, {
		apiVersion: 3,
		title: 'Box',
		category: 'text',
		attributes: {
			text: { type: 'string', source: 'html', selector: 'p' },
		},
		save: ( { attributes } ) => <p>{ attributes.text }</p>,
	} );
	registerBlockType( STACK, {
		apiVersion: 3,
		title: 'Stack',
		category: 'text',
		save: () => null,
	} );

	const sized = {
		text: { type: 'string', source: 'html', selector: 'p' },
		size: { type: 'string' },
		metadata: { type: 'object' },
	};

	registerBlockType( KEEPS, {
		apiVersion: 3,
		title: 'Keeps',
		category: 'text',
		attributes: sized,
		save: ( { attributes } ) => (
			<p
				className={
					attributes.size
						? `has-${ attributes.size }-size`
						: undefined
				}
			>
				{ attributes.text }
			</p>
		),
		deprecated: [
			{
				attributes: sized,
				save: ( { attributes } ) => <p>{ attributes.text }</p>,
			},
		],
	} );
	registerBlockType( MOVES, {
		apiVersion: 3,
		title: 'Moves',
		category: 'text',
		attributes: {
			text: sized.text,
			style: { type: 'object' },
		},
		save: ( { attributes } ) => (
			<p
				className={
					attributes.style?.typography?.size
						? `has-${ attributes.style.typography.size }-size`
						: undefined
				}
			>
				{ attributes.text }
			</p>
		),
		deprecated: [
			{
				attributes: sized,
				save: ( { attributes } ) => (
					<p
						className={
							attributes.size
								? `has-${ attributes.size }-size`
								: undefined
						}
					>
						{ attributes.text }
					</p>
				),
				migrate: ( { size, ...rest } ) => ( {
					...rest,
					style: { typography: { size } },
				} ),
			},
		],
	} );

	registerBlockType( DROPS, {
		apiVersion: 3,
		title: 'Drops',
		category: 'text',
		attributes: sized,
		save: ( { attributes } ) => (
			<p
				className={
					attributes.size
						? `has-${ attributes.size }-size`
						: undefined
				}
			>
				{ attributes.text }
			</p>
		),
		deprecated: [
			{
				attributes: { text: sized.text },
				save: ( { attributes } ) => <p>{ attributes.text }</p>,
			},
		],
	} );
} );

afterAll( () => {
	unregisterBlockType( BOX );
	unregisterBlockType( STACK );
	unregisterBlockType( KEEPS );
	unregisterBlockType( DROPS );
	unregisterBlockType( MOVES );
} );

const box = ( html ) => `<!-- wp:${ BOX } -->${ html }<!-- /wp:${ BOX } -->`;

describe( 'findInvalidBlocks', () => {
	it( 'passes markup the block type would have written', () => {
		expect( findInvalidBlocks( box( '<p>Hello</p>' ) ) ).toEqual( [] );
	} );

	it( 'reports markup the block type would not have written', () => {
		const invalid = findInvalidBlocks( box( '<div>Hello</div>' ) );

		expect( invalid ).toHaveLength( 1 );
		expect( invalid[ 0 ].name ).toBe( BOX );
		expect( console ).toHaveWarned();
		expect( console ).toHaveErrored();
	} );

	it( 'looks inside inner blocks', () => {
		const markup = `<!-- wp:${ STACK } -->${ box(
			'<div>Hello</div>'
		) }<!-- /wp:${ STACK } -->`;

		expect( findInvalidBlocks( markup ) ).toHaveLength( 1 );
		expect( console ).toHaveWarned();
		expect( console ).toHaveErrored();
	} );

	it( 'leaves block types this site does not have alone', () => {
		const markup =
			'<!-- wp:some-plugin/thing --><div>Hi</div><!-- /wp:some-plugin/thing -->';

		expect( findInvalidBlocks( markup ) ).toEqual( [] );
	} );

	it( 'has nothing to say about empty content', () => {
		expect( findInvalidBlocks( '' ) ).toEqual( [] );
		expect( findInvalidBlocks( undefined ) ).toEqual( [] );
	} );
} );

describe( 'findOutdatedBlocks', () => {
	it( 'says nothing about markup the block writes today', () => {
		const markup = `<!-- wp:${ KEEPS } {"size":"large"} --><p class="has-large-size">Hi</p><!-- /wp:${ KEEPS } -->`;

		expect( findInvalidBlocks( markup ) ).toEqual( [] );
		expect( findOutdatedBlocks( markup ) ).toEqual( [] );
	} );

	it( 'reports markup that only matches a deprecated version', () => {
		const markup = `<!-- wp:${ KEEPS } {"size":"large"} --><p>Hi</p><!-- /wp:${ KEEPS } -->`;
		expect( findInvalidBlocks( markup ) ).toEqual( [] );

		const outdated = findOutdatedBlocks( markup );
		expect( outdated ).toHaveLength( 1 );
		expect( outdated[ 0 ].name ).toBe( KEEPS );
		expect( outdated[ 0 ].reason ).toBe( 'old-form' );
		expect( console ).toHaveInformed();
	} );

	it( 'reports an attribute the migration threw away', () => {
		const markup = `<!-- wp:${ DROPS } {"size":"large"} --><p>Hi</p><!-- /wp:${ DROPS } -->`;

		const outdated = findOutdatedBlocks( markup );
		expect( outdated ).toHaveLength( 1 );
		expect( outdated[ 0 ].name ).toBe( DROPS );
		expect( outdated[ 0 ].reason ).toBe( 'dropped-attribute' );
		expect( outdated[ 0 ].dropped ).toEqual( [ 'size' ] );
		expect( console ).toHaveInformed();
	} );

	it( 'does not call a relocated attribute a lost one', () => {
		const markup = `<!-- wp:${ MOVES } {"size":"large"} --><p class="has-large-size">Hi</p><!-- /wp:${ MOVES } -->`;
		expect( findOutdatedBlocks( markup ) ).toEqual( [] );
		expect( console ).toHaveInformed();
	} );

	it( 'leaves a block with bindings alone', () => {
		const bound =
			'{"size":"large","metadata":{"bindings":{"__default":{"source":"core/pattern-overrides"}}}}';
		const markup = `<!-- wp:${ KEEPS } ${ bound } --><p>Hi</p><!-- /wp:${ KEEPS } -->`;

		expect( findOutdatedBlocks( markup ) ).toEqual( [] );
		expect( console ).toHaveInformed();
	} );

	it( 'looks inside inner blocks', () => {
		const markup = `<!-- wp:${ STACK } --><!-- wp:${ KEEPS } {"size":"large"} --><p>Hi</p><!-- /wp:${ KEEPS } --><!-- /wp:${ STACK } -->`;

		expect( findOutdatedBlocks( markup ) ).toHaveLength( 1 );
		expect( console ).toHaveInformed();
	} );

	it( 'leaves block types this site does not have alone', () => {
		const markup =
			'<!-- wp:some-plugin/thing {"size":"large"} --><div>Hi</div><!-- /wp:some-plugin/thing -->';

		expect( findOutdatedBlocks( markup ) ).toEqual( [] );
	} );

	it( 'has nothing to say about empty content', () => {
		expect( findOutdatedBlocks( '' ) ).toEqual( [] );
		expect( findOutdatedBlocks( undefined ) ).toEqual( [] );
	} );
} );

describe( 'describeBlocks', () => {
	it( 'names each block once, with a count when it repeats', () => {
		const described = describeBlocks( [
			{ name: 'core/heading', title: 'heading' },
			{ name: 'core/heading', title: 'heading' },
			{ name: 'core/list', title: 'list' },
		] );

		expect( described ).toBe( 'heading (2), list' );
	} );
} );
