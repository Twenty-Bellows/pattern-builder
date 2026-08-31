import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const BASE = '/pattern-builder/v1/cloud';
const POLL_INTERVAL = 2500;

/**
 * Run an AI generation to completion: submit the prompt and optional
 * screenshot, poll the job, and resolve with the cloud pattern it produced.
 *
 * @param {Object} args           Generation input.
 * @param {string} args.prompt    Description of the pattern to build.
 * @param {File}   args.imageFile Optional screenshot to recreate.
 * @return {Promise<Object>} The generated cloud pattern summary.
 */
export function generatePattern( { prompt, imageFile } ) {
	const form = new window.FormData();
	form.append( 'prompt', prompt );
	if ( imageFile ) {
		form.append( 'image', imageFile );
	}

	return apiFetch( {
		path: `${ BASE }/generate`,
		method: 'POST',
		body: form,
	} ).then(
		( job ) =>
			new Promise( ( resolve, reject ) => {
				const timer = window.setInterval( () => {
					apiFetch( { path: `${ BASE }/generate/${ job.id }` } )
						.then( ( update ) => {
							if ( update.status === 'succeeded' ) {
								window.clearInterval( timer );
								resolve( update.pattern );
							}
							if ( update.status === 'failed' ) {
								window.clearInterval( timer );
								reject(
									new Error(
										update.error ||
											__(
												'The generation failed.',
												'pattern-builder'
											)
									)
								);
							}
						} )
						.catch( ( error ) => {
							window.clearInterval( timer );
							reject( error );
						} );
				}, POLL_INTERVAL );
			} )
	);
}

/**
 * Bring a cloud pattern onto this site.
 *
 * @param {number} cloudId     Cloud pattern ID.
 * @param {string} destination 'user' or 'theme'.
 * @return {Promise<Object>} { type, id, title }
 */
export function downloadCloudPattern( cloudId, destination ) {
	return apiFetch( {
		path: `${ BASE }/download`,
		method: 'POST',
		data: { source: 'library', cloudId, destination },
	} );
}
