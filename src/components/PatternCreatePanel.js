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
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { copy } from '@wordpress/icons';
import { store as blockEditorStore } from '@wordpress/block-editor';



export const PatternCreatePanel = () => {
	const { onNavigateToEntityRecord } = useSelect(
		(select) => {
			const { getSettings } = select(blockEditorStore);
			return {
				onNavigateToEntityRecord: getSettings().onNavigateToEntityRecord,
			};
		},
		[]
	);

	const createPattern = () => {
		createPatternCall(newPatternOptions)
			.then((pattern) => {
				onNavigateToEntityRecord({
					postId: pattern.id,
					postType: 'wp_block'
				});
			})
			.catch((error) => {
				console.error('Error creating pattern:', error);
			});
	}

	const [newPatternOptions, setNewPatternOptions] = useState({
		synced: true,
		status: 'publish',
		source: 'theme',
		title: '',
		description: '',
	});

	const createPatternCall = (pattern) => {

		if (!pattern.synced) {
			pattern.meta = {
				wp_pattern_sync_status: 'unsynced'
			}
		}

		return apiFetch({
			path: '/wp/v2/blocks',
			method: 'POST',
			body: JSON.stringify(pattern),
			headers: {
				'Content-Type': 'application/json',
			},
		});
	}
	return (
		<VStack spacing={4} style={{ paddingTop: '20px'}}>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={__('Pattern Title', 'pattern-builder')}
				value={newPatternOptions.title}
				onChange={(value) =>
					setNewPatternOptions({
						...newPatternOptions,
						title: value,
					})
				}
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={__(
					'Pattern Description',
					'pattern-builder'
				)}
				value={newPatternOptions.description}
				rows={4}
				onChange={(value) =>
					setNewPatternOptions({
						...newPatternOptions,
						description: value,
					})
				}
				placeholder={__(
					'A short description of the pattern',
					'pattern-builder'
				)}
			/>
			<>
				<div className="components-base-control">
					<label className="components-base-control__label">{'Where should this pattern be stored?'}</label>
					<ToggleGroupControl
						value={newPatternOptions.source || 'theme'}
						onChange={(value) => {
							setNewPatternOptions({
								...newPatternOptions,
								source: value,
							})
						}}
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="theme" label="Theme" />
						<ToggleGroupControlOption value="user" label="User" />
					</ToggleGroupControl>
				</div>
				{newPatternOptions.source === 'theme' && (
					<Text variant="muted" size="11px">
						{__(
							'Theme Patterns are stored as files in your theme. They are tied to the current theme and can be exported with your theme to be used in other environments.',
							'pattern-builder'
						)}
					</Text>
				)}
				{newPatternOptions.source === 'user' && (
					<Text variant="muted" size="11px">
						{__(
							'User Patterns are stored in the database and can be used across themes. They are not tied to a specific theme but are only available in this environment.',
							'pattern-builder'
						)}
					</Text>
				)}
			</>
			<>
				<div className="components-base-control">
					<label className="components-base-control__label">{'Should this pattern be synced?'}</label>
					<ToggleGroupControl
						value={newPatternOptions.synced ? 'true' : 'false'}
						onChange={(value) => {
							setNewPatternOptions({
								...newPatternOptions,
								synced: value === 'true',
							})
						}}
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="true" label="Synced" />
						<ToggleGroupControlOption value="false" label="Unsynced" />
					</ToggleGroupControl>
				</div>
				{newPatternOptions.synced === true && (
					<Text variant="muted" size="11px">
						{__(
							'Synced Patterns can be reused across your site and will be updated automatically when the original pattern is updated.',
							'pattern-builder'
						)}
					</Text>
				)}
				{newPatternOptions.synced === false && (
					<Text variant="muted" size="11px">
						{__(
							'Unsynced Patterns can be customized freely and will not update automatically when the original pattern is updated.',
							'pattern-builder'
						)}
					</Text>
				)}
			</>
			<Button
				icon={copy}
				disabled={!newPatternOptions.title}
				variant="primary"
				onClick={() => createPattern()}
			>
				{__('Create Pattern', 'pattern-builder')}
			</Button>
		</VStack>
	);
}
