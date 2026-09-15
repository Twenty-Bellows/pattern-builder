import { getBlockBindingsSources } from '@wordpress/blocks';
import { useSelect } from '@wordpress/data';

import { OVERRIDES_SOURCE, normalizeFields } from '../../utils/bindings';

/**
 * The lens value meaning no post type, so no source offers any field.
 *
 * @type {string}
 */
export const USER_INPUT_ONLY = '';

/**
 * The argument name a source with no field list is assumed to read.
 *
 * @type {string}
 */
export const OPAQUE_ARG = 'key';

/**
 * Sorts registered binding sources by whether they can describe themselves.
 *
 * The post type is handed to every source rather than only to those declaring
 * `postType` in `usesContext`: it is the one piece of context a pattern can
 * supply, and a source that did not ask for it ignores it.
 *
 * `core/pattern-overrides` is in neither list; the panel offers it directly.
 *
 * @param {Object<string, Object>} sources  The registered sources.
 * @param {Function}               select   The registry's `select`.
 * @param {string}                 postType The post type whose fields to list.
 * @return {{listed: Array<Object>, opaque: Array<Object>}} Sources that can publish a
 *         field list, with the fields they published, and those that cannot.
 */
export function collectBindingSources( sources, select, postType ) {
	const listed = [];
	const opaque = [];

	if ( ! postType ) {
		return { listed, opaque };
	}

	Object.entries( sources || {} ).forEach( ( [ name, source ] ) => {
		if ( name === OVERRIDES_SOURCE ) {
			return;
		}

		const label = source?.label || name;

		if ( typeof source?.getFieldsList !== 'function' ) {
			opaque.push( { name, label } );
			return;
		}

		let fields = [];

		try {
			fields = normalizeFields(
				source.getFieldsList( { select, context: { postType } } )
			);
		} catch {
			fields = [];
		}

		listed.push( { name, label, fields } );
	} );

	return { listed, opaque };
}

/**
 * Every registered binding source, sorted by whether it can describe itself.
 *
 * @param {string} postType The post type whose fields to list.
 * @return {{listed: Array<Object>, opaque: Array<Object>}} The sorted sources.
 */
export function useBindingSources( postType ) {
	return useSelect(
		( select ) =>
			collectBindingSources(
				getBlockBindingsSources(),
				select,
				postType
			),
		[ postType ]
	);
}
