/**
 * The browse app's side of opt-in usage telemetry.
 *
 * The PHP side (`Pattern_Builder_Telemetry`) owns the decision and does
 * the sending; this only knows whether the site said yes, asks the
 * question once, and reports what it sees. Nothing leaves the browser
 * when the answer is no — `track()` is a no-op, not a request that the
 * server discards.
 */

import apiFetch from '@wordpress/api-fetch';

const BASE = '/pattern-builder/v1/telemetry';

/**
 * The state the PHP side printed, kept current as the answer changes.
 * `consent` is '' (never asked), 'allowed' or 'declined'.
 */
let state = { consent: '', enabled: false };

/**
 * Take the state the page printed (or a status payload carried).
 *
 * @param {Object} next { consent, enabled }.
 */
export function setTelemetryState( next ) {
	if ( next && typeof next === 'object' ) {
		state = { ...state, ...next };
	}
}

/**
 * The current state.
 *
 * @return {Object} { consent, enabled }.
 */
export function getTelemetryState() {
	return state;
}

/**
 * Whether the site has not been asked yet.
 *
 * @return {boolean} True when the prompt should show.
 */
export function shouldAskForTelemetry() {
	return state.consent === '';
}

/**
 * Whether the site declined, which is when the connect panel offers again.
 *
 * @return {boolean} True when declined.
 */
export function hasDeclinedTelemetry() {
	return state.consent === 'declined';
}

/**
 * Answer the prompt.
 *
 * @param {boolean} allow The answer.
 * @return {Promise<Object>} The new state.
 */
export function setTelemetryConsent( allow ) {
	return apiFetch( {
		path: `${ BASE }/consent`,
		method: 'POST',
		data: { allow: !! allow },
	} ).then( ( next ) => {
		setTelemetryState( next );
		return next;
	} );
}

/**
 * Report an event. Fire and forget, and nothing at all unless allowed.
 *
 * @param {string} event      Event name (the service keeps the list).
 * @param {Object} properties Scalar properties (the service keeps that list too).
 */
export function track( event, properties = {} ) {
	if ( ! state.enabled ) {
		return;
	}
	apiFetch( {
		path: `${ BASE }/event`,
		method: 'POST',
		data: { event, properties },
	} ).catch( () => {} );
}
