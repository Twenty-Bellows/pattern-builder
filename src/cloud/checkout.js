/**
 * The Freemius overlay checkout, opened from inside wp-admin.
 */

import apiFetch from '@wordpress/api-fetch';

const SCRIPT_ID = 'pattern-builder-freemius-checkout';

/**
 * Load the checkout script once.
 *
 * @param {string} src Script URL, as the service named it.
 * @return {Promise<void>} Resolves once `FS.Checkout` exists.
 */
function loadCheckoutScript( src ) {
	if ( window.FS && window.FS.Checkout ) {
		return Promise.resolve();
	}
	return new Promise( ( resolve, reject ) => {
		let script = document.getElementById( SCRIPT_ID );
		if ( ! script ) {
			script = document.createElement( 'script' );
			script.id = SCRIPT_ID;
			script.src = src;
			script.async = true;
			document.head.appendChild( script );
		}
		script.addEventListener( 'load', () => resolve() );
		script.addEventListener( 'error', () =>
			reject( new Error( 'checkout script failed to load' ) )
		);
	} );
}

/**
 * Open the overlay for an account.
 *
 * @param {Object}   config             Checkout configuration from the service.
 * @param {Object}   callbacks          Callbacks.
 * @param {Function} callbacks.onSynced Called with the new status once the purchase is
 *                                      reported.
 * @param {Function} callbacks.onClosed Called when the overlay closes after a purchase.
 * @param {Function} callbacks.onCancel Called when the overlay is closed without one.
 * @return {Promise<void>} Resolves once the overlay is open; rejects if the script would
 * not load.
 */
export function openCheckout( config, callbacks = {} ) {
	return loadCheckoutScript( config.script ).then( () => {
		const handler = new window.FS.Checkout( {
			product_id: String( config.product_id ),
			plan_id: String( config.plan_id ),
			public_key: config.public_key,
		} );

		const options = {
			user_email: config.user_email,
			readonly_user: !! config.readonly_user,
			purchaseCompleted: ( response ) => {
				const licenseId =
					response?.purchase?.license_id || response?.license?.id;
				if ( ! licenseId ) {
					return;
				}
				apiFetch( {
					path: '/pattern-builder/v1/cloud/billing/sync',
					method: 'POST',
					data: { licenseId: Number( licenseId ) },
				} )
					.then( ( status ) => callbacks.onSynced?.( status ) )
					.catch( () => {} );
			},
			success: () => callbacks.onClosed?.(),
			cancel: () => callbacks.onCancel?.(),
		};

		if ( config.user_name ) {
			const [ first, ...rest ] = String( config.user_name ).split( ' ' );
			options.user_firstname = first;
			options.user_lastname = rest.join( ' ' );
		}
		if ( config.sandbox ) {
			options.sandbox = config.sandbox;
		}

		handler.open( options );
	} );
}
