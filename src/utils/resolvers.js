import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import AbstractPattern from '../objects/AbstractPattern';

export async function fetchAllPatterns() {
	return apiFetch( {
		path: '/pattern-builder/v1/patterns',
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
		},
	} ).then( ( response ) => {
		return response.map( ( pattern ) => {
			return new AbstractPattern( pattern );
		} );
	} );
}

/**
 * The read-only patterns a plugin registered. Asked for separately from the site's own,
 * because a pattern plugin can register hundreds and only the browser's Plugin tab wants
 * them.
 *
 * @return {Promise<Array>} The patterns.
 */
export async function fetchPluginPatterns() {
	return apiFetch( {
		path: addQueryArgs( '/pattern-builder/v1/patterns', {
			source: 'plugin',
		} ),
		method: 'GET',
	} ).then( ( response ) =>
		response.map( ( pattern ) => new AbstractPattern( pattern ) )
	);
}
