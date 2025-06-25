/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

import { getLocalizePatternsSetting, setLocalizePatternsSetting, getImportImagesSetting, setImportImagesSetting } from '../utils/localStorage';

export const PatternBuilderConfiguration = () => {
	const [ localizePatterns, setLocalizePatterns ] = useState( false );
	const [ importImages, setImportImages ] = useState( true );

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
		</VStack>
	);
};