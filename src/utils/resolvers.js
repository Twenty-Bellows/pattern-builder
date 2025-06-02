import apiFetch from '@wordpress/api-fetch';
import AbstractPattern from '../objects/AbstractPattern';
import { formatBlockMarkup } from './formatters';

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

export async function fetchEditorConfiguration() {
	return apiFetch({ path: `/pattern-builder/v1/global-styles` })
		.then((data) => {
			return data;
		})
		.catch((error) => {
			console.error('Error fetching global styles:', error);
		});
}

export async function savePattern(pattern) {
	pattern.content = formatBlockMarkup(pattern.content);
	return apiFetch( {
		path: '/pattern-builder/v1/pattern',
		method: 'PUT',
		body: JSON.stringify( pattern ),
		headers: {
			'Content-Type': 'application/json',
		},
	} )
	.then( ( response ) => {
		return new AbstractPattern( response );
	});
}

export async function deletePattern(pattern) {
	return apiFetch( {
		path: `/pattern-builder/v1/pattern`,
		method: 'DELETE',
		body: JSON.stringify( pattern ),
	})
	.then( ( response ) => {
		return response;
	});
}
