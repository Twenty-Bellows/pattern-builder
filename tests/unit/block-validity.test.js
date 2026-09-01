/**
 * The upload gate: the editor's own block validation, run before a pattern
 * leaves the site. Markup a block type would not have written itself renders
 * correctly but reads as "unexpected or invalid content" the moment an editor
 * opens it, so it must never reach the cloud library.
 */

import { registerBlockType, unregisterBlockType } from '@wordpress/blocks';

import {
	findInvalidBlocks,
	findOutdatedBlocks,
	describeBlocks,
} from '../../src/utils/blockValidity';

const BOX = 'pattern-builder-test/box';
const STACK = 'pattern-builder-test/stack';

/*
 * Two blocks that have moved on since markup was written against them. Every
 * block keeps its old save() implementations so existing content keeps
 * working, and the parser tries all of them — which is why markup can be
 * accepted and still not be what the block writes today. The difference
 * between these two is whether the old version knew about the attribute:
 * KEEPS carries it through and merely writes it differently, DROPS never had
 * it and so throws it away.
 */
const KEEPS = 'pattern-builder-test/keeps';
const DROPS = 'pattern-builder-test/drops';
const MOVES = 'pattern-builder-test/moves';

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

	const sized = {
		text: { type: 'string', source: 'html', selector: 'p' },
		size: { type: 'string' },
		/*
		 * In wp-admin every block gets `metadata` — it is where bindings live
		 * — from a filter in the editor package, which jest has no transform
		 * for. Declaring it here puts this fixture in the same shape the real
		 * blocks are in when this code runs.
		 */
		metadata: { type: 'object' },
	};

	registerBlockType( KEEPS, {
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

	/*
	 * Core relocates settings as well as discarding them — block library 10.5
	 * moved text alignment out of a paragraph's `align` and into a typography
	 * support, migrating the value to `style.typography.textAlign`. The
	 * top-level key is gone and the setting is entirely intact, so that must
	 * not be reported as a loss.
	 */
	registerBlockType( MOVES, {
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
				// The old version had no size at all, so migrating to it
				// discards whatever the author wrote.
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

describe( 'findOutdatedBlocks', () => {
	it( 'says nothing about markup the block writes today', () => {
		const markup = `<!-- wp:${ KEEPS } {"size":"large"} --><p class="has-large-size">Hi</p><!-- /wp:${ KEEPS } -->`;

		expect( findInvalidBlocks( markup ) ).toEqual( [] );
		expect( findOutdatedBlocks( markup ) ).toEqual( [] );
	} );

	it( 'reports markup that only matches a deprecated version', () => {
		const markup = `<!-- wp:${ KEEPS } {"size":"large"} --><p>Hi</p><!-- /wp:${ KEEPS } -->`;

		// The editor accepts this, which is the whole problem: it renders
		// without the class, so the styling silently does not apply.
		expect( findInvalidBlocks( markup ) ).toEqual( [] );

		const outdated = findOutdatedBlocks( markup );
		expect( outdated ).toHaveLength( 1 );
		expect( outdated[ 0 ].name ).toBe( KEEPS );
		expect( outdated[ 0 ].reason ).toBe( 'old-form' );
		// Core announces the migration on the console. Asserting it is also
		// the proof that the deprecation path ran at all.
		expect( console ).toHaveInformed();
	} );

	it( 'reports an attribute the migration threw away', () => {
		const markup = `<!-- wp:${ DROPS } {"size":"large"} --><p>Hi</p><!-- /wp:${ DROPS } -->`;

		const outdated = findOutdatedBlocks( markup );
		expect( outdated ).toHaveLength( 1 );
		expect( outdated[ 0 ].name ).toBe( DROPS );
		expect( outdated[ 0 ].reason ).toBe( 'dropped-attribute' );
		expect( outdated[ 0 ].dropped ).toEqual( [ 'size' ] );
		// Core announces the migration on the console. Asserting it is also
		// the proof that the deprecation path ran at all.
		expect( console ).toHaveInformed();
	} );

	it( 'does not call a relocated attribute a lost one', () => {
		const markup = `<!-- wp:${ MOVES } {"size":"large"} --><p class="has-large-size">Hi</p><!-- /wp:${ MOVES } -->`;

		// `size` is gone from the top level, but its value is still in there
		// under `style.typography` — nothing was lost, so nothing to report.
		expect( findOutdatedBlocks( markup ) ).toEqual( [] );
		expect( console ).toHaveInformed();
	} );

	it( 'leaves a block with bindings alone', () => {
		// A bound block takes its content from the binding source at render,
		// not from the markup, so the file and a save computed from the
		// file's own attributes are not comparable.
		const bound =
			'{"size":"large","metadata":{"bindings":{"__default":{"source":"core/pattern-overrides"}}}}';
		const markup = `<!-- wp:${ KEEPS } ${ bound } --><p>Hi</p><!-- /wp:${ KEEPS } -->`;

		expect( findOutdatedBlocks( markup ) ).toEqual( [] );
		expect( console ).toHaveInformed();
	} );

	it( 'looks inside inner blocks', () => {
		const markup = `<!-- wp:${ STACK } --><!-- wp:${ KEEPS } {"size":"large"} --><p>Hi</p><!-- /wp:${ KEEPS } --><!-- /wp:${ STACK } -->`;

		expect( findOutdatedBlocks( markup ) ).toHaveLength( 1 );
		// Core announces the migration on the console. Asserting it is also
		// the proof that the deprecation path ran at all.
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
