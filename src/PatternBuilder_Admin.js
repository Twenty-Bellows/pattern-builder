/**
 * The Appearance → Pattern Builder screen.
 */

import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { registerCoreBlocks } from '@wordpress/block-library';

import { PatternBuilderAdminApp } from './admin/App';
import { bootPatternEditor } from './admin/editor-boot';
import { setTelemetryState } from './utils/telemetry';
import './admin/admin.scss';

const settings = window.patternBuilderAdmin || {};
setTelemetryState( settings.telemetry );

/**
 * Pins the app's bottom edge to the viewport so the browser panes scroll internally instead
 * of the page.
 *
 * @param {Element} el The app container.
 */
function lockToViewportBottom( el ) {
	const update = () => {
		const top = el.getBoundingClientRect().top + window.scrollY;
		el.style.height = Math.max( 400, window.innerHeight - top ) + 'px';
	};

	update();
	window.addEventListener( 'resize', update );
	if ( window.ResizeObserver ) {
		new window.ResizeObserver( update ).observe(
			document.getElementById( 'wpbody-content' ) || document.body
		);
	}
}

if ( settings.pattern ) {
	bootPatternEditor( settings );
} else {
	domReady( () => {
		const mountPoint = document.getElementById( 'pattern-builder-admin' );

		if ( ! mountPoint ) {
			return;
		}

		lockToViewportBottom( mountPoint );
		registerCoreBlocks();

		createRoot( mountPoint ).render(
			<PatternBuilderAdminApp settings={ settings } />
		);
	} );
}
