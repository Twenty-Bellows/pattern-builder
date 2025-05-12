import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

/**
 * PatternBrowserPanel Component
 * Displays a sidebar for filters or additional controls.
 */
export const PatternBrowserPanel = ({ editorSettings, patterns, onFilterChange }) => {
    const [filterOptions, setFilterOptions] = useState({
        source: 'all',
        synced: 'both',
        category: 'all',
        keyword: '',
    });

    const updateFilterOptions = (key, value) => {
        const updatedFilters = { ...filterOptions, [key]: value };
        setFilterOptions(updatedFilters);
        if (onFilterChange) {
            onFilterChange(updatedFilters);
        }
    };

    return (
        <div className="pattern-manager__sidebar">
            <PanelBody title={__('Filters', 'pattern-manager')} initialOpen={true}>
                {/* Source Filter */}
                <SelectControl
                    label={__('Source', 'pattern-manager')}
                    value={filterOptions.source}
                    options={[
                        { label: __('All', 'pattern-manager'), value: 'all' },
                        { label: __('User', 'pattern-manager'), value: 'user' },
                        { label: __('Theme', 'pattern-manager'), value: 'theme' },
                        { label: __('Core', 'pattern-manager'), value: 'core' },
                    ]}
                    onChange={(value) => updateFilterOptions('source', value)}
                />

                {/* Synced Filter */}
                <SelectControl
                    label={__('Synced', 'pattern-manager')}
                    value={filterOptions.synced}
                    options={[
                        { label: __('All', 'pattern-manager'), value: 'all' },
                        { label: __('Synced', 'pattern-manager'), value: 'yes' },
                        { label: __('Unsynced', 'pattern-manager'), value: 'no' },
                    ]}
                    onChange={(value) => updateFilterOptions('synced', value)}
                />

                {/* Categories Filter */}
                <SelectControl
                    label={__('Categories', 'pattern-manager')}
                    value={filterOptions.category}
                    options={[
                        { label: __('All Categories', 'pattern-manager'), value: 'all' },
                        { label: __('Category 1', 'pattern-manager'), value: 'category1' },
                        { label: __('Category 2', 'pattern-manager'), value: 'category2' },
                    ]}
                    onChange={(value) => updateFilterOptions('category', value)}
                />

                {/* Keyword Filter */}
                <TextControl
                    label={__('Keyword', 'pattern-manager')}
                    value={filterOptions.keyword}
                    placeholder={__('Search by keyword...', 'pattern-manager')}
                    onChange={(value) => updateFilterOptions('keyword', value)}
                />
            </PanelBody>
        </div>
    );
};

