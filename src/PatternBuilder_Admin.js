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
import './admin/admin.scss';

const settings = window.patternBuilderAdmin || {};

if ( settings.pattern ) {
	bootPatternEditor( settings );
} else {
	domReady( () => {
		const mountPoint = document.getElementById( 'pattern-builder-admin' );

		if ( ! mountPoint ) {
			return;
		}

		// Core's editor screens do this during boot; the browse screen (which
		// renders block previews) boots itself. The edit mode must NOT do
		// this — initializeEditor registers core blocks on its own.
		registerCoreBlocks();

		createRoot( mountPoint ).render(
			<PatternBuilderAdminApp settings={ settings } />
		);
	} );
}
