/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	TextControl,
	TextareaControl,
	Button,
	FormFileUpload,
	Notice,
	Spinner,
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
import { generatePattern, downloadCloudPattern } from '../cloud/generate';

export const PatternCreatePanel = ( { onCreated } ) => {
	const { onNavigateToEntityRecord } = useSelect( ( select ) => {
		const { getSettings } = select( blockEditorStore );
		return {
			onNavigateToEntityRecord: getSettings().onNavigateToEntityRecord,
		};
	}, [] );

	const [ prompt, setPrompt ] = useState( '' );
	const [ imageFile, setImageFile ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ cloud, setCloud ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/pattern-builder/v1/cloud/status' } )
			.then( setCloud )
			.catch( () => setCloud( { connected: false } ) );
	}, [] );

	const canGenerate =
		!! cloud?.connected && cloud.tier === 'pro' && !! cloud.ai?.enabled;
	const wantsGeneration = canGenerate && prompt.trim() !== '';

	const open = ( pattern ) => {
		if ( onCreated ) {
			onCreated( pattern );
		}
		navigateToPattern( pattern, onNavigateToEntityRecord );
	};

	/**
	 * Generate on the service, bring the result onto this site, and open it
	 * in the editor — the AI path through the same Create button.
	 */
	const createGeneratedPattern = () => {
		setBusy( true );
		setError( '' );

		generatePattern( { prompt, imageFile } )
			.then( ( cloudPattern ) =>
				downloadCloudPattern(
					cloudPattern.id,
					newPatternOptions.source
				)
			)
			.then( ( imported ) =>
				open( {
					id: imported.id,
					name: imported.id,
					source: imported.type,
				} )
			)
			.catch( ( generationError ) => {
				setBusy( false );
				setError(
					generationError.message ||
						__(
							'The pattern could not be generated.',
							'pattern-builder'
						)
				);
			} );
	};

	const createPattern = () => {
		if ( wantsGeneration ) {
			createGeneratedPattern();
			return;
		}

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
			{ cloud?.connected && (
				<div className="pattern-builder-create__ai">
					<Text weight="600">
						{ __( 'Create with AI', 'pattern-builder' ) }
					</Text>

					{ ! canGenerate && cloud.tier !== 'pro' && (
						<Text variant="muted" size="12px">
							{ __(
								'Describe a pattern and have it built for you with Pattern Builder Pro.',
								'pattern-builder'
							) }{ ' ' }
							<a
								href={
									cloud.upgradeUrl ||
									`${ ( cloud.serviceUrl || '' ).replace(
										/\/+$/,
										''
									) }/pricing/`
								}
								target="_blank"
								rel="noreferrer"
							>
								{ __( 'Upgrade', 'pattern-builder' ) }
							</a>
						</Text>
					) }

					{ ! canGenerate && cloud.tier === 'pro' && (
						<Text variant="muted" size="12px">
							{ __(
								'AI generation is currently switched off on patternbuilderwp.com.',
								'pattern-builder'
							) }
						</Text>
					) }

					{ canGenerate && (
						<>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __(
									'Describe the pattern (optional)',
									'pattern-builder'
								) }
								help={ __(
									'Leave this empty to start from a blank pattern. A generated pattern arrives with its own title and content.',
									'pattern-builder'
								) }
								placeholder={ __(
									'A pricing section with two plans and a highlighted “most popular” column…',
									'pattern-builder'
								) }
								rows={ 3 }
								value={ prompt }
								onChange={ setPrompt }
							/>
							<FormFileUpload
								accept="image/png,image/jpeg,image/webp,image/gif"
								onChange={ ( event ) =>
									setImageFile(
										event.target.files?.[ 0 ] || null
									)
								}
								variant="secondary"
							>
								{ imageFile
									? imageFile.name
									: __(
											'Attach a screenshot',
											'pattern-builder'
									  ) }
							</FormFileUpload>
							{ typeof cloud.usage?.ai_credits === 'number' && (
								<Text variant="muted" size="11px">
									{ sprintf(
										/* translators: %d: remaining credit count. */
										__(
											'%d generations left this month.',
											'pattern-builder'
										),
										cloud.usage.ai_credits
									) }
								</Text>
							) }
						</>
					) }
				</div>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Button
				icon={ addTemplate }
				disabled={
					busy || ( ! newPatternOptions.title && ! wantsGeneration )
				}
				isBusy={ busy }
				variant="primary"
				onClick={ () => createPattern() }
			>
				{ busy
					? __( 'Generating…', 'pattern-builder' )
					: __( 'Create Pattern', 'pattern-builder' ) }
			</Button>

			{ busy && (
				<Text variant="muted" size="11px">
					<Spinner />
					{ __(
						'Building your pattern — this usually takes under a minute.',
						'pattern-builder'
					) }
				</Text>
			) }
		</VStack>
	);
};
