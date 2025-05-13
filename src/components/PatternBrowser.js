import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { BlockEditorProvider } from '@wordpress/block-editor';

import { getAllPatterns, getEditorSettings } from '../resolvers';
import PatternPreview from './PatternPreview';
import { PatternBrowserPanel } from './PatternBrowserPanel';
import { AbstractPattern } from '../objects/AbstractPattern';

/**
 * PatternGrid Component
 * Displays a grid of pattern previews.
 */
export const PatternGrid = ({ onPatternClick, patterns, editorSettings }) => {
    return (
        <BlockEditorProvider
            settings={{
                ...editorSettings,
                isPreviewMode: true,
                focusMode: false,
            }}
        >
            <div className="pattern-manager__preview-grid">
                {patterns.map((pattern, index) => (
                    <div key={index}>
                        <PatternPreview onClick={onPatternClick} pattern={pattern} />
                    </div>
                ))}
            </div>
        </BlockEditorProvider>
    );
};

/**
 * PatternBrowser Component
 * Combines PatternGrid and PatternBrowserPanel into a single layout.
 * Handles loading and error states.
 */
export const PatternBrowser = ({ onPatternClick }) => {
    const [patterns, setPatterns] = useState([]);
    const [filteredPatterns, setFilteredPatterns] = useState(null);
    const [editorSettings, setEditorSettings] = useState({});
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        getAllPatterns()
            .then((patterns) => {
                setPatterns(patterns);
                setFilteredPatterns(null);
                setIsLoading(false);
            })
            .catch((err) => {
                setError(err.message || 'Something went wrong.');
                setIsLoading(false);
            });
        getEditorSettings()
            .then((data) => {
                setEditorSettings(data);
            });
    }, []);

    const handleFilterChange = (filters) => {

		if (filters.keyword.length < 2 && filters.category === 'all' && filters.source === 'all' && filters.synced === 'all') {
			setFilteredPatterns(null);
			return;
		}
		// Filter patterns based on the keyword

        const updatedFilteredPatterns = patterns
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
				return pattern.categories.some((category) => category.slug === filters.category); // Match the category
			})
			.filter((pattern) => {
				// Filter patterns based on the keyword / title
				if ( ! filters.keyword ) return true;
				return (
					// search pattern title
					pattern.title.toLowerCase().includes(filters.keyword.toLowerCase())

					// search pattern keywords
					|| (pattern.keywords && pattern.keywords.some((keyword) => keyword.toLowerCase().includes(filters.keyword.toLowerCase())))
				);
			});

        setFilteredPatterns(updatedFilteredPatterns);

    };

	const handleCreatePattern = (newPattern) => {
		onPatternClick(new AbstractPattern( {
			...newPattern,
			synced: true,
			source: 'user',
		}));
	}

    return (
        <div className="pattern-manager__pattern-browser">

            {isLoading && <p>{__('Loading patterns...', 'pattern-manager')}</p>}

            {error && <p style={{ color: 'red' }}>{error}</p>}

            {!isLoading && !error && (
                <>
                    <PatternBrowserPanel
                        patterns={patterns}
                        editorSettings={editorSettings}
                        onFilterChange={handleFilterChange}
						onCreatePattern={handleCreatePattern}
                    />
					{filteredPatterns?.length > 0 && (
					<PatternGrid
                        onPatternClick={onPatternClick}
                        patterns={filteredPatterns}
                        editorSettings={editorSettings}
                    />
					)}
					{filteredPatterns?.length === 0 && (
						<div>
							<p>{__('No patterns found', 'pattern-manager')}</p>
						</div>

					)}

					{!filteredPatterns && (
						<div>
							<p>{__('Welcome to the Pattern Manager.  Start searching to find a pattern to edit or create a new one.', 'pattern-manager')}</p>
						</div>
					)}
                </>
            )}
        </div>
    );
};

export default PatternBrowser;
