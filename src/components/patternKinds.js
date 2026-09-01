/**
 * The kinds of pattern the Create Pattern modal offers.
 *
 * A "kind" is a starting point, not a stored property: each one names the
 * job a pattern is being made for and fixes the metadata that job implies —
 * whether inserted copies stay linked to the original, which blocks, post
 * types or templates WordPress should offer it for — so the modal only ever
 * asks for what that kind genuinely leaves open. Everything a kind decides
 * can still be changed afterwards in the pattern's own metadata panels.
 *
 * The metadata each kind fixes follows the theme handbook's pattern pages:
 * https://developer.wordpress.org/themes/patterns/
 */

import { __ } from '@wordpress/i18n';
import {
	layout,
	symbol,
	page as pageIcon,
	blockDefault,
	pages,
	header as headerIcon,
} from '@wordpress/icons';

export const DESIGN = 'design';
export const SYNCED_DESIGN = 'synced-design';
export const PAGE = 'page';
export const BLOCK_STARTER = 'block-starter';
export const TEMPLATE = 'template';
export const TEMPLATE_PART = 'template-part';

export const DESIGN_GROUP = 'design';
export const STARTER_GROUP = 'starter';

/**
 * The two halves of the list: patterns made to be used, and patterns made
 * to be offered somewhere.
 */
export const PATTERN_KIND_GROUPS = [
	{ key: DESIGN_GROUP, label: __( 'Design', 'pattern-builder' ) },
	{ key: STARTER_GROUP, label: __( 'Starter', 'pattern-builder' ) },
];

/**
 * The block type WordPress reads to offer a pattern as starter content for
 * new posts and pages.
 */
export const POST_CONTENT_BLOCK = 'core/post-content';

/**
 * The width a full-width pattern is previewed at. Template and template
 * part patterns are designed against the whole page, so they are previewed
 * against it too — the same width the pattern grid renders at.
 */
export const FULL_WIDTH_VIEWPORT = 1400;

/**
 * The template part areas a pattern can belong to.
 *
 * Only these two: the handbook is explicit that "only parts that use the
 * Header and Footer template part areas are supported", and a pattern for a
 * custom area is simply never offered. Each carries the pattern category
 * that goes with it, as the handbook's own example does.
 */
export const TEMPLATE_PART_AREAS = [
	{
		key: 'header',
		label: __( 'Header', 'pattern-builder' ),
		blockType: 'core/template-part/header',
		category: 'header',
	},
	{
		key: 'footer',
		label: __( 'Footer', 'pattern-builder' ),
		blockType: 'core/template-part/footer',
		category: 'footer',
	},
];

/**
 * The template types the `Template Types` header takes — WordPress core's
 * default block template types, which are what the Site Editor offers to
 * create.
 */
export const TEMPLATE_TYPES = [
	{ slug: 'index', label: __( 'Index', 'pattern-builder' ) },
	{ slug: 'home', label: __( 'Blog Home', 'pattern-builder' ) },
	{ slug: 'front-page', label: __( 'Front Page', 'pattern-builder' ) },
	{ slug: 'singular', label: __( 'Single Entries', 'pattern-builder' ) },
	{ slug: 'single', label: __( 'Single Posts', 'pattern-builder' ) },
	{ slug: 'page', label: __( 'Pages', 'pattern-builder' ) },
	{ slug: 'archive', label: __( 'All Archives', 'pattern-builder' ) },
	{ slug: 'author', label: __( 'Author Archives', 'pattern-builder' ) },
	{ slug: 'category', label: __( 'Category Archives', 'pattern-builder' ) },
	{ slug: 'taxonomy', label: __( 'Taxonomies', 'pattern-builder' ) },
	{ slug: 'date', label: __( 'Date Archives', 'pattern-builder' ) },
	{ slug: 'tag', label: __( 'Tag Archives', 'pattern-builder' ) },
	{ slug: 'attachment', label: __( 'Media', 'pattern-builder' ) },
	{ slug: 'search', label: __( 'Search Results', 'pattern-builder' ) },
	{
		slug: 'privacy-policy',
		label: __( 'Privacy Policy', 'pattern-builder' ),
	},
	{ slug: '404', label: __( 'Page: 404', 'pattern-builder' ) },
];

/**
 * Extra inputs a kind asks the user for, beyond name and description.
 *
 * `storage` — theme file or database. `postTypes` — which post types get
 * offered the pattern when new content is created. `blockTypes` — which
 * blocks offer the pattern when they are inserted. `templateTypes` — which
 * templates it is offered for. `templatePartArea` — header or footer.
 */
export const STORAGE_FIELD = 'storage';
export const POST_TYPES_FIELD = 'postTypes';
export const BLOCK_TYPES_FIELD = 'blockTypes';
export const TEMPLATE_TYPES_FIELD = 'templateTypes';
export const TEMPLATE_PART_AREA_FIELD = 'templatePartArea';

/**
 * Fields whose whole point is the list they collect: a kind that asks for
 * one cannot be created until something is in it, because the pattern would
 * never be offered anywhere.
 */
const REQUIRED_LIST_FIELDS = [
	POST_TYPES_FIELD,
	BLOCK_TYPES_FIELD,
	TEMPLATE_TYPES_FIELD,
];

export const PATTERN_KINDS = [
	{
		key: DESIGN,
		group: DESIGN_GROUP,
		icon: layout,
		label: __( 'Design Pattern', 'pattern-builder' ),
		summary: __(
			'Insert it anywhere, then edit freely.',
			'pattern-builder'
		),
		description: __(
			'The building blocks of your site. These patterns put blocks together in ways that you want reused over and over again. When you use this pattern you’re free to change any part of it. Changing the pattern only changes new instances.',
			'pattern-builder'
		),
		fields: [ STORAGE_FIELD ],
		defaults: { source: 'theme', synced: false },
	},
	{
		key: SYNCED_DESIGN,
		group: DESIGN_GROUP,
		icon: symbol,
		label: __( 'Synced Design Pattern', 'pattern-builder' ),
		summary: __(
			'Content is editable, design is locked.',
			'pattern-builder'
		),
		description: __(
			'These patterns are building block patterns, and you can change the content of the pattern, but the design is locked in. If you change the pattern it changes it everywhere it’s used.',
			'pattern-builder'
		),
		fields: [ STORAGE_FIELD ],
		defaults: { source: 'theme', synced: true },
	},
	{
		key: PAGE,
		group: STARTER_GROUP,
		icon: pageIcon,
		label: __( 'Page Pattern', 'pattern-builder' ),
		summary: __(
			'Offered when new content is created.',
			'pattern-builder'
		),
		description: __(
			'These patterns are offered to the user when a new page is created. They often have Design Patterns in them as starter content. They are stored in your theme, because the post types they are offered for are recorded in the pattern file.',
			'pattern-builder'
		),
		// The contexts a starter pattern is offered in are pattern-file
		// headers, which a database pattern has nowhere to put — so this
		// kind is always a theme pattern and never asks where to store it.
		fields: [ POST_TYPES_FIELD ],
		defaults: {
			source: 'theme',
			synced: false,
			blockTypes: [ POST_CONTENT_BLOCK ],
			postTypes: [ 'page' ],
		},
	},
	{
		key: BLOCK_STARTER,
		group: STARTER_GROUP,
		icon: blockDefault,
		label: __( 'Block Starter Pattern', 'pattern-builder' ),
		summary: __( 'Offered when a block is inserted.', 'pattern-builder' ),
		description: __(
			'These patterns belong to a block. WordPress offers them when that block is inserted and still empty, so an untouched Query Loop or Cover asks which one to start from. The block’s toolbar offers them too, to swap one design for another. They are stored in your theme, because the blocks they belong to are recorded in the pattern file.',
			'pattern-builder'
		),
		// Same story as a starter pattern: Block Types is a pattern-file
		// header, so this kind is always a theme pattern.
		fields: [ BLOCK_TYPES_FIELD ],
		defaults: { source: 'theme', synced: false, blockTypes: [] },
	},
	{
		key: TEMPLATE,
		group: STARTER_GROUP,
		icon: pages,
		label: __( 'Template Pattern', 'pattern-builder' ),
		summary: __( 'Offered when a template is created.', 'pattern-builder' ),
		description: __(
			'These patterns are whole templates, like an archive or a 404, offered in the Site Editor when someone creates a template of that type, header and footer included. They are stored in your theme, because the template types they are offered for are recorded in the pattern file.',
			'pattern-builder'
		),
		fields: [ TEMPLATE_TYPES_FIELD ],
		defaults: {
			source: 'theme',
			synced: false,
			templateTypes: [],
			// A whole template is noise in the block inserter, and the
			// themes that ship these keep it out of there.
			inserter: false,
			viewportWidth: FULL_WIDTH_VIEWPORT,
		},
	},
	{
		key: TEMPLATE_PART,
		group: STARTER_GROUP,
		icon: headerIcon,
		label: __( 'Template Part Pattern', 'pattern-builder' ),
		summary: __( 'Offered for a header or a footer.', 'pattern-builder' ),
		description: __(
			'These patterns belong to a template part. The Site Editor offers them when a header or a footer is created, and from the part itself, to swap one design for another. WordPress supports those two areas only. They are stored in your theme, because the part they belong to is recorded in the pattern file.',
			'pattern-builder'
		),
		fields: [ TEMPLATE_PART_AREA_FIELD ],
		defaults: {
			source: 'theme',
			synced: false,
			templatePartArea: TEMPLATE_PART_AREAS[ 0 ].key,
			viewportWidth: FULL_WIDTH_VIEWPORT,
		},
	},
];

/**
 * Looks a kind up by key.
 *
 * @param {string} key A kind key.
 * @return {Object} The kind; the first kind when the key is unknown.
 */
export function getPatternKind( key ) {
	return (
		PATTERN_KINDS.find( ( kind ) => kind.key === key ) || PATTERN_KINDS[ 0 ]
	);
}

/**
 * The kinds in one group, in the order they are listed.
 *
 * @param {string} group A group key.
 * @return {Object[]} The kinds in that group.
 */
export function getPatternKindsInGroup( group ) {
	return PATTERN_KINDS.filter( ( kind ) => kind.group === group );
}

/**
 * Looks a template part area up by key.
 *
 * @param {string} key An area key.
 * @return {Object} The area; the first area when the key is unknown.
 */
export function getTemplatePartArea( key ) {
	return (
		TEMPLATE_PART_AREAS.find( ( area ) => area.key === key ) ||
		TEMPLATE_PART_AREAS[ 0 ]
	);
}

/**
 * Whether a kind asks the user for a given field.
 *
 * @param {Object} kind  A kind.
 * @param {string} field A field name.
 * @return {boolean} Whether the modal shows the field for this kind.
 */
export function kindHasField( kind, field ) {
	return ( kind.fields || [] ).includes( field );
}

/**
 * The values the modal starts a kind with.
 *
 * @param {Object} kind A kind.
 * @return {Object} Field values.
 */
export function getInitialValues( kind ) {
	return {
		title: '',
		description: '',
		source: kind.defaults.source,
		postTypes: kind.defaults.postTypes || [],
		blockTypes: kind.defaults.blockTypes || [],
		templateTypes: kind.defaults.templateTypes || [],
		templatePartArea:
			kind.defaults.templatePartArea || TEMPLATE_PART_AREAS[ 0 ].key,
	};
}

/**
 * Whether the collected values are enough to create the pattern.
 *
 * @param {Object} kind   A kind.
 * @param {Object} values The collected values.
 * @return {boolean} Whether creation can proceed.
 */
export function canCreate( kind, values ) {
	if ( ! ( values.title || '' ).trim() ) {
		return false;
	}

	return REQUIRED_LIST_FIELDS.every(
		( field ) =>
			! kindHasField( kind, field ) ||
			( values[ field ] || [] ).length > 0
	);
}

/**
 * What a list-valued header ends up as: the user's choice where the kind
 * asks for one, and what the kind fixed where it does not.
 *
 * @param {Object} kind   A kind.
 * @param {Object} values The collected values.
 * @param {string} field  A field name.
 * @return {string[]} The list.
 */
function listFor( kind, values, field ) {
	const list = kindHasField( kind, field )
		? values[ field ]
		: kind.defaults[ field ];

	return list || [];
}

/**
 * The REST request that creates a pattern of this kind.
 *
 * Theme patterns are file-backed `pb_pattern` entities with a controller of
 * their own; user patterns are `wp_block` posts, where an unsynced pattern is
 * expressed as post meta rather than a header.
 *
 * @param {Object} kind   A kind.
 * @param {Object} values The collected values.
 * @return {{path: string, method: string, data: Object}} An apiFetch request.
 */
export function buildCreateRequest( kind, values ) {
	const title = ( values.title || '' ).trim();
	const description = ( values.description || '' ).trim();
	const synced = !! kind.defaults.synced;
	const source = kindHasField( kind, STORAGE_FIELD )
		? values.source || kind.defaults.source
		: kind.defaults.source;

	if ( 'user' === source ) {
		const userData = {
			title,
			excerpt: description,
			status: 'publish',
		};

		if ( ! synced ) {
			userData.meta = { wp_pattern_sync_status: 'unsynced' };
		}

		return { path: '/wp/v2/blocks', method: 'POST', data: userData };
	}

	const data = { title, description, synced };

	// A template part pattern is a block type pattern underneath: the area
	// decides both the block type that offers it and the category it files
	// itself under.
	const area = kindHasField( kind, TEMPLATE_PART_AREA_FIELD )
		? getTemplatePartArea( values.templatePartArea )
		: null;

	const lists = {
		blockTypes: area
			? [ area.blockType ]
			: listFor( kind, values, BLOCK_TYPES_FIELD ),
		categories: area
			? [ area.category ]
			: listFor( kind, values, 'categories' ),
		postTypes: listFor( kind, values, POST_TYPES_FIELD ),
		templateTypes: listFor( kind, values, TEMPLATE_TYPES_FIELD ),
	};

	Object.keys( lists ).forEach( ( key ) => {
		if ( lists[ key ].length ) {
			data[ key ] = lists[ key ];
		}
	} );

	if ( false === kind.defaults.inserter ) {
		data.inserter = false;
	}

	if ( kind.defaults.viewportWidth ) {
		data.viewportWidth = kind.defaults.viewportWidth;
	}

	return {
		path: '/pattern-builder/v1/patterns',
		method: 'POST',
		data,
	};
}
