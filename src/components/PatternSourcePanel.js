import { __ } from '@wordpress/i18n';
import {
	Button,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useState } from '@wordpress/element';

import { navigateToPattern } from '../utils/patternNavigation';

/**
 * Shows where a pattern is stored and converts it to the other storage.
 *
 * A theme pattern lives in a PHP file in the theme; converting it moves the
 * content into a wp_block post (exporting theme image assets to the media
 * library) and deletes the file. A user pattern lives in the database;
 * converting it writes a pattern file (importing its images into the theme)
 * and deletes the post. Conversion changes the pattern's identity, so it acts
 * on the last saved version and then opens the converted pattern.
 *
 * @param {Object} root0             Component props.
 * @param {Object} root0.patternPost The pattern's entity record.
 * @param {string} root0.postType    The pattern's post type.
 */
export const PatternSourcePanel = ( { patternPost, postType } ) => {
	const [ isConverting, setIsConverting ] = useState( false );
	const { createErrorNotice } = useDispatch( noticesStore );
	const { onNavigateToEntityRecord } = useSelect( ( select ) => {
		return {
			onNavigateToEntityRecord:
				select( blockEditorStore ).getSettings()
					.onNavigateToEntityRecord,
		};
	}, [] );

	if ( ! patternPost ) {
		return null;
	}

	const isThemePattern = postType === 'pb_pattern';

	const convert = async () => {
		setIsConverting( true );

		try {
			let converted;

			if ( isThemePattern ) {
				converted = await apiFetch( {
					path: `/pattern-builder/v1/patterns/${ patternPost.id }`,
					method: 'PUT',
					data: { source: 'user' },
				} );
			} else {
				converted = await apiFetch( {
					path: '/pattern-builder/v1/patterns',
					method: 'POST',
					data: { fromWpBlock: patternPost.id },
				} );
			}

			navigateToPattern(
				{
					id: converted.id,
					name: converted.name,
					source: converted.source,
				},
				onNavigateToEntityRecord
			);
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__(
						'The pattern could not be converted.',
						'pattern-builder'
					),
				{ type: 'snackbar' }
			);
			setIsConverting( false );
		}
	};

	return (
		<VStack spacing={ 3 }>
			{ isThemePattern ? (
				<>
					<Text variant="muted">
						{ __(
							'This is a Theme Pattern. It is stored as a file in your theme, is tied to the current theme, and can be shipped with the theme to other environments.',
							'pattern-builder'
						) }
					</Text>
					<Button
						variant="secondary"
						isBusy={ isConverting }
						disabled={ isConverting }
						onClick={ convert }
					>
						{ __( 'Convert to User Pattern', 'pattern-builder' ) }
					</Button>
					<Text variant="muted">
						{ __(
							'Converting moves the pattern into the database (exporting its theme images to the media library) and deletes the theme file. The last saved version is converted.',
							'pattern-builder'
						) }
					</Text>
				</>
			) : (
				<>
					<Text variant="muted">
						{ __(
							'This is a User Pattern. It is stored in the database, works across themes, but only exists in this environment.',
							'pattern-builder'
						) }
					</Text>
					<Button
						variant="secondary"
						isBusy={ isConverting }
						disabled={ isConverting }
						onClick={ convert }
					>
						{ __( 'Convert to Theme Pattern', 'pattern-builder' ) }
					</Button>
					<Text variant="muted">
						{ __(
							'Converting writes the pattern into a file in the active theme (importing its images as theme assets) and deletes the database copy. The last saved version is converted.',
							'pattern-builder'
						) }
					</Text>
				</>
			) }
		</VStack>
	);
};
