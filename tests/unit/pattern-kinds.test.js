/**
 * The kinds the Create Pattern modal offers: what each one fixes, what it
 * still asks for, and the request it turns into. A kind's whole job is the
 * metadata it decides on the user's behalf, so that is what is asserted here.
 */

import {
	PATTERN_KINDS,
	PATTERN_KIND_GROUPS,
	DESIGN,
	SYNCED_DESIGN,
	PAGE_STARTER,
	BLOCK_STARTER,
	TEMPLATE,
	TEMPLATE_PART,
	DESIGN_GROUP,
	STARTER_GROUP,
	POST_CONTENT_BLOCK,
	FULL_WIDTH_VIEWPORT,
	STORAGE_FIELD,
	POST_TYPES_FIELD,
	BLOCK_TYPES_FIELD,
	TEMPLATE_TYPES_FIELD,
	TEMPLATE_PART_AREA_FIELD,
	getPatternKind,
	getPatternKindsInGroup,
	getTemplatePartArea,
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
	it( 'lists the design kinds, then everywhere a pattern can be offered', () => {
		expect( PATTERN_KINDS.map( ( kind ) => kind.key ) ).toEqual( [
			DESIGN,
			SYNCED_DESIGN,
			PAGE_STARTER,
			BLOCK_STARTER,
			TEMPLATE,
			TEMPLATE_PART,
		] );
	} );

	it( 'files every kind under one of the two groups', () => {
		expect( PATTERN_KIND_GROUPS.map( ( group ) => group.key ) ).toEqual( [
			DESIGN_GROUP,
			STARTER_GROUP,
		] );
		expect(
			getPatternKindsInGroup( DESIGN_GROUP ).map( ( kind ) => kind.key )
		).toEqual( [ DESIGN, SYNCED_DESIGN ] );
		expect(
			getPatternKindsInGroup( STARTER_GROUP ).map( ( kind ) => kind.key )
		).toEqual( [ PAGE_STARTER, BLOCK_STARTER, TEMPLATE, TEMPLATE_PART ] );
		expect(
			getPatternKindsInGroup( DESIGN_GROUP ).length +
				getPatternKindsInGroup( STARTER_GROUP ).length
		).toBe( PATTERN_KINDS.length );
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
		[ PAGE_STARTER, BLOCK_STARTER ].forEach( ( key ) => {
			const kind = getPatternKind( key );

			expect( kindHasField( kind, STORAGE_FIELD ) ).toBe( false );
			expect( kind.defaults.source ).toBe( 'theme' );
		} );

		expect(
			buildCreateRequest(
				getPatternKind( PAGE_STARTER ),
				valuesFor( PAGE_STARTER )
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

	it( 'requires a template pattern to name at least one template type', () => {
		const kind = getPatternKind( TEMPLATE );

		expect( kindHasField( kind, TEMPLATE_TYPES_FIELD ) ).toBe( true );
		expect( canCreate( kind, valuesFor( TEMPLATE ) ) ).toBe( false );
		expect(
			canCreate(
				kind,
				valuesFor( TEMPLATE, { templateTypes: [ 'archive' ] } )
			)
		).toBe( true );
	} );

	it( 'asks a template part pattern for nothing but an area, which it starts with', () => {
		const kind = getPatternKind( TEMPLATE_PART );

		expect( kindHasField( kind, TEMPLATE_PART_AREA_FIELD ) ).toBe( true );
		expect( getInitialValues( kind ).templatePartArea ).toBe( 'header' );
		expect( canCreate( kind, valuesFor( TEMPLATE_PART ) ) ).toBe( true );
	} );

	it( 'requires a starter pattern to name at least one post type', () => {
		const starter = getPatternKind( PAGE_STARTER );

		expect(
			canCreate( starter, valuesFor( PAGE_STARTER, { postTypes: [] } ) )
		).toBe( false );
		expect( canCreate( starter, valuesFor( PAGE_STARTER ) ) ).toBe( true );
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
			getPatternKind( PAGE_STARTER ),
			valuesFor( PAGE_STARTER, { postTypes: [ 'page', 'post' ] } )
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
			getInitialValues( getPatternKind( PAGE_STARTER ) ).postTypes
		).toEqual( [ 'page' ] );
		expect(
			kindHasField( getPatternKind( PAGE_STARTER ), POST_TYPES_FIELD )
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
		const kind = getPatternKind( PAGE_STARTER );

		expect( kindHasField( kind, BLOCK_TYPES_FIELD ) ).toBe( false );
		expect(
			buildCreateRequest(
				kind,
				valuesFor( PAGE_STARTER, { blockTypes: [ 'core/cover' ] } )
			).data.blockTypes
		).toEqual( [ POST_CONTENT_BLOCK ] );
	} );

	it( 'writes a template pattern the way the themes that ship them do', () => {
		const request = buildCreateRequest(
			getPatternKind( TEMPLATE ),
			valuesFor( TEMPLATE, {
				templateTypes: [ 'archive', 'category' ],
			} )
		);

		expect( request.data ).toEqual( {
			title: 'Hero',
			description: '',
			synced: false,
			templateTypes: [ 'archive', 'category' ],
			// A whole template belongs in the Site Editor's template
			// chooser, not in the block inserter.
			inserter: false,
			viewportWidth: FULL_WIDTH_VIEWPORT,
		} );
	} );

	it( 'turns a template part area into the block type and category that go with it', () => {
		expect(
			buildCreateRequest(
				getPatternKind( TEMPLATE_PART ),
				valuesFor( TEMPLATE_PART, { templatePartArea: 'footer' } )
			).data
		).toEqual( {
			title: 'Hero',
			description: '',
			synced: false,
			blockTypes: [ 'core/template-part/footer' ],
			categories: [ 'footer' ],
			viewportWidth: FULL_WIDTH_VIEWPORT,
		} );

		const header = buildCreateRequest(
			getPatternKind( TEMPLATE_PART ),
			valuesFor( TEMPLATE_PART )
		).data;

		expect( header.blockTypes ).toEqual( [ 'core/template-part/header' ] );
		expect( header.categories ).toEqual( [ 'header' ] );
		// Unlike a whole template, a header is worth inserting by hand.
		expect( header.inserter ).toBeUndefined();
	} );

	it( 'knows only the two template part areas WordPress supports', () => {
		expect( getTemplatePartArea( 'header' ).blockType ).toBe(
			'core/template-part/header'
		);
		expect( getTemplatePartArea( 'sidebar' ).key ).toBe( 'header' );
	} );

	it( 'ignores a source the kind never asked for', () => {
		const request = buildCreateRequest(
			getPatternKind( PAGE_STARTER ),
			valuesFor( PAGE_STARTER, { source: 'user' } )
		);

		expect( request.path ).toBe( '/pattern-builder/v1/patterns' );
	} );
} );
