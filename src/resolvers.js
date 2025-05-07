import apiFetch from '@wordpress/api-fetch';
import Pattern from './objects/Pattern';

export async function getAllPatterns() {
	return apiFetch( {
		path: '/pattern-manager/v1/patterns',
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
		},
	} )
	.then( ( response ) => {
		return response.map( ( pattern ) => {
			return new Pattern( pattern );
		} );
	});
}
