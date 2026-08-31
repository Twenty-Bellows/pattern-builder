/**
 * The upload gate: the editor's own block validation, run before a pattern
 * leaves the site. Markup a block type would not have written itself renders
 * correctly but reads as "unexpected or invalid content" the moment an editor
 * opens it, so it must never reach the cloud library.
 */

import { registerBlockType, unregisterBlockType } from '@wordpress/blocks';

import {
	findInvalidBlocks,
	describeInvalidBlocks,
} from '../../src/utils/blockValidity';

const BOX = 'pattern-builder-test/box';
const STACK = 'pattern-builder-test/stack';

beforeAll( () => {
	registerBlockType( BOX, {
		title: 'Box',
		category: 'text',
		attributes: {
			text: { type: 'string', source: 'html', selector: 'p' },
		},
		save: ( { attributes } ) => <p>{ attributes.text }</p>,
	} );

	// Rendered server-side, so nothing to validate: a container to nest in.
	registerBlockType( STACK, {
		title: 'Stack',
		category: 'text',
		save: () => null,
	} );
} );

afterAll( () => {
	unregisterBlockType( BOX );
	unregisterBlockType( STACK );
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
		// The validator says why on the console, exactly as it does when the
		// editor opens the same markup.
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
		// An unregistered type parses to core/missing, which is the service's
		// allowlist to rule on, not a fault in the markup.
		const markup =
			'<!-- wp:some-plugin/thing --><div>Hi</div><!-- /wp:some-plugin/thing -->';

		expect( findInvalidBlocks( markup ) ).toEqual( [] );
	} );

	it( 'has nothing to say about empty content', () => {
		expect( findInvalidBlocks( '' ) ).toEqual( [] );
		expect( findInvalidBlocks( undefined ) ).toEqual( [] );
	} );
} );

describe( 'describeInvalidBlocks', () => {
	it( 'names each block once, with a count when it repeats', () => {
		const described = describeInvalidBlocks( [
			{ name: 'core/heading', title: 'heading' },
			{ name: 'core/heading', title: 'heading' },
			{ name: 'core/list', title: 'list' },
		] );

		expect( described ).toBe( 'heading (2), list' );
	} );
} );
