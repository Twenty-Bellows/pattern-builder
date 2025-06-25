/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import {
	ToggleControl,
	Button,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalDivider as Divider,
} from '@wordpress/components';

import { getLocalizePatternsSetting, setLocalizePatternsSetting, getImportImagesSetting, setImportImagesSetting } from '../utils/localStorage';

export const PatternBuilderConfiguration = () => {
	const [ localizePatterns, setLocalizePatterns ] = useState( false );
	const [ importImages, setImportImages ] = useState( true );
	const [ isProcessing, setIsProcessing ] = useState( false );
	
	const { createSuccessNotice, createErrorNotice } = useDispatch( noticesStore );

	// Load the settings from localStorage on component mount
	useEffect( () => {
		const savedLocalizeValue = getLocalizePatternsSetting();
		const savedImportValue = getImportImagesSetting();
		setLocalizePatterns( savedLocalizeValue );
		setImportImages( savedImportValue );
	}, [] );

	// Handle toggle changes
	const handleLocalizeToggle = ( value ) => {
		setLocalizePatterns( value );
		setLocalizePatternsSetting( value );
	};

	const handleImportImagesToggle = ( value ) => {
		setImportImages( value );
		setImportImagesSetting( value );
	};

	// Handle reprocess all theme patterns
	const handleReprocessPatterns = async () => {
		setIsProcessing( true );
		
		try {
			// Build query parameters based on current settings
			const params = [];
			if ( localizePatterns ) {
				params.push( 'localize=true' );
			}
			if ( ! importImages ) {
				params.push( 'importImages=false' );
			}
			
			const queryString = params.length > 0 ? '?' + params.join( '&' ) : '';
			
			const response = await apiFetch( {
				path: `/pattern-builder/v1/process-theme${queryString}`,
				method: 'POST',
			} );
			
			if ( response.success ) {
				createSuccessNotice( response.message, {
					isDismissible: true,
				} );
			} else {
				createErrorNotice( response.message || __( 'Failed to process theme patterns.', 'pattern-builder' ), {
					isDismissible: true,
				} );
			}
		} catch ( error ) {
			createErrorNotice( __( 'Error reprocessing theme patterns. Please try again.', 'pattern-builder' ), {
				isDismissible: true,
			} );
		} finally {
			setIsProcessing( false );
		}
	};

	return (
		<VStack spacing={ 4 }>
			<ToggleControl
				label={ __( 'Localize Patterns', 'pattern-builder' ) }
				help={ __(
					'When enabled, patterns will be processed for localization when saved as theme patterns.',
					'pattern-builder'
				) }
				checked={ localizePatterns }
				onChange={ handleLocalizeToggle }
			/>
			<ToggleControl
				label={ __( 'Import Images to Theme', 'pattern-builder' ) }
				help={ __(
					'When enabled, images will be downloaded and imported into the theme assets folder when saving theme patterns.',
					'pattern-builder'
				) }
				checked={ importImages }
				onChange={ handleImportImagesToggle }
			/>
			<Divider />
			<VStack spacing={ 2 }>
				<Button
					variant="secondary"
					onClick={ handleReprocessPatterns }
					isBusy={ isProcessing }
					disabled={ isProcessing }
				>
					{ isProcessing 
						? __( 'Processing...', 'pattern-builder' )
						: __( 'Reprocess All Theme Patterns', 'pattern-builder' )
					}
				</Button>
				<p style={ { fontSize: '12px', color: '#757575', margin: 0 } }>
					{ __(
						'Reprocess all theme patterns with the current configuration settings.',
						'pattern-builder'
					) }
				</p>
			</VStack>
		</VStack>
	);
};