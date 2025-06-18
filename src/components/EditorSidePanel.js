/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { TextControl, TextareaControl, SelectControl, ToggleControl, Button, FormTokenField, Panel, PanelBody } from '@wordpress/components';
import { Navigator } from '@wordpress/components';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalDivider as Divider,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	Icon,
	FlexItem,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalSpacer as Spacer,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import {
	widget,
	tool,
	copy,
	download,
	edit,
	code,
	chevronLeft,
	chevronRight,
	addCard,
	blockMeta,
	help,
	trash,
} from '@wordpress/icons';
import { store as blockEditorStore } from '@wordpress/block-editor';



export const EditorSidePanel = () => {

	const { onNavigateToEntityRecord } = useSelect(
		( select ) => {
			const { getSettings } = select( blockEditorStore );
			return {
				onNavigateToEntityRecord: getSettings().onNavigateToEntityRecord,
			};
		},
		[]
	);

	const openPatternBrowser = () => {
		console.log('Opening pattern browser...');
	}

	const createPattern = () => {
		createPatternCall(newPatternOptions)
			.then((pattern) => {
				console.log('Pattern created successfully:', pattern);
				onNavigateToEntityRecord({
					postId: pattern.id,
					postType: 'wp_block'
				});
			})
			.catch((error) => {
				console.error('Error creating pattern:', error);
			});
	}

	const [ newPatternOptions, setNewPatternOptions ] = useState({
		synced: true,
		status: 'publish',
		source: 'theme',
		title: '',
		description: '',
	});

	const createPatternCall = (pattern) => {

		if (!pattern.synced) {
			pattern.meta = {
				wp_pattern_sync_status:'unsynced'
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
		<>
			<PluginSidebarMoreMenuItem
				target="pattern-builder-sidebar"
				icon={tool}
			>
				{_x('Pattern Builder', 'UI String', 'pattern-builder')}
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				className='pattern-builder__editor-sidebar'
				name="pattern-builder-sidebar"
				icon={widget}
				title={_x(
					'Pattern Builder',
					'UI String',
					'pattern-builder'
				)}
			>
				<Navigator initialPath="/">
					<Navigator.Screen path="/">
						<PanelBody>
							<VStack spacing={0}>
								<Button
									icon={widget}
									onClick={() => openPatternBrowser()}
								>
									{__(
										'Browse All Patterns',
										'pattern-builder'
									)}
								</Button>
								<Navigator.Button
									icon={tool}
									path="/create/source"
								>
										{__('Create Pattern', 'pattern-builder')}
										<Icon icon={chevronRight} />
								</Navigator.Button>
							</VStack>
						</PanelBody>
					</Navigator.Screen>
					<Navigator.Screen path="/create/source">
						<PanelBody>
							<HStack spacing={2} alignment="left">
								<Navigator.BackButton
									icon={chevronLeft}
									label={__('Back', 'pattern-builder')}
								/>
								<Heading
									level={2}
									size={13}
									style={ { margin: 0 } }
								>
									{__('Choose Location', 'pattern-builder')}
								</Heading>
							</HStack>
							<VStack>
								<Divider />
								<Text>
									{ __(
										'Would you like to create a new User Pattern or a Theme Pattern? (You may change the type later.)',
										'pattern-builder'
									) }
								</Text>
								<Navigator.Button
									icon={tool}
									path="/create/type"
									onClick={() => {
										setNewPatternOptions({
											...newPatternOptions,
											source: 'theme',
										});
									}}
								>
										{__('Create Theme Pattern', 'pattern-builder')}
										<Icon icon={chevronRight} />
								</Navigator.Button>
								<Text variant="muted">
									{ __(
										'Theme Patterns are stored in files in your theme. They are tied to the current theme and can be exported with your theme to be used in other environments.',
										'pattern-builder'
									) }
								</Text>
								<Divider />
								<Navigator.Button
									icon={tool}
									path="/create/type"
									onClick={() => {
										setNewPatternOptions({
											...newPatternOptions,
											source: 'user',
										});
									}}
								>
										{__('Create User Pattern', 'pattern-builder')}
										<Icon icon={chevronRight} />
								</Navigator.Button>
								<Text variant="muted">
									{ __(
										'User Patterns are stored in the database and can be used across themes. They are not tied to a specific theme but are only available in this environment.',
										'pattern-builder'
									) }
								</Text>
							</VStack>
						</PanelBody>
					</Navigator.Screen>
					<Navigator.Screen path="/create/type">
						<PanelBody>
							<HStack spacing={2} alignment="left">
								<Navigator.BackButton
									icon={chevronLeft}
									label={__('Back', 'pattern-builder')}
								/>
								<Heading
									level={2}
									size={13}
									style={ { margin: 0 } }
								>
									{__('Choose Type', 'pattern-builder')}
								</Heading>
							</HStack>
							<VStack>
								<Divider />
								<Navigator.Button
									icon={tool}
									path="/create/details"
									onClick={() => {
										setNewPatternOptions({
											...newPatternOptions,
											synced: true,
										});
									}}
								>
										{__('Create Synced Pattern', 'pattern-builder')}
										<Icon icon={chevronRight} />
								</Navigator.Button>
								<Text variant="muted">
									{ __(
										'Synced Patterns can be reused across your site and will be updated automatically when the original pattern is updated. Certain parts of the pattern (text and images) can be customized wherever they are used. This is useful for patterns that are used in multiple places and when you wish your design to be preserved and easily updated.',
										'pattern-builder'
									) }
								</Text>
								<Divider />
								<Navigator.Button
									icon={tool}
									path="/create/details"
									onClick={() => {
										setNewPatternOptions({
											...newPatternOptions,
											synced: false,
										});
									}}
								>
										{__('Create Unsynced Pattern', 'pattern-builder')}
										<Icon icon={chevronRight} />
								</Navigator.Button>
								<Text variant="muted">
									{ __(
										'Unsynced Patterns can be customized freely and will not update automatically when the original pattern is updated. This is useful for one-off designs or when you want to have full control over the pattern without worrying about updates.',
										'pattern-builder'
									) }
								</Text>
							</VStack>
						</PanelBody>
					</Navigator.Screen>
					<Navigator.Screen path="/create/details">
						<PanelBody>
							<HStack spacing={2} alignment="left">
								<Navigator.BackButton
									icon={chevronLeft}
									label={__('Back', 'pattern-builder')}
								/>
								<Heading
									level={2}
									size={13}
									style={ { margin: 0 } }
								>
									{__('Pattern Details', 'pattern-builder')}
								</Heading>
							</HStack>
							<VStack spacing={4}>
								<Divider />
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
								<Button
									icon={copy}
									disabled={!newPatternOptions.title}
									variant="primary"
									onClick={() => createPattern()}
								>
									{__('Create Pattern', 'pattern-builder')}
								</Button>
							</VStack>
						</PanelBody>
					</Navigator.Screen>
				</Navigator>
			</PluginSidebar>
		</>
	);
}
