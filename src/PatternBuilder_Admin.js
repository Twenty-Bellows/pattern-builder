/**
 * The Appearance → Pattern Builder screen: browse every pattern, and edit
 * theme patterns in a full-screen block editor that saves straight to the
 * pattern files.
 */

import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { registerCoreBlocks } from '@wordpress/block-library';

import { PatternBuilderAdminApp } from './admin/App';
import './admin/admin.scss';

domReady( () => {
	const mountPoint = document.getElementById( 'pattern-builder-admin' );

	if ( ! mountPoint ) {
		return;
	}

	// Core's editor screens do this during boot; this page boots itself.
	registerCoreBlocks();

	createRoot( mountPoint ).render(
		<PatternBuilderAdminApp settings={ window.patternBuilderAdmin || {} } />
	);
} );
