import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import PatternPreview from './PatternPreview';
import { PatternBrowserPanel } from './PatternBrowserPanel';
import { AbstractPattern } from '../objects/AbstractPattern';

import { BlockEditorProvider } from '@wordpress/block-editor';

/**
 * PatternBrowser Component
 * Combines PatternGrid and PatternBrowserPanel into a single layout.
 * Handles loading and error states.
 */
export const PatternBrowser = ({ onPatternClick }) => {
    const [filteredPatterns, setFilteredPatterns] = useState([]);
    const [filters, setFilters] = useState({}); // Store filters in state

    const { patterns, editorSettings } = useSelect((select) => {
        return {
            patterns: select('pattern-manager').getAllPatterns(),
            editorSettings: select('pattern-manager').getEditorConfiguration(),
        };
    }, []);

    useEffect(() => {
        const updatedFilteredPatterns = patterns
            .filter((pattern) => {
                if (filters.hidden === 'all') return true;
                return pattern.inserter === (filters.hidden === 'visible');
            })
            .filter((pattern) => {
                if (filters.source === 'all') return true;
                return pattern.source === filters.source;
            })
            .filter((pattern) => {
                if (filters.synced === 'all') return true;
                return pattern.synced === (filters.synced === 'yes');
            })
            .filter((pattern) => {
                if (filters.category === 'all') return true;
                if (filters.category === 'uncategorized' && pattern.categories.length === 0) return true;
                return pattern.categories.some((category) => category.slug === filters.category);
            })
            .filter((pattern) => {
                if (!filters.blockType || filters.blockType === 'all') return true;
                if (filters.blockType === 'unassigned' && pattern.blockTypes.length === 0) return true;
                return pattern.blockTypes.includes(filters.blockType);
            })
            .filter((pattern) => {
                if (!filters.templateType || filters.templateType === 'all') return true;
                if (filters.templateType === 'unassigned' && pattern.templateTypes.length === 0) return true;
                return pattern.templateTypes.includes(filters.templateType);
            })
            .filter((pattern) => {
                if (!filters.postType || filters.postType === 'all') return true;
                if (filters.postType === 'unassigned' && pattern.postTypes.length === 0) return true;
                return pattern.postTypes.includes(filters.postType);
            })
            .filter((pattern) => {
                if (!filters.keyword) return true;
                return (
                    pattern.title.toLowerCase().includes(filters.keyword.toLowerCase()) ||
                    (pattern.keywords &&
                        pattern.keywords.some((keyword) =>
                            keyword.toLowerCase().includes(filters.keyword.toLowerCase())
                        ))
                );
            });

        setFilteredPatterns(updatedFilteredPatterns);

    }, [patterns, filters]);

    const handleCreatePattern = (newPattern) => {
        onPatternClick(new AbstractPattern(newPattern));
    };

    return (
        <div className="pattern-manager__pattern-browser">
            <BlockEditorProvider settings={editorSettings}>
                <PatternBrowserPanel
                    patterns={patterns}
                    editorSettings={editorSettings}
                    onFilterChange={setFilters}
                    onCreatePattern={handleCreatePattern}
                />
                {filteredPatterns?.length > 0 && (
                    <div className="pattern-manager__preview-grid">
                        {filteredPatterns.map((pattern, index) => (
                            <div key={index}>
                                <PatternPreview onClick={onPatternClick} pattern={pattern} />
                            </div>
                        ))}
                    </div>
                )}
                {filteredPatterns?.length === 0 && (
                    <div>
                        <p>{__('No patterns found', 'pattern-manager')}</p>
                    </div>
                )}
            </BlockEditorProvider>
        </div>
    );
};

export default PatternBrowser;
