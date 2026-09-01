/**
 * The kinds the Create Pattern modal offers: what each one fixes, what it
 * still asks for, and the request it turns into. A kind's whole job is the
 * metadata it decides on the user's behalf, so that is what is asserted here.
 */

import {
	PATTERN_KINDS,
	DESIGN,
	SYNCED_DESIGN,
	STARTER,
	BLOCK_STARTER,
	POST_CONTENT_BLOCK,
	STORAGE_FIELD,
	POST_TYPES_FIELD,
	BLOCK_TYPES_FIELD,
	getPatternKind,
	getInitialValues,
	kindHasField,
	canCreate,
	buildCreateRequest,
} from '../../src/components/patternKinds';

const valuesFor = ( key, overrides = {} ) => ( {
	...getInitialValues( getPatternKind( key ) ),
	title: 'Hero',
	...overrides,
} );

describe( 'pattern kinds', () => {
	it( 'offers a design, a synced design, and the two starter kinds', () => {
		expect( PATTERN_KINDS.map( ( kind ) => kind.key ) ).toEqual( [
			DESIGN,
			SYNCED_DESIGN,
			STARTER,
			BLOCK_STARTER,
		] );
	} );

	it( 'every kind describes itself', () => {
		PATTERN_KINDS.forEach( ( kind ) => {
			expect( kind.label ).toBeTruthy();
			expect( kind.description ).toBeTruthy();
			expect( kind.icon ).toBeTruthy();
		} );
	} );

	it( 'falls back to the first kind for an unknown key', () => {
		expect( getPatternKind( 'nope' ).key ).toBe( DESIGN );
	} );

	it( 'asks the design kinds where to store the pattern', () => {
		[ DESIGN, SYNCED_DESIGN ].forEach( ( key ) => {
			expect( kindHasField( getPatternKind( key ), STORAGE_FIELD ) ).toBe(
				true
			);
		} );
	} );

	it( 'never asks a starter pattern where to live — a wp_block cannot carry its headers', () => {
		[ STARTER, BLOCK_STARTER ].forEach( ( key ) => {
			const kind = getPatternKind( key );

			expect( kindHasField( kind, STORAGE_FIELD ) ).toBe( false );
			expect( kind.defaults.source ).toBe( 'theme' );
		} );

		expect(
			buildCreateRequest(
				getPatternKind( STARTER ),
				valuesFor( STARTER )
			).path
		).toBe( '/pattern-builder/v1/patterns' );
	} );
} );

describe( 'canCreate', () => {
	it( 'requires a name', () => {
		expect(
			canCreate(
				getPatternKind( DESIGN ),
				valuesFor( DESIGN, { title: '   ' } )
			)
		).toBe( false );
		expect(
			canCreate( getPatternKind( DESIGN ), valuesFor( DESIGN ) )
		).toBe( true );
	} );

	it( 'requires a block starter pattern to name at least one block', () => {
		const kind = getPatternKind( BLOCK_STARTER );

		expect( canCreate( kind, valuesFor( BLOCK_STARTER ) ) ).toBe( false );
		expect(
			canCreate(
				kind,
				valuesFor( BLOCK_STARTER, { blockTypes: [ 'core/cover' ] } )
			)
		).toBe( true );
	} );

	it( 'requires a starter pattern to name at least one post type', () => {
		const starter = getPatternKind( STARTER );

		expect(
			canCreate( starter, valuesFor( STARTER, { postTypes: [] } ) )
		).toBe( false );
		expect( canCreate( starter, valuesFor( STARTER ) ) ).toBe( true );
	} );
} );

describe( 'buildCreateRequest', () => {
	it( 'writes an unsynced theme pattern for a design pattern', () => {
		const request = buildCreateRequest(
			getPatternKind( DESIGN ),
			valuesFor( DESIGN, { description: '  A hero.  ' } )
		);

		expect( request ).toEqual( {
			path: '/pattern-builder/v1/patterns',
			method: 'POST',
			data: { title: 'Hero', description: 'A hero.', synced: false },
		} );
	} );

	it( 'marks a synced design pattern synced', () => {
		const request = buildCreateRequest(
			getPatternKind( SYNCED_DESIGN ),
			valuesFor( SYNCED_DESIGN )
		);

		expect( request.data.synced ).toBe( true );
	} );

	it( 'creates a wp_block when the user stores a design pattern in the database', () => {
		const request = buildCreateRequest(
			getPatternKind( DESIGN ),
			valuesFor( DESIGN, { source: 'user', description: 'A hero.' } )
		);

		expect( request ).toEqual( {
			path: '/wp/v2/blocks',
			method: 'POST',
			data: {
				title: 'Hero',
				excerpt: 'A hero.',
				status: 'publish',
				meta: { wp_pattern_sync_status: 'unsynced' },
			},
		} );
	} );

	it( 'leaves a synced user pattern without the unsynced meta', () => {
		const request = buildCreateRequest(
			getPatternKind( SYNCED_DESIGN ),
			valuesFor( SYNCED_DESIGN, { source: 'user' } )
		);

		expect( request.path ).toBe( '/wp/v2/blocks' );
		expect( request.data.meta ).toBeUndefined();
	} );

	it( 'gives a starter pattern the headers WordPress reads when new content is created', () => {
		const request = buildCreateRequest(
			getPatternKind( STARTER ),
			valuesFor( STARTER, { postTypes: [ 'page', 'post' ] } )
		);

		expect( request.data ).toEqual( {
			title: 'Hero',
			description: '',
			synced: false,
			blockTypes: [ POST_CONTENT_BLOCK ],
			postTypes: [ 'page', 'post' ],
		} );
	} );

	it( 'starts a starter pattern on pages', () => {
		expect(
			getInitialValues( getPatternKind( STARTER ) ).postTypes
		).toEqual( [ 'page' ] );
		expect(
			kindHasField( getPatternKind( STARTER ), POST_TYPES_FIELD )
		).toBe( true );
	} );

	it( 'binds a block starter pattern to the blocks the user picked', () => {
		const request = buildCreateRequest(
			getPatternKind( BLOCK_STARTER ),
			valuesFor( BLOCK_STARTER, {
				blockTypes: [ 'core/query', 'core/cover' ],
			} )
		);

		expect( request.data ).toEqual( {
			title: 'Hero',
			description: '',
			synced: false,
			blockTypes: [ 'core/query', 'core/cover' ],
		} );
		expect( request.data.postTypes ).toBeUndefined();
	} );

	it( 'starts a block starter pattern with no block chosen', () => {
		const kind = getPatternKind( BLOCK_STARTER );

		expect( getInitialValues( kind ).blockTypes ).toEqual( [] );
		expect( kindHasField( kind, BLOCK_TYPES_FIELD ) ).toBe( true );
	} );

	it( 'keeps the page starter pattern on core/post-content, which it never asks about', () => {
		const kind = getPatternKind( STARTER );

		expect( kindHasField( kind, BLOCK_TYPES_FIELD ) ).toBe( false );
		expect(
			buildCreateRequest(
				kind,
				valuesFor( STARTER, { blockTypes: [ 'core/cover' ] } )
			).data.blockTypes
		).toEqual( [ POST_CONTENT_BLOCK ] );
	} );

	it( 'ignores a source the kind never asked for', () => {
		const request = buildCreateRequest(
			getPatternKind( STARTER ),
			valuesFor( STARTER, { source: 'user' } )
		);

		expect( request.path ).toBe( '/pattern-builder/v1/patterns' );
	} );
} );
