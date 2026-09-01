/**
 * The kinds of pattern the Create Pattern modal offers.
 *
 * A "kind" is a starting point, not a stored property: each one names the
 * job a pattern is being made for and fixes the metadata that job implies —
 * whether inserted copies stay linked to the original, which block and post
 * types WordPress should offer it for — so the modal only ever asks for what
 * that kind genuinely leaves open. Everything a kind decides can still be
 * changed afterwards in the pattern's own metadata panels.
 */

import { __ } from '@wordpress/i18n';
import {
	layout,
	symbol,
	page as pageIcon,
	blockDefault,
} from '@wordpress/icons';

export const DESIGN = 'design';
export const SYNCED_DESIGN = 'synced-design';
export const STARTER = 'starter';
export const BLOCK_STARTER = 'block-starter';

/**
 * The block type WordPress reads to offer a pattern as starter content for
 * new posts and pages.
 */
export const POST_CONTENT_BLOCK = 'core/post-content';

/**
 * Extra inputs a kind asks the user for, beyond name and description.
 *
 * `storage` — theme file or database. `postTypes` — which post types get
 * offered the pattern when new content is created. `blockTypes` — which
 * blocks offer the pattern when they are inserted.
 */
export const STORAGE_FIELD = 'storage';
export const POST_TYPES_FIELD = 'postTypes';
export const BLOCK_TYPES_FIELD = 'blockTypes';

export const PATTERN_KINDS = [
	{
		key: DESIGN,
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
		key: STARTER,
		icon: pageIcon,
		label: __( 'Starter Pattern', 'pattern-builder' ),
		summary: __(
			'Offered when new content is created.',
			'pattern-builder'
		),
		description: __(
			'These patterns are offered to the user when a new page is created. They often have Design Patterns in them as starter content.',
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
		icon: blockDefault,
		label: __( 'Block Starter Pattern', 'pattern-builder' ),
		summary: __( 'Offered when a block is inserted.', 'pattern-builder' ),
		description: __(
			'These patterns belong to a block. WordPress offers them when that block is inserted and still empty — an untouched Query Loop or Cover asks which one to start from — and from the block’s toolbar, to swap one design for another.',
			'pattern-builder'
		),
		// Same story as a starter pattern: Block Types is a pattern-file
		// header, so this kind is always a theme pattern.
		fields: [ BLOCK_TYPES_FIELD ],
		defaults: { source: 'theme', synced: false, blockTypes: [] },
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

	if ( kindHasField( kind, POST_TYPES_FIELD ) ) {
		return ( values.postTypes || [] ).length > 0;
	}

	if ( kindHasField( kind, BLOCK_TYPES_FIELD ) ) {
		return ( values.blockTypes || [] ).length > 0;
	}

	return true;
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
		const data = {
			title,
			excerpt: description,
			status: 'publish',
		};

		if ( ! synced ) {
			data.meta = { wp_pattern_sync_status: 'unsynced' };
		}

		return { path: '/wp/v2/blocks', method: 'POST', data };
	}

	const data = { title, description, synced };
	const blockTypes = kindHasField( kind, BLOCK_TYPES_FIELD )
		? values.blockTypes || []
		: kind.defaults.blockTypes || [];
	const postTypes = kindHasField( kind, POST_TYPES_FIELD )
		? values.postTypes || []
		: kind.defaults.postTypes || [];

	if ( blockTypes.length ) {
		data.blockTypes = blockTypes;
	}

	if ( postTypes.length ) {
		data.postTypes = postTypes;
	}

	return {
		path: '/pattern-builder/v1/patterns',
		method: 'POST',
		data,
	};
}
