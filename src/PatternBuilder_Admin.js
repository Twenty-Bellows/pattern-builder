/**
 * The Appearance → Pattern Builder screen. Two modes, decided by the URL's
 * `pattern` parameter: browse (the pattern grid), and edit — the WordPress
 * editor itself (core's edit-post package, the same editor post.php runs)
 * bound to the `pb_pattern` entity.
 */

import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { registerCoreBlocks } from '@wordpress/block-library';

import { PatternBuilderAdminApp } from './admin/App';
import { bootPatternEditor } from './admin/editor-boot';
import { registerPreviewBindings } from './admin/preview-bindings';
import { setTelemetryState } from './utils/telemetry';
import './admin/admin.scss';

const settings = window.patternBuilderAdmin || {};

// Whether this site allows usage reporting, as the PHP side recorded it.
setTelemetryState( settings.telemetry );

/**
 * Pins the app's bottom edge to the viewport so the browser panes scroll
 * internally instead of the page. The container sits below whatever the
 * admin renders above it (admin bar, notices, update nags), so its height
 * is measured from its actual position — and re-measured when the window
 * resizes or the content above it changes (a dismissed notice).
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

	// The admin body keeps a viewport-locked height, but #wpbody-content
	// grows and shrinks with the notices above the app.
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

		// Core's editor screens do this during boot; the browse screen (which
		// renders block previews) boots itself. The edit mode must NOT do
		// this — initializeEditor registers core blocks and the binding
		// sources on its own.
		registerCoreBlocks();
		registerPreviewBindings();

		createRoot( mountPoint ).render(
			<PatternBuilderAdminApp settings={ settings } />
		);
	} );
}
