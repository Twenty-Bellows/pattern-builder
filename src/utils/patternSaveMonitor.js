/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { getLocalizePatternsSetting, getImportImagesSetting } from './localStorage';

/**
 * Component to monitor pattern saving and add localization flag when needed
 */
export const PatternSaveMonitor = () => {
	const { isSavingPost, postType, currentPost } = useSelect( ( select ) => {
		const { isSavingPost: _isSavingPost, getCurrentPostType, getCurrentPost } = select( 'core/editor' );
		return {
			isSavingPost: _isSavingPost(),
			postType: getCurrentPostType(),
			currentPost: getCurrentPost(),
		};
	}, [] );

	useEffect( () => {
		// Set up API fetch middleware to add localization query parameter when needed
		const middleware = ( options, next ) => {
			// Check if this is a POST request to save/update posts
			if ( options.method === 'POST' || options.method === 'PUT' ) {
				// Check if the path matches post saving endpoints
				if ( options.path && (
					options.path.includes( '/wp/v2/blocks/' ) ||
					options.path.includes( '/pattern-builder/v1/' ) ||
					( options.path.includes( '/wp/v2/posts/' ) && options.data?.type === 'pb_block' )
				) ) {
					// Check settings
					const shouldLocalize = getLocalizePatternsSetting();
					const shouldImportImages = getImportImagesSetting();

					// Build query parameters
					const params = [];
					
					if ( shouldLocalize ) {
						params.push( 'patternBuilderLocalize=true' );
					}
					
					if ( ! shouldImportImages ) {
						// Only add parameter if disabled (since default is true)
						params.push( 'patternBuilderImportImages=false' );
					}

					// Add parameters to the path if any are needed
					if ( params.length > 0 ) {
						const separator = options.path.includes( '?' ) ? '&' : '?';
						options.path = options.path + separator + params.join( '&' );
					}
				}
			}

			return next( options );
		};

		// Add the middleware
		apiFetch.use( middleware );

		// Return cleanup function to remove middleware
		return () => {
			// Note: apiFetch doesn't have a direct way to remove middleware
			// but since this effect only runs once, it's acceptable
		};
	}, [] );

	// Also monitor the saving state for logging/debugging
	useEffect( () => {
		if ( isSavingPost && postType === 'pb_block' ) {
			const shouldLocalize = getLocalizePatternsSetting();
			const shouldImportImages = getImportImagesSetting();
			
			const settings = [];
			if ( shouldLocalize ) settings.push( 'localize=true' );
			if ( ! shouldImportImages ) settings.push( 'importImages=false' );
			
			if ( settings.length > 0 ) {
				console.log( `Saving theme pattern with settings (${settings.join(', ')}):`, currentPost?.title );
			}
		}
	}, [ isSavingPost, postType, currentPost ] );

	// This component doesn't render anything
	return null;
};
