import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl, TextareaControl, CheckboxControl, Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

/**
 * PatternBrowserPanel Component
 * Displays a sidebar for filters or additional controls.
 */
export const PatternBrowserPanel = ({ editorSettings, patterns, onFilterChange, onCreatePattern }) => {

	const [filterOptions, setFilterOptions] = useState({
		source: 'all',
		synced: 'all',
		category: 'all',
		hidden: 'visible',
		keyword: '',
	});

	useEffect(() => {
		onFilterChange(filterOptions);
	}, [patterns]);

	const updateFilterOptions = (key, value) => {
		const updatedFilters = { ...filterOptions, [key]: value };
		setFilterOptions(updatedFilters);
		if (onFilterChange) {
			onFilterChange(updatedFilters);
		}
	};

	const handleCreatePattern = (newPattern) => {
		if (onCreatePattern) {
			onCreatePattern(newPattern);
		}
	};

	const patternCategories = Object.values(patterns.reduce((acc, pattern) => {
		pattern.categories.forEach((category) => {
			if (!acc[category.slug]) {
				acc[category.slug] = {
					label: category.name,
					value: category.slug,
				};
			}
		});
		return acc;
	}, { 'all': { label: __('All', 'pattern-manager'), value: 'all' } }));

	return (
		<div className="pattern-manager__sidebar">

			<TextControl
				label={__('Keyword / Title', 'pattern-manager')}
				value={filterOptions.keyword}
				placeholder={__('Search...', 'pattern-manager')}
				onChange={(value) => updateFilterOptions('keyword', value)}
			/>

			<ToggleGroupControl
				isBlock
				label={__('Source', 'pattern-manager')}
				value={filterOptions.source}
				onChange={(value) => updateFilterOptions('source', value)}
			>
				<ToggleGroupControlOption label={__('All', 'pattern-manager')} value="all" />
				<ToggleGroupControlOption label={__('User', 'pattern-manager')} value="user" />
				<ToggleGroupControlOption label={__('Theme', 'pattern-manager')} value="theme" />
			</ToggleGroupControl>

			<ToggleGroupControl
				isBlock
				label={__('Synced', 'pattern-manager')}
				value={filterOptions.synced}
				onChange={(value) => updateFilterOptions('synced', value)}
			>
				<ToggleGroupControlOption label={__('All', 'pattern-manager')} value="all" />
				<ToggleGroupControlOption label={__('Synced', 'pattern-manager')} value="yes" />
				<ToggleGroupControlOption label={__('Unsynced', 'pattern-manager')} value="no" />
			</ToggleGroupControl>

			<ToggleGroupControl
				isBlock
				label={__('Visiblity', 'pattern-manager')}
				value={filterOptions.hidden}
				onChange={(value) => updateFilterOptions('hidden', value)}
			>
				<ToggleGroupControlOption label={__('All', 'pattern-manager')} value="all" />
				<ToggleGroupControlOption label={__('Visible', 'pattern-manager')} value="visible" />
				<ToggleGroupControlOption label={__('Hidden', 'pattern-manager')} value="hidden" />
			</ToggleGroupControl>

			<SelectControl
				label={__('Category', 'pattern-manager')}
				value={filterOptions.category}
				options={patternCategories}
				onChange={(value) => updateFilterOptions('category', value)}
			/>

			<hr style={{ width: '100%' }} />

			<Button
				style={{ width: '100%' }}
				variant='primary'
				onClick={() => handleCreatePattern({ source: 'user', synced: true })}
			>
				{__('Create User Pattern', 'pattern-manager')}
			</Button>

			<Button
				style={{ width: '100%' }}
				variant='primary'
				onClick={() => handleCreatePattern({ source: 'theme', synced: false })}
			>
				{__('Create Theme Pattern', 'pattern-manager')}
			</Button>

		</div>
	);
};

