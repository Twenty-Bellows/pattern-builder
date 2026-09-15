import apiFetch from '@wordpress/api-fetch';
import { getBlockBindingsSources } from '@wordpress/blocks';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

import { OVERRIDES_SOURCE, normalizeFields } from '../../utils/bindings';

/**
 * The lens value meaning no post type, so no source is offered.
 *
 * @type {string}
 */
export const USER_INPUT_ONLY = '';

/**
 * Merges a source's own fields with the ones declared for it.
 *
 * A source registered in PHP cannot publish a field list at all — core's
 * registration rejects any property beyond `label`, `get_value_callback` and
 * `uses_context` — so the `pattern_builder_binding_fields` filter is how those
 * sources, and any field a site wants to add to an existing source, reach the
 * panel.
 *
 * @param {Object<string, Object>} sources  The registered sources.
 * @param {Function}               select   The registry's `select`.
 * @param {string}                 postType The post type whose fields to list.
 * @param {Object<string, Array>}  declared Fields from the filter, by source.
 * @return {Array<{name: string, label: string, fields: Array}>} The sources.
 */
export function collectBindingSources( sources, select, postType, declared ) {
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

			return {
				name,
				label: source?.label || name,
				fields: [ ...fields, ...normalizeFields( declared?.[ name ] ) ],
			};
		} );
}

/**
 * The fields declared for this post type through the PHP filter.
 *
 * Fetched per post type rather than bootstrapped for all of them, so a site
 * with many fields does not pay for the ones nobody is looking at.
 *
 * @param {string} postType The post type whose fields to list.
 * @return {Object<string, Array>} Fields by source name.
 */
function useDeclaredFields( postType ) {
	const [ declared, setDeclared ] = useState( {} );

	useEffect( () => {
		if ( ! postType ) {
			setDeclared( {} );
			return undefined;
		}

		let ignore = false;

		apiFetch( {
			path: addQueryArgs( '/pattern-builder/v1/binding-fields', {
				post_type: postType,
			} ),
		} )
			.then( ( fields ) => {
				if ( ! ignore ) {
					setDeclared( fields || {} );
				}
			} )
			.catch( () => {
				if ( ! ignore ) {
					setDeclared( {} );
				}
			} );

		return () => {
			ignore = true;
		};
	}, [ postType ] );

	return declared;
}

/**
 * The post types that have anything to bind to.
 *
 * Asked of the server rather than worked out here: deciding it in the editor
 * would mean enumerating every post type's meta, and `core/post-meta` resolves
 * that with a request apiece.
 *
 * @return {string[]|undefined} Post type slugs, or `undefined` until loaded.
 */
export function useBindablePostTypes() {
	const [ postTypes, setPostTypes ] = useState( undefined );

	useEffect( () => {
		let ignore = false;

		apiFetch( { path: '/pattern-builder/v1/binding-fields' } )
			.then( ( types ) => {
				if ( ! ignore ) {
					setPostTypes( Array.isArray( types ) ? types : [] );
				}
			} )
			.catch( () => {
				if ( ! ignore ) {
					setPostTypes( [] );
				}
			} );

		return () => {
			ignore = true;
		};
	}, [] );

	return postTypes;
}

/**
 * Every registered binding source, with the fields it offers for a post type.
 *
 * @param {string} postType The post type whose fields to list.
 * @return {Array<{name: string, label: string, fields: Array}>} The sources.
 */
export function useBindingSources( postType ) {
	const declared = useDeclaredFields( postType );

	return useSelect(
		( select ) =>
			collectBindingSources(
				getBlockBindingsSources(),
				select,
				postType,
				declared
			),
		[ postType, declared ]
	);
}
