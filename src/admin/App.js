import { useCallback } from '@wordpress/element';

import { PatternBrowser } from './PatternBrowser';
import { getAdminEditorUrl } from '../utils/patternNavigation';

/**
 * The Pattern Builder admin app: the pattern browser.
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
			tileBase={ settings.tileBase || '' }
			designVersion={ settings.designVersion || '' }
		/>
	);
}
