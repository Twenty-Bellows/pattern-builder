import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
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

    const { patterns, editorSettings } = useSelect((select) => {
        return {
            patterns: select('pattern-manager').getAllPatterns(),
            editorSettings: select('pattern-manager').getEditorConfiguration(),
        };
    }, []);

    const handleFilterChange = (filters) => {
        const updatedFilteredPatterns = patterns
            .filter((pattern) => {
                // Filter patterns based on the hidden status
                if (filters.hidden === 'all') return true;
                return pattern.inserter === (filters.hidden === 'visible');
            })
            .filter((pattern) => {
                // Filter patterns based on the source
                if (filters.source === 'all') return true; // Show all patterns
                return pattern.source === filters.source; // Match the source
            })
            .filter((pattern) => {
                // Filter patterns based on the synced status
                if (filters.synced === 'all') return true; // Show all patterns
                return pattern.synced === (filters.synced === 'yes'); // Match the synced status
            })
            .filter((pattern) => {
                // Filter patterns based on the category
                if (filters.category === 'all') return true; // Show all patterns
                if (filters.category === 'uncategorized' && pattern.categories.length === 0) return true; // Show patterns without categories
                return pattern.categories.some((category) => category.slug === filters.category); // Match the category
            })
            .filter((pattern) => {
                // Filter patterns based on the block type
                if (!filters.blockType || filters.blockType === 'all') return true; // Show all patterns
                if (filters.blockType === 'unassigned' && pattern.blockTypes.length === 0) return true; // Show patterns without block types
                return pattern.blockTypes.includes(filters.blockType); // Match the block type
            })
            .filter((pattern) => {
                // Filter patterns based on the template type
                if (!filters.templateType || filters.templateType === 'all') return true; // Show all patterns
                if (filters.templateType === 'unassigned' && pattern.templateTypes.length === 0) return true; // Show patterns without template types
                return pattern.templateTypes.includes(filters.templateType); // Match the template type
            })
            .filter((pattern) => {
                // Filter patterns based on the post type
                if (!filters.postType || filters.postType === 'all') return true; // Show all patterns
                if (filters.postType === 'unassigned' && pattern.postTypes.length === 0) return true; // Show patterns without post types
                return pattern.postTypes.includes(filters.postType); // Match the post type
            })
            .filter((pattern) => {
                // Filter patterns based on the keyword / title
                if (!filters.keyword) return true;
                return (
                    // search pattern title
                    pattern.title.toLowerCase().includes(filters.keyword.toLowerCase()) ||
                    // search pattern keywords
                    (pattern.keywords &&
                        pattern.keywords.some((keyword) =>
                            keyword.toLowerCase().includes(filters.keyword.toLowerCase())
                        ))
                );
            });

        setFilteredPatterns(updatedFilteredPatterns);
    };

    const handleCreatePattern = (newPattern) => {
        onPatternClick(new AbstractPattern(newPattern));
    };

    return (
        <div className="pattern-manager__pattern-browser">
            <BlockEditorProvider settings={editorSettings}>
                <PatternBrowserPanel
                    patterns={patterns}
                    editorSettings={editorSettings}
                    onFilterChange={handleFilterChange}
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
