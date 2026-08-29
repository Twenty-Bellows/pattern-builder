import { useState, useEffect, useCallback } from '@wordpress/element';

import { PatternBrowser } from './PatternBrowser';
import { PatternEditor } from './PatternEditor';

/**
 * Reads the pattern id out of the current URL.
 *
 * @return {string|null} The pattern id, or null on the browse screen.
 */
function getPatternFromUrl() {
	return new URL( window.location.href ).searchParams.get( 'pattern' );
}

/**
 * The Pattern Builder admin app: a pattern browser, and a full-screen editor
 * for the theme pattern named by the `pattern` URL parameter.
 *
 * @param {Object} props          Component props.
 * @param {Object} props.settings The settings the PHP side printed.
 */
export function PatternBuilderAdminApp( { settings } ) {
	const [ patternId, setPatternId ] = useState(
		settings.pattern || getPatternFromUrl()
	);

	// Keep the URL shareable and the browser's back button working.
	useEffect( () => {
		const onPopState = () => setPatternId( getPatternFromUrl() );
		window.addEventListener( 'popstate', onPopState );
		return () => window.removeEventListener( 'popstate', onPopState );
	}, [] );

	const openPattern = useCallback( ( pattern ) => {
		if ( pattern.source === 'user' ) {
			// User patterns are wp_block posts; core's editor owns them.
			window.location.href = `post.php?post=${ pattern.id }&action=edit`;
			return;
		}

		const url = new URL( window.location.href );
		url.searchParams.set( 'pattern', pattern.id );
		window.history.pushState( {}, '', url );
		setPatternId( pattern.id );
	}, [] );

	const closeEditor = useCallback( () => {
		const url = new URL( window.location.href );
		url.searchParams.delete( 'pattern' );
		window.history.pushState( {}, '', url );
		setPatternId( null );
	}, [] );

	if ( patternId ) {
		return (
			<PatternEditor
				patternId={ patternId }
				editorSettings={ settings.editorSettings || {} }
				onBack={ closeEditor }
			/>
		);
	}

	return (
		<PatternBrowser
			onEdit={ openPattern }
			editorSettings={ settings.editorSettings || {} }
		/>
	);
}
