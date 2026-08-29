import { useCallback } from '@wordpress/element';

import { PatternBrowser } from './PatternBrowser';
import { getSiteEditorUrl } from '../utils/patternNavigation';

/**
 * The Pattern Builder admin app: the pattern browser.
 *
 * Editing always happens in the WordPress editor — user patterns open in
 * the Site Editor's pattern canvas, theme patterns in this same page's edit
 * mode (`&pattern={id}`), which hosts core's edit-post editor bound to the
 * `pb_pattern` entity.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.settings The settings the PHP side printed.
 */
export function PatternBuilderAdminApp( { settings } ) {
	const openPattern = useCallback(
		( pattern ) => {
			if ( pattern.source === 'user' ) {
				// User patterns are wp_block posts; the Site Editor edits
				// them natively.
				window.location.href = getSiteEditorUrl( pattern );
				return;
			}

			const url = new URL(
				settings.adminUrl || window.location.href,
				window.location.href
			);
			url.searchParams.set( 'pattern', pattern.id );
			window.location.href = url.toString();
		},
		[ settings.adminUrl ]
	);

	return (
		<PatternBrowser
			onEdit={ openPattern }
			editorSettings={ settings.editorSettings || {} }
		/>
	);
}
