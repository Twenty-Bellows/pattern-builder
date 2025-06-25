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

import { getLocalizePatternsSetting, setLocalizePatternsSetting } from '../utils/localStorage';

export const PatternBuilderConfiguration = () => {
	const [ localizePatterns, setLocalizePatterns ] = useState( false );

	// Load the setting from localStorage on component mount
	useEffect( () => {
		const savedValue = getLocalizePatternsSetting();
		setLocalizePatterns( savedValue );
	}, [] );

	// Handle toggle change
	const handleLocalizeToggle = ( value ) => {
		setLocalizePatterns( value );
		setLocalizePatternsSetting( value );
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
		</VStack>
	);
};