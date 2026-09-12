/**
 * Declares the `content` attribute on `core/pattern`.
 */

import { addFilter } from '@wordpress/hooks';

/**
 * Adds the content attribute and the context it provides to `core/pattern`.
 *
 * @param {Object} settings Block type settings.
 * @param {string} name     Block type name.
 * @return {Object} Filtered settings.
 */
export function addPatternContentAttribute( settings, name ) {
	if ( name !== 'core/pattern' ) {
		return settings;
	}

	return {
		...settings,
		attributes: {
			...settings.attributes,
			content: { type: 'object' },
		},
		providesContext: {
			...settings.providesContext,
			'pattern/overrides': 'content',
		},
		__experimentalLabel: ( attributes, { context } ) => {
			if ( context !== 'list-view' && context !== 'breadcrumb' ) {
				return undefined;
			}

			return attributes?.metadata?.name || attributes?.slug;
		},
	};
}

addFilter(
	'blocks.registerBlockType',
	'pattern-builder/pattern-content-attribute',
	addPatternContentAttribute
);
