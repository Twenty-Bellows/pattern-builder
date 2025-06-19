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
import { useNavigator } from '@wordpress/components';
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

import { fetchAllPatterns } from '../utils/resolvers';
import { PatternCreatePanel } from './PatternCreatePanel';
import { useEffect } from 'react';
import { useMemo } from 'react';
import { PatternBrowserPanel } from './PatternBrowserPanel';

export const EditorSidePanel = () => {

	const [	allPatterns, setAllPatterns ] = useState([]);

	useEffect(() => {
		fetchAllPatterns()
			.then((patterns) => {
				setAllPatterns(patterns);
			})
			.catch((error) => {
				console.error('Error fetching patterns:', error);
			});
	}, []);

	const patternCategories = useMemo(() => {
		const categories = Object.values(allPatterns.reduce((acc, pattern) => {
			if (pattern.inserter === false) {
				return acc; // Skip patterns that are not in the inserter
			}
			pattern.categories.forEach((category) => {
				if (!acc[category]) {
					acc[category] = {
						label: category.charAt(0).toUpperCase() + category.slice(1),
						value: category,
					};
				}
			});
			return acc;
		}, {}));
		categories.push({
			label: __('Uncategorized', 'pattern-builder'),
			value: 'uncategorized',
		});
		categories.push({
			label: __('Hidden', 'pattern-builder'),
			value: 'hidden',
		});
		return categories;
	}, [allPatterns]);

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
								<Navigator.Button
									icon={tool}
									path="/create"
								>
										{__('Create Pattern', 'pattern-builder')}
										<Icon icon={chevronRight} />
								</Navigator.Button>
								<Divider />
								<Text style={{marginBottom: '10px'}}>{__('Pattern Categories', 'pattern-builder')}</Text>
								{patternCategories.map((category) => (
									<Navigator.Button
										key={category.value}
										icon={widget}
										path={`/browse/${category.value}`}
									>
										{category.label}
										<Icon icon={chevronRight} />
									</Navigator.Button>
								))}
							</VStack>
						</PanelBody>
					</Navigator.Screen>
					<Navigator.Screen path="/create">
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
									{__('Create Pattern', 'pattern-builder')}
								</Heading>
							</HStack>
							<PatternCreatePanel/>
						</PanelBody>
					</Navigator.Screen>
					<Navigator.Screen path="/browse/:category">
						<PanelBody>
							<VStack spacing={4}>
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
									{__('Browse', 'pattern-builder')}
								</Heading>
							</HStack>
								<PatternBrowserPanel allPatterns={allPatterns}/>
							</VStack>
						</PanelBody>
					</Navigator.Screen>
				</Navigator>
			</PluginSidebar>
		</>
	);
}
