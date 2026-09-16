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
 */
export const PatternSaveMonitor = () => {
	const postType = useSelect(
		( select ) => select( 'core/editor' )?.getCurrentPostType(),
		[]
	);
	const { lockPostAutosaving } = useDispatch( 'core/editor' ) || {};
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
	}, [] );
	return null;
};
