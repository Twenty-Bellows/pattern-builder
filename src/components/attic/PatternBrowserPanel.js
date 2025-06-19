import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl, TextareaControl, CheckboxControl, Button } from '@wordpress/components';
import { useEffect, useMemo } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import store from '../utils/store'; // Import the Redux store

/**
 * PatternBrowserPanel Component
 * Displays a sidebar for filters or additional controls.
 */
export const PatternBrowserPanel = ({ editorSettings, patterns, onFilterChange, onCreatePattern }) => {

	const filterOptions = useSelect((select) => select(store).getFilterOptions(), []);

	const { setFilterOptions } = useDispatch(store);

	useEffect(() => {
		if (onFilterChange) {
			onFilterChange(filterOptions);
		}
	}, [filterOptions]);

	const updateFilterOptions = (key, value) => {
		setFilterOptions({ [key]: value });
	};

	const handleCreatePattern = (newPattern) => {
		if (onCreatePattern) {
			onCreatePattern(newPattern);
		}
	};

	const patternCategories = useMemo(() => {
		return Object.values(patterns.reduce((acc, pattern) => {
			pattern.categories.forEach((category) => {
				if (!acc[category]) {
					acc[category] = {
						label: category,
						value: category,
					};
				}
			});
			return acc;
		}, {
			'all': { label: __('All', 'pattern-builder'), value: 'all' },
			'uncategorized': { label: __('Uncategorized', 'pattern-builder'), value: 'uncategorized' }
		}));
	}, [patterns]);

	const patternBlockTypes = useMemo(() => {
		return Object.values(patterns.reduce((acc, pattern) => {
			pattern.blockTypes.forEach((blockType) => {
				if (!acc[blockType]) {
					acc[blockType] = {
						label: blockType,
						value: blockType,
					};
				}
			});
			return acc;
		}, {
			'all': { label: __('All', 'pattern-builder'), value: 'all' },
			'unassigned': { label: __('Unassigned', 'pattern-builder'), value: 'unassigned' }
		}));
	}, [patterns]);

	const patternTemplateTypes = useMemo(() => {
		return Object.values(patterns.reduce((acc, pattern) => {
			pattern.templateTypes.forEach((templateType) => {
				if (!acc[templateType]) {
					acc[templateType] = {
						label: templateType,
						value: templateType,
					};
				}
			});
			return acc;
		}, {
			'all': { label: __('All', 'pattern-builder'), value: 'all' },
			'unassigned': { label: __('Unassigned', 'pattern-builder'), value: 'unassigned' }
		}));
	}, [patterns]);

	const patternPostTypes = useMemo(() => {
		return Object.values(patterns.reduce((acc, pattern) => {
			pattern.postTypes.forEach((postTypes) => {
				if (!acc[postTypes]) {
					acc[postTypes] = {
						label: postTypes,
						value: postTypes,
					};
				}
			});
			return acc;
		}, {
			'all': { label: __('All', 'pattern-builder'), value: 'all' },
			'unassigned': { label: __('Unassigned', 'pattern-builder'), value: 'unassigned' }
		}));
	}, [patterns]);

	return (
		<div className="pattern-builder__sidebar">

			<TextControl
				label={__('Keyword / Title', 'pattern-builder')}
				value={filterOptions.keyword}
				placeholder={__('Search...', 'pattern-builder')}
				onChange={(value) => updateFilterOptions('keyword', value)}
			/>

			<ToggleGroupControl
				isBlock
				label={__('Source', 'pattern-builder')}
				value={filterOptions.source}
				onChange={(value) => updateFilterOptions('source', value)}
			>
				<ToggleGroupControlOption label={__('All', 'pattern-builder')} value="all" />
				<ToggleGroupControlOption label={__('User', 'pattern-builder')} value="user" />
				<ToggleGroupControlOption label={__('Theme', 'pattern-builder')} value="theme" />
			</ToggleGroupControl>

			<ToggleGroupControl
				isBlock
				label={__('Synced', 'pattern-builder')}
				value={filterOptions.synced}
				onChange={(value) => updateFilterOptions('synced', value)}
			>
				<ToggleGroupControlOption label={__('All', 'pattern-builder')} value="all" />
				<ToggleGroupControlOption label={__('Synced', 'pattern-builder')} value="yes" />
				<ToggleGroupControlOption label={__('Unsynced', 'pattern-builder')} value="no" />
			</ToggleGroupControl>

			<ToggleGroupControl
				isBlock
				label={__('Visiblity', 'pattern-builder')}
				value={filterOptions.hidden}
				onChange={(value) => updateFilterOptions('hidden', value)}
			>
				<ToggleGroupControlOption label={__('All', 'pattern-builder')} value="all" />
				<ToggleGroupControlOption label={__('Visible', 'pattern-builder')} value="visible" />
				<ToggleGroupControlOption label={__('Hidden', 'pattern-builder')} value="hidden" />
			</ToggleGroupControl>

			<SelectControl
				label={__('Category', 'pattern-builder')}
				value={filterOptions.category}
				options={patternCategories}
				onChange={(value) => updateFilterOptions('category', value)}
			/>

			{ patternTemplateTypes.length > 2 && (

				<SelectControl
					label={__('Template Type', 'pattern-builder')}
					value={filterOptions.templateType || 'all'}
					options={patternTemplateTypes}
					onChange={(value) => updateFilterOptions('templateType', value)}
				/>
			)}

			{ patternPostTypes.length > 2 && (
				<SelectControl
					label={__('Post Type', 'pattern-builder')}
					value={filterOptions.postType || 'all'}
					options={patternPostTypes}
					onChange={(value) => updateFilterOptions('postType', value)}
				/>
			)}

			{ patternBlockTypes.length > 2 && (
				<SelectControl
					label={__('Block Type', 'pattern-builder')}
					value={filterOptions.blockType || 'all'}
					options={patternBlockTypes}
					onChange={(value) => updateFilterOptions('blockType', value)}
				/>
			)}

			<hr style={{ width: '100%' }} />

			<Button
				style={{ width: '100%' }}
				variant='primary'
				onClick={() => handleCreatePattern({ source: 'user', synced: true })}
			>
				{__('Create User Pattern', 'pattern-builder')}
			</Button>

			<Button
				style={{ width: '100%' }}
				variant='primary'
				onClick={() => handleCreatePattern({ source: 'theme', synced: false })}
			>
				{__('Create Theme Pattern', 'pattern-builder')}
			</Button>

		</div>
	);
};

