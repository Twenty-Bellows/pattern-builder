/**
 * Reading and writing a block's `metadata.bindings`.
 *
 * The shapes are core's. A block's bindings map an attribute name to
 * `{ source, args }`, with one reserved key, `__default`, meaning every
 * supported attribute is a pattern override.
 */

/**
 * The binding source that marks an attribute as a pattern override.
 *
 * @type {string}
 */
export const OVERRIDES_SOURCE = 'core/pattern-overrides';

/**
 * The reserved attribute key binding every supported attribute at once.
 *
 * @type {string}
 */
export const DEFAULT_ATTRIBUTE = '__default';

/**
 * The two states the per-block control can express on its own.
 *
 * @type {Object<string, string>}
 */
export const MODE = {
	STATIC: 'static',
	OVERRIDES: 'overrides',
};

/**
 * The bindable attributes of each block type, for WordPress 6.8.
 *
 * 6.9 exposes the table to the editor as a setting, which the panel prefers.
 * Mirrors the fallback in `Pattern_Resolver::get_supported_attributes()`.
 *
 * @type {Object<string, string[]>}
 */
export const FALLBACK_SUPPORTED_ATTRIBUTES = {
	'core/paragraph': [ 'content' ],
	'core/heading': [ 'content' ],
	'core/image': [ 'id', 'url', 'title', 'alt' ],
	'core/button': [ 'url', 'text', 'linkTarget', 'rel' ],
};

/**
 * Every block in a tree that WordPress will resolve bindings for.
 *
 * @param {Object[]}                 blocks              The blocks to walk.
 * @param {Object<string, string[]>} supportedAttributes Bindable attributes by block name.
 * @return {Array<{block: Object, supported: string[]}>} The bindable blocks, in document order.
 */
export function collectBindableBlocks( blocks, supportedAttributes ) {
	const found = [];

	const walk = ( list ) => {
		( list || [] ).forEach( ( block ) => {
			const supported = supportedAttributes?.[ block.name ];

			if ( supported?.length ) {
				found.push( { block, supported } );
			}

			walk( block.innerBlocks );
		} );
	};

	walk( blocks );

	return found;
}

/**
 * Whether a binding points at pattern overrides.
 *
 * @param {?Object} binding A single attribute's binding.
 * @return {boolean} Whether the binding is a pattern override.
 */
export function isOverride( binding ) {
	return binding?.source === OVERRIDES_SOURCE;
}

/**
 * Whether a block's bindings carry the `__default` pattern-override key.
 *
 * @param {?Object} bindings A block's `metadata.bindings`.
 * @return {boolean} Whether `__default` binds to pattern overrides.
 */
export function hasDefaultOverrides( bindings ) {
	return isOverride( bindings?.[ DEFAULT_ATTRIBUTE ] );
}

/**
 * The per-attribute view of a block's bindings.
 *
 * Expands `__default` into one override per supported attribute, the way core
 * does at render, keeping any explicit binding already on an attribute.
 *
 * @param {?Object}  bindings  A block's `metadata.bindings`.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {Object<string, Object>} Bindings keyed by supported attribute.
 */
export function expandBindings( bindings, supported ) {
	const expanded = {};
	const bindsEverything = hasDefaultOverrides( bindings );

	supported.forEach( ( attribute ) => {
		if ( bindings?.[ attribute ] ) {
			expanded[ attribute ] = bindings[ attribute ];
			return;
		}

		if ( bindsEverything ) {
			expanded[ attribute ] = { source: OVERRIDES_SOURCE };
		}
	} );

	return expanded;
}

/**
 * Whether a block's bindings are too detailed for the per-block control.
 *
 * True for anything other than "nothing bound" and "every attribute
 * overridable" — a binding to another source, or overrides on only some
 * attributes. Such a block always shows its attribute rows, whatever post type
 * the panel is listing, so an existing binding is never hidden.
 *
 * @param {?Object}  bindings  A block's `metadata.bindings`.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {boolean} Whether the attribute rows are required.
 */
export function needsRows( bindings, supported ) {
	const expanded = expandBindings( bindings, supported );
	const bound = Object.keys( expanded );

	if ( bound.length === 0 ) {
		return false;
	}

	return (
		bound.length !== supported.length ||
		! bound.every( ( attribute ) => isOverride( expanded[ attribute ] ) )
	);
}

/**
 * Which state the per-block control should show.
 *
 * Only meaningful when `needsRows()` is false.
 *
 * @param {?Object}  bindings  A block's `metadata.bindings`.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {string} One of the `MODE` values.
 */
export function getBindingMode( bindings, supported ) {
	return Object.keys( expandBindings( bindings, supported ) ).length === 0
		? MODE.STATIC
		: MODE.OVERRIDES;
}

/**
 * The bindings on attributes this panel does not manage.
 *
 * @param {?Object}  bindings  A block's `metadata.bindings`.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {Object<string, Object>} The bindings left untouched.
 */
function getForeignBindings( bindings, supported ) {
	const foreign = {};

	Object.keys( bindings || {} ).forEach( ( attribute ) => {
		if (
			attribute !== DEFAULT_ATTRIBUTE &&
			! supported.includes( attribute )
		) {
			foreign[ attribute ] = bindings[ attribute ];
		}
	} );

	return foreign;
}

/**
 * Writes a per-attribute view back into a block's bindings.
 *
 * Collapses to `__default` when every supported attribute ends up an override,
 * so a pattern file does not change shape just because it was opened.
 *
 * @param {?Object}  bindings  The block's current `metadata.bindings`.
 * @param {Object}   rows      The new per-attribute bindings; omit an attribute to unbind it.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {Object|undefined} The bindings to write, or `undefined` when none are left.
 */
export function applyRows( bindings, rows, supported ) {
	const next = getForeignBindings( bindings, supported );
	const bound = supported.filter( ( attribute ) => !! rows[ attribute ] );

	const everythingOverridden =
		bound.length === supported.length &&
		bound.every( ( attribute ) => isOverride( rows[ attribute ] ) );

	if ( everythingOverridden ) {
		next[ DEFAULT_ATTRIBUTE ] = { source: OVERRIDES_SOURCE };
	} else {
		bound.forEach( ( attribute ) => {
			next[ attribute ] = rows[ attribute ];
		} );
	}

	return Object.keys( next ).length > 0 ? next : undefined;
}

/**
 * The bindings for a block whose every supported attribute is overridable.
 *
 * @param {?Object}  bindings  The block's current `metadata.bindings`.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {Object|undefined} The bindings to write.
 */
export function bindEverything( bindings, supported ) {
	const rows = {};
	supported.forEach( ( attribute ) => {
		rows[ attribute ] = { source: OVERRIDES_SOURCE };
	} );

	return applyRows( bindings, rows, supported );
}

/**
 * The bindings for a block that takes every value from the pattern file.
 *
 * @param {?Object}  bindings  The block's current `metadata.bindings`.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {Object|undefined} The bindings to write.
 */
export function bindNothing( bindings, supported ) {
	return applyRows( bindings, {}, supported );
}

/**
 * A block's `metadata` with its bindings replaced.
 *
 * Returns `undefined` when nothing is left; an empty `metadata` object would
 * otherwise serialize into the pattern file.
 *
 * @param {?Object}          metadata The block's current `metadata` attribute.
 * @param {Object|undefined} bindings The bindings to write, if any.
 * @return {Object|undefined} The metadata to write.
 */
export function withBindings( metadata, bindings ) {
	const { bindings: previous, ...rest } = metadata || {};
	const next = bindings ? { ...rest, bindings } : rest;

	return Object.keys( next ).length > 0 ? next : undefined;
}

/**
 * The type a binding source's fields must declare to match an attribute.
 *
 * Core treats `rich-text` as `string`.
 *
 * @param {?Object} blockType     The registered block type.
 * @param {string}  attributeName The attribute to look up.
 * @return {string|undefined} The type to match fields against.
 */
export function getAttributeType( blockType, attributeName ) {
	const type = blockType?.attributes?.[ attributeName ]?.type;

	return type === 'rich-text' ? 'string' : type;
}

/**
 * A binding source's fields, in one shape whatever WordPress returned.
 *
 * `getFieldsList` returned a map keyed by field key up to WordPress 6.8 and an
 * array of descriptors carrying their own `args` since.
 *
 * @param {Object|Array|null|undefined} fields Whatever `getFieldsList` returned.
 * @return {Array<{label: string, type: string, args: Object}>} The fields.
 */
export function normalizeFields( fields ) {
	if ( ! fields ) {
		return [];
	}

	if ( Array.isArray( fields ) ) {
		return fields
			.filter( ( field ) => !! field?.args )
			.map( ( { label, type, args } ) => ( { label, type, args } ) );
	}

	return Object.entries( fields ).map( ( [ key, field ] ) => ( {
		label: field?.label || key,
		type: field?.type,
		args: { key },
	} ) );
}

/**
 * Whether two bindings point at the same field of the same source.
 *
 * @param {?Object} a One binding.
 * @param {?Object} b Another binding.
 * @return {boolean} Whether they are the same connection.
 */
export function isSameBinding( a, b ) {
	return (
		a?.source === b?.source &&
		JSON.stringify( a?.args ?? null ) === JSON.stringify( b?.args ?? null )
	);
}
