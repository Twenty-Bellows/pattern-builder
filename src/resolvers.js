import apiFetch from '@wordpress/api-fetch';
import AbstractPattern from './objects/AbstractPattern';

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
			return new AbstractPattern( pattern );
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
	return apiFetch( {
		path: '/pattern-manager/v1/pattern/' + pattern.name,
		method: 'PUT',
		body: JSON.stringify( pattern ),
		headers: {
			'Content-Type': 'application/json',
		},
	} )
	.then( ( response ) => {
		// TODO: Clear pattern data in the editor so it picks up the changes
		return new AbstractPattern( response );
	});
}
