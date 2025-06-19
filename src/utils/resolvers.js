import apiFetch from '@wordpress/api-fetch';
import AbstractPattern from '../objects/AbstractPattern';

export async function fetchAllPatterns() {
	return apiFetch( {
		path: '/pattern-builder/v1/patterns',
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
		},
	} )
	.then( ( response ) => {
		return response.map( ( pattern ) => {
			return new AbstractPattern( pattern );
		} );
	});
}
