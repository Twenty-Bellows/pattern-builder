import { getBlockBindingsSources } from '@wordpress/blocks';
import { useSelect } from '@wordpress/data';

import { OVERRIDES_SOURCE, normalizeFields } from '../../utils/bindings';

/**
 * The key a source with no field list is assumed to read its value from.
 *
 * A source registered in PHP receives whatever was written to `args` as
 * `$source_args`, so there is nothing to discover and nothing to validate.
 * `key` is the name core's own registration example uses and what the sources
 * in the wild read, so it is what the typed-key control writes.
 *
 * @type {string}
 */
export const OPAQUE_ARG = 'key';

/**
 * Sorts registered binding sources by whether they can describe themselves.
 *
 * This is the part core gets wrong for a pattern. Its panel calls each source's
 * `getFieldsList` with the surrounding block context and silently drops the
 * ones that come back empty — which, inside a pattern, is all of them, because
 * a pattern has no post. Passing a post type of the author's choosing instead
 * is enough to make `core/post-meta` enumerate: it builds its list from
 * `context.postType` alone, with no `postId` involved.
 *
 * A source that publishes no `getFieldsList` cannot be enumerated at any post
 * type. That is most of them, and not only the ones registered in PHP: through
 * `@wordpress/blocks` 15.6 the store keeps `getFieldsList` for `core/post-meta`
 * and discards it for every other source unless the Gutenberg plugin is
 * running, and 16.0 opens it up. So on shipping WordPress a typed key is the
 * normal case rather than the exception, and these are returned separately
 * rather than hidden so the panel can still offer them.
 *
 * `core/pattern-overrides` is left out of both lists; the panel offers it in
 * its own right, as "Overridable".
 *
 * @param {Object<string, Object>} sources  The registered sources.
 * @param {Function}               select   The data registry's `select`, which
 *                                          a source's `getFieldsList` may use.
 * @param {string}                 postType The post type whose fields to list.
 * @return {{listed: Array<Object>, opaque: Array<Object>}} The sources that can
 *         publish a field list, each with the fields it published for this post
 *         type, and those that cannot describe themselves at all.
 */
export function collectBindingSources( sources, select, postType ) {
	const listed = [];
	const opaque = [];

	Object.entries( sources || {} ).forEach( ( [ name, source ] ) => {
		if ( name === OVERRIDES_SOURCE ) {
			return;
		}

		const label = source?.label || name;

		if ( typeof source?.getFieldsList !== 'function' ) {
			opaque.push( { name, label } );
			return;
		}

		/*
		 * Only the context the source asked for, and only what a pattern can
		 * honestly supply — the lens post type. A source that wants a `postId`
		 * gets none, which is the truth: the post is whichever one the pattern
		 * is placed in.
		 */
		const context = {};
		if ( source.usesContext?.includes( 'postType' ) ) {
			context.postType = postType;
		}

		let fields = [];

		try {
			fields = normalizeFields(
				source.getFieldsList( { select, context } )
			);
		} catch ( error ) {
			// A source that throws on a context it did not expect has no
			// fields to offer here; the panel says so rather than vanishing.
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
