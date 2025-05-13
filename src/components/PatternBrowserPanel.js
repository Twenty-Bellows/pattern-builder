import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl, TextareaControl, CheckboxControl, Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
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
        keyword: '',
    });

    const [newPattern, setNewPattern] = useState({
        title: '',
    });

    const updateFilterOptions = (key, value) => {
        const updatedFilters = { ...filterOptions, [key]: value };
        setFilterOptions(updatedFilters);
        if (onFilterChange) {
            onFilterChange(updatedFilters);
        }
    };

    const updateNewPattern = (key, value) => {
        setNewPattern({ ...newPattern, [key]: value });
    };

    const handleCreatePattern = () => {
        if (onCreatePattern) {
            onCreatePattern(newPattern);
        }
        setNewPattern({
	    title: '',
        });
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
	}, {'all': { label: __('All', 'pattern-manager'), value: 'all' } }));

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

                <SelectControl
                    label={__('Category', 'pattern-manager')}
                    value={filterOptions.category}
                    options={patternCategories}
                    onChange={(value) => updateFilterOptions('category', value)}
                />

        </div>
    );
};

