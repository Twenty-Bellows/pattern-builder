/**
 * The rules for reading and writing a block's `metadata.bindings`.
 *
 * Everything here is pure so the round-trips that matter — expanding
 * `__default` and collapsing back to it — can be tested without an editor.
 *
 * The shapes involved are core's, not this plugin's. A block's bindings are a
 * map of attribute name to `{ source, args }`, with one reserved key:
 * `__default`, which only ever means "every supported attribute is a pattern
 * override". Core expands that key at render time, and the panel expands it
 * for display, so the two stay in agreement.
 */

/**
 * The binding source that marks an attribute as a pattern override.
 *
 * @type {string}
 */
export const OVERRIDES_SOURCE = 'core/pattern-overrides';

/**
 * The reserved attribute key that binds every supported attribute at once.
 *
 * @type {string}
 */
export const DEFAULT_ATTRIBUTE = '__default';

/**
 * The three states a bindable block can be in.
 *
 * `dynamic` is not a fourth kind of binding — it is the per-attribute view of
 * the same two choices, plus every registered source.
 */
export const MODE = {
	STATIC: 'static',
	OVERRIDES: 'overrides',
	DYNAMIC: 'dynamic',
};

/**
 * The bindable attributes of each block type, for WordPress 6.8.
 *
 * 6.9 moved this table into `get_block_bindings_supported_attributes()` and
 * hands it to the editor as a setting, which is what the panel prefers. 6.8
 * keeps it private to `WP_Block`, so this mirrors the same fallback
 * `Pattern_Resolver::get_supported_attributes()` carries on the PHP side.
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
 * Walks inner blocks too, so a bindable block nested inside a Group or a
 * Columns layout is found. Document order is preserved, which is the order the
 * panel lists them in.
 *
 * @param {Object[]}                 blocks              The blocks to walk.
 * @param {Object<string, string[]>} supportedAttributes Bindable attributes by
 *                                                       block name.
 * @return {Array<{block: Object, supported: string[]}>} The bindable blocks.
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
 * `__default` becomes one pattern-override entry per supported attribute,
 * which is the same expansion core performs in `process_block_bindings()` and
 * in `replacePatternOverridesDefaultBinding()`. An attribute that already had
 * an explicit binding keeps it — core retains those too, which is what lets a
 * block take one attribute from a source and leave another overridable.
 *
 * Attributes the block type cannot bind are left out; they are never shown and
 * never edited, and `applyRows()` preserves them untouched.
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
 * Which of the three states a block's bindings describe.
 *
 * A block counts as `overrides` when every supported attribute is an override
 * and nothing else is bound — however it was written. That way a block whose
 * bindings were expanded attribute by attribute still reads as the simple case
 * it is, and the panel does not strand the author in the per-attribute view.
 *
 * @param {?Object}  bindings  A block's `metadata.bindings`.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {string} One of the `MODE` values.
 */
export function getBindingMode( bindings, supported ) {
	const expanded = expandBindings( bindings, supported );
	const bound = Object.keys( expanded );

	if ( bound.length === 0 ) {
		return MODE.STATIC;
	}

	const everythingOverridden =
		bound.length === supported.length &&
		bound.every( ( attribute ) => isOverride( expanded[ attribute ] ) );

	return everythingOverridden ? MODE.OVERRIDES : MODE.DYNAMIC;
}

/**
 * The bindings that are neither `__default` nor a supported attribute.
 *
 * Core ignores these at render, but they are somebody's data — a binding left
 * behind by a block type that has since changed, or written by another plugin
 * — so the panel edits around them rather than through them.
 *
 * @param {?Object}  bindings  A block's `metadata.bindings`.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {Object<string, Object>} The bindings the panel does not manage.
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
 * When every supported attribute ends up an override the result collapses to
 * `__default`, so a block nobody bound to a source is written exactly the way
 * the panel has always written it — a pattern file should not change shape
 * just because it was opened in a newer editor.
 *
 * @param {?Object}  bindings  The block's current `metadata.bindings`.
 * @param {Object}   rows      The new per-attribute bindings; omit an attribute
 *                             or give it a falsy value to unbind it.
 * @param {string[]} supported The block type's bindable attributes.
 * @return {Object|undefined} The bindings to write, or `undefined` when none
 *                            are left — the shape `metadata.bindings` should
 *                            be deleted for.
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
 * Returns `undefined` when nothing is left, which is the value
 * `updateBlockAttributes` wants in order to drop the attribute entirely —
 * an empty `metadata` object would serialize into the pattern file.
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
 * The attribute type a binding source's fields must declare to match.
 *
 * Core compares a field's declared `type` against the block attribute's type,
 * treating `rich-text` as `string`. Across every bindable attribute in
 * WordPress 7.1 that leaves exactly one that is not a string:
 * `core/image`'s `id`.
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
 * `getFieldsList` changed shape after 6.8: it used to return an object keyed
 * by field key, where the key was the whole of the binding's `args`, and now
 * returns an array of descriptors that carry their own `args`. Normalising
 * here keeps every caller on the newer, more general shape.
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
