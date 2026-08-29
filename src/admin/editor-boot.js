/**
 * Boots the WordPress editor — core's `@wordpress/edit-post` package, the
 * same editor that powers post.php — bound to a `pb_pattern` entity, so a
 * theme pattern gets the full core editing experience (header, list view,
 * inspector, document panels, keyboard shortcuts) and its saves go straight
 * to the pattern file.
 */

import domReady from '@wordpress/dom-ready';
import { registerPlugin } from '@wordpress/plugins';
import { Button } from '@wordpress/components';
import { __, isRTL } from '@wordpress/i18n';
import { chevronLeft, chevronRight } from '@wordpress/icons';
import {
	initializeEditor,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalMainDashboardButton as MainDashboardButton,
} from '@wordpress/edit-post';

/**
 * The editor assumes it lives at post.php and rewrites the address bar to
 * `post.php?post={id}` as it settles (its BrowserURL component). Theme
 * patterns have string ids post.php can't load, and this page's own URL is
 * the shareable one — so those rewrites are dropped.
 */
function keepPageUrl() {
	const original = window.history.replaceState.bind( window.history );

	window.history.replaceState = ( state, title, url ) => {
		if ( typeof url === 'string' && url.startsWith( 'post.php' ) ) {
			return;
		}

		return original( state, title, url );
	};
}

/**
 * The fullscreen-mode close button. The editor's default links to the post
 * type's list table, which a rowless type doesn't have — this one returns
 * to wherever the user came from (the Site Editor, the Pattern Builder
 * browse screen, …; the URL is validated server-side).
 *
 * @param {Object} props     Component props.
 * @param {string} props.url The URL to go back to.
 */
function BackButton( { url } ) {
	return (
		<MainDashboardButton>
			<Button
				size="compact"
				href={ url }
				label={ __( 'Back', 'pattern-builder' ) }
				showTooltip
				tooltipPosition="bottom"
				icon={ isRTL() ? chevronRight : chevronLeft }
			/>
		</MainDashboardButton>
	);
}

/**
 * Boots the editor for the pattern named by the page settings.
 *
 * @param {Object} settings The settings the PHP side printed.
 */
export function bootPatternEditor( settings ) {
	keepPageUrl();

	registerPlugin( 'pattern-builder-back-button', {
		render: () => (
			<BackButton url={ settings.backUrl || settings.adminUrl } />
		),
	} );

	domReady( () => {
		initializeEditor(
			'pattern-builder-admin',
			'pb_pattern',
			settings.pattern,
			settings.editorSettings || {},
			null
		);
	} );
}
