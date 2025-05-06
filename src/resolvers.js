import apiFetch from '@wordpress/api-fetch';

export async function getAllPatterns() {
	return apiFetch( {
		path: '/pattern-manager/v1/patterns',
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
		},
	} );
}
