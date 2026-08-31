import { useCallback } from '@wordpress/element';

import { PatternBrowser } from './PatternBrowser';
import { getAdminEditorUrl } from '../utils/patternNavigation';

/**
 * The Pattern Builder admin app: the pattern browser.
 *
 * Editing always happens in this page's edit mode (`&pattern={id}`), which
 * hosts core's edit-post editor bound to the pattern's entity — the same
 * editor for theme and user patterns alike.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.settings The settings the PHP side printed.
 */
export function PatternBuilderAdminApp( { settings } ) {
	const openPattern = useCallback( ( pattern ) => {
		window.location.href = getAdminEditorUrl( pattern );
	}, [] );

	return (
		<PatternBrowser
			onEdit={ openPattern }
			editorSettings={ settings.editorSettings || {} }
		/>
	);
}
