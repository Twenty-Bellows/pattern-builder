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

export async function getEditorSettings() {
	return apiFetch({ path: `/pattern-manager/v1/global-styles` })
		.then((data) => {
			return data;
		})
		.catch((error) => {
			console.error('Error fetching global styles:', error);
		});
}

export async function savePattern(pattern) {
	console.log('saving pattern', pattern);
}
