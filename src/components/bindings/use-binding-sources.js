import { getBlockBindingsSources } from '@wordpress/blocks';
import { useSelect } from '@wordpress/data';

import { OVERRIDES_SOURCE, normalizeFields } from '../../utils/bindings';

/**
 * The lens value meaning no post type, so no source is offered.
 *
 * @type {string}
 */
export const USER_INPUT_ONLY = '';

/**
 * The argument name most sources read, and the default for a typed binding.
 *
 * Core's own `core/post-data` and `core/term-data` read `field` instead, so
 * the name is editable rather than fixed.
 *
 * @type {string}
 */
export const DEFAULT_ARG = 'key';

/**
 * The one source whose editor field list and render-time gate are the same.
 *
 * @type {string}
 */
export const POST_META_SOURCE = 'core/post-meta';

/**
 * Every registered binding source, with whatever fields it publishes.
 *
 * Publishing a field list is an editor convenience: resolution happens in PHP,
 * from whatever is in `args`, and does not depend on it. So every source is
 * returned whether or not it could describe itself, and a source with no
 * fields is still bindable by typing the argument it reads.
 *
 * The post type is handed to every source rather than only to those declaring
 * `postType` in `usesContext`: it is the one piece of context a pattern can
 * supply, and a source that did not ask for it ignores it.
 *
 * `core/pattern-overrides` is left out; the panel offers it directly.
 *
 * @param {Object<string, Object>} sources  The registered sources.
 * @param {Function}               select   The registry's `select`.
 * @param {string}                 postType The post type whose fields to list.
 * @return {Array<{name: string, label: string, fields: Array}>} The sources.
 */
export function collectBindingSources( sources, select, postType ) {
	if ( ! postType ) {
		return [];
	}

	return Object.entries( sources || {} )
		.filter( ( [ name ] ) => name !== OVERRIDES_SOURCE )
		.map( ( [ name, source ] ) => {
			let fields = [];

			if ( typeof source?.getFieldsList === 'function' ) {
				try {
					fields = normalizeFields(
						source.getFieldsList( {
							select,
							context: { postType },
						} )
					);
				} catch {
					fields = [];
				}
			}

			return { name, label: source?.label || name, fields };
		} );
}

/**
 * Every registered binding source, with whatever fields it publishes.
 *
 * @param {string} postType The post type whose fields to list.
 * @return {Array<{name: string, label: string, fields: Array}>} The sources.
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
