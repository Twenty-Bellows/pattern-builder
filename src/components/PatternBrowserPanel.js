import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, TextControl, TextareaControl, CheckboxControl, Button } from '@wordpress/components';
import { useState } from '@wordpress/element';

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

    return (
        <div className="pattern-manager__sidebar">

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

				<hr />

                <TextControl
                    label={__('Name', 'pattern-manager')}
                    value={newPattern.title}
                    placeholder={__('Enter pattern name...', 'pattern-manager')}
                    onChange={(value) => updateNewPattern('title', value)}
                />
                <Button
		    		variant="primary"
                    onClick={handleCreatePattern}
                    disabled={!newPattern.title}
					style={{ width: '100%' }}
                >
                    {__('Create Pattern', 'pattern-manager')}
                </Button>
        </div>
    );
};

