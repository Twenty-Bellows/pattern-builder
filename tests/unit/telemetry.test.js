/**
 * The browse app's side of telemetry: nothing leaves the browser unless
 * the site said yes.
 */

import apiFetch from '@wordpress/api-fetch';

import {
	getTelemetryState,
	hasDeclinedTelemetry,
	setTelemetryConsent,
	setTelemetryState,
	shouldAskForTelemetry,
	track,
} from '../../src/utils/telemetry';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'telemetry', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
		setTelemetryState( { consent: '', enabled: false } );
	} );

	it( 'asks when nobody has answered, and only then', () => {
		expect( shouldAskForTelemetry() ).toBe( true );
		expect( hasDeclinedTelemetry() ).toBe( false );

		setTelemetryState( { consent: 'declined', enabled: false } );
		expect( shouldAskForTelemetry() ).toBe( false );
		expect( hasDeclinedTelemetry() ).toBe( true );
	} );

	it( 'sends nothing unless enabled', () => {
		track( 'browser_opened' );
		expect( apiFetch ).not.toHaveBeenCalled();

		setTelemetryState( { consent: 'allowed', enabled: true } );
		track( 'pattern_created', { kind: 'design' } );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/pattern-builder/v1/telemetry/event',
			method: 'POST',
			data: { event: 'pattern_created', properties: { kind: 'design' } },
		} );
	} );

	it( 'takes the answer from the server, not from the click', async () => {
		apiFetch.mockResolvedValueOnce( { consent: 'allowed', enabled: true } );

		await setTelemetryConsent( true );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/pattern-builder/v1/telemetry/consent',
			method: 'POST',
			data: { allow: true },
		} );
		expect( getTelemetryState() ).toEqual( {
			consent: 'allowed',
			enabled: true,
		} );
	} );

	it( 'ignores a malformed state', () => {
		setTelemetryState( { consent: 'allowed', enabled: true } );
		setTelemetryState( null );
		expect( getTelemetryState().enabled ).toBe( true );
	} );
} );
