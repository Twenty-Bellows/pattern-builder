/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { getLocalizePatternsSetting } from './localStorage';

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
					// Check if localization is enabled
					const shouldLocalize = getLocalizePatternsSetting();

					if ( shouldLocalize ) {
						// Add query parameter to indicate this request should trigger localization
						const separator = options.path.includes( '?' ) ? '&' : '?';
						options.path = options.path + separator + 'patternBuilderLocalize=true';
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
			if ( shouldLocalize ) {
				console.log( 'Saving theme pattern with localization enabled (patternBuilderLocalize=true):', currentPost?.title );
			}
		}
	}, [ isSavingPost, postType, currentPost ] );

	// This component doesn't render anything
	return null;
};
