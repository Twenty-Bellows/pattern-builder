/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	getLocalizePatternsSetting,
	getImportImagesSetting,
} from './localStorage';

/**
 * Decorates pattern saves with this plugin's save options.
 *
 * Theme pattern writes all travel through `/pattern-builder/v1/` (entity
 * saves from any editor, and the bulk process-theme action). The middleware
 * appends the localize / import-images flags the server-side file writer
 * reads, based on the user's Configuration panel settings.
 */
export const PatternSaveMonitor = () => {
	const postType = useSelect(
		( select ) => select( 'core/editor' )?.getCurrentPostType(),
		[]
	);
	const { lockPostAutosaving } = useDispatch( 'core/editor' ) || {};

	// Theme patterns are rowless entities with no autosaves endpoint.
	useEffect( () => {
		if ( postType === 'pb_pattern' && lockPostAutosaving ) {
			lockPostAutosaving( 'pattern-builder' );
		}
	}, [ postType, lockPostAutosaving ] );

	useEffect( () => {
		const middleware = ( options, next ) => {
			if (
				( options.method === 'POST' || options.method === 'PUT' ) &&
				options.path &&
				options.path.includes( '/pattern-builder/v1/' )
			) {
				const params = [];

				if ( getLocalizePatternsSetting() ) {
					params.push( 'patternBuilderLocalize=true' );
				}

				if ( ! getImportImagesSetting() ) {
					// Only add parameter if disabled (since default is true).
					params.push( 'patternBuilderImportImages=false' );
				}

				if ( params.length > 0 ) {
					const separator = options.path.includes( '?' ) ? '&' : '?';
					options.path =
						options.path + separator + params.join( '&' );
				}
			}

			return next( options );
		};

		apiFetch.use( middleware );

		// apiFetch has no way to remove middleware; this effect runs once.
	}, [] );

	// This component doesn't render anything.
	return null;
};
