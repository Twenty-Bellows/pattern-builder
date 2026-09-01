/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	TextControl,
	TextareaControl,
	Button,
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { addTemplate } from '@wordpress/icons';
import { store as blockEditorStore } from '@wordpress/block-editor';

import { navigateToPattern } from '../utils/patternNavigation';

export const PatternCreatePanel = ( { onCreated } ) => {
	const { onNavigateToEntityRecord } = useSelect( ( select ) => {
		const { getSettings } = select( blockEditorStore );
		return {
			onNavigateToEntityRecord: getSettings().onNavigateToEntityRecord,
		};
	}, [] );

	const [ error, setError ] = useState( '' );

	const open = ( pattern ) => {
		if ( onCreated ) {
			onCreated( pattern );
		}
		navigateToPattern( pattern, onNavigateToEntityRecord );
	};

	const createPattern = () => {
		createPatternCall( newPatternOptions )
			.then( ( pattern ) =>
				open( {
					id: pattern.id,
					name: pattern.name,
					source: pattern.source || 'user',
				} )
			)
			.catch( ( createError ) => {
				setError(
					createError.message ||
						__(
							'The pattern could not be created.',
							'pattern-builder'
						)
				);
			} );
	};

	const [ newPatternOptions, setNewPatternOptions ] = useState( {
		synced: true,
		status: 'publish',
		source: 'theme',
		title: '',
		description: '',
	} );

	const createPatternCall = ( pattern ) => {
		if ( pattern.source === 'theme' ) {
			// Theme patterns are file-backed pb_pattern entities.
			return apiFetch( {
				path: '/pattern-builder/v1/patterns',
				method: 'POST',
				data: {
					title: pattern.title,
					description: pattern.description,
					synced: pattern.synced,
				},
			} );
		}

		const body = {
			title: pattern.title,
			excerpt: pattern.description,
			status: 'publish',
		};

		if ( ! pattern.synced ) {
			body.meta = {
				wp_pattern_sync_status: 'unsynced',
			};
		}

		return apiFetch( {
			path: '/wp/v2/blocks',
			method: 'POST',
			data: body,
		} );
	};
	return (
		<VStack spacing={ 4 } style={ { paddingTop: '20px' } }>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Pattern Title', 'pattern-builder' ) }
				value={ newPatternOptions.title }
				onChange={ ( value ) =>
					setNewPatternOptions( {
						...newPatternOptions,
						title: value,
					} )
				}
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Pattern Description', 'pattern-builder' ) }
				value={ newPatternOptions.description }
				rows={ 4 }
				onChange={ ( value ) =>
					setNewPatternOptions( {
						...newPatternOptions,
						description: value,
					} )
				}
				placeholder={ __(
					'A short description of the pattern',
					'pattern-builder'
				) }
			/>
			<>
				<div className="components-base-control">
					<p className="components-base-control__label">
						{ 'Where should this pattern be stored?' }
					</p>
					<ToggleGroupControl
						value={ newPatternOptions.source || 'theme' }
						onChange={ ( value ) => {
							setNewPatternOptions( {
								...newPatternOptions,
								source: value,
							} );
						} }
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="theme" label="Theme" />
						<ToggleGroupControlOption value="user" label="User" />
					</ToggleGroupControl>
				</div>
				{ newPatternOptions.source === 'theme' && (
					<Text variant="muted" size="11px">
						{ __(
							'Theme Patterns are stored as files in your theme. They are tied to the current theme and can be exported with your theme to be used in other environments.',
							'pattern-builder'
						) }
					</Text>
				) }
				{ newPatternOptions.source === 'user' && (
					<Text variant="muted" size="11px">
						{ __(
							'User Patterns are stored in the database and can be used across themes. They are not tied to a specific theme but are only available in this environment.',
							'pattern-builder'
						) }
					</Text>
				) }
			</>
			<>
				<div className="components-base-control">
					<p className="components-base-control__label">
						{ 'Should this pattern be synced?' }
					</p>
					<ToggleGroupControl
						value={ newPatternOptions.synced ? 'true' : 'false' }
						onChange={ ( value ) => {
							setNewPatternOptions( {
								...newPatternOptions,
								synced: value === 'true',
							} );
						} }
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="true" label="Synced" />
						<ToggleGroupControlOption
							value="false"
							label="Unsynced"
						/>
					</ToggleGroupControl>
				</div>
				{ newPatternOptions.synced === true && (
					<Text variant="muted" size="11px">
						{ __(
							'Synced Patterns can be reused across your site and will be updated automatically when the original pattern is updated.',
							'pattern-builder'
						) }
					</Text>
				) }
				{ newPatternOptions.synced === false && (
					<Text variant="muted" size="11px">
						{ __(
							'Unsynced Patterns can be customized freely and will not update automatically when the original pattern is updated.',
							'pattern-builder'
						) }
					</Text>
				) }
			</>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Button
				icon={ addTemplate }
				disabled={ ! newPatternOptions.title }
				variant="primary"
				onClick={ () => createPattern() }
			>
				{ __( 'Create Pattern', 'pattern-builder' ) }
			</Button>
		</VStack>
	);
};
