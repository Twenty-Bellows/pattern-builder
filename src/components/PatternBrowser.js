import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { BlockEditorProvider } from '@wordpress/block-editor';
import { getAllPatterns, getEditorSettings } from '../resolvers';
import PatternPreview from './PatternPreview';
import { PatternBrowserPanel } from './PatternBrowserPanel';

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
                    <div className="pattern-manager__preview-grid-item" key={index}>
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
    const [filteredPatterns, setFilteredPatterns] = useState([]);
    const [editorSettings, setEditorSettings] = useState({});
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        getAllPatterns()
            .then((patterns) => {
                setPatterns(patterns);
                setFilteredPatterns(patterns); // Initialize filtered patterns
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
        const { source } = filters;

        const updatedFilteredPatterns = patterns
		.filter((pattern) => {
        	// Filter patterns based on the source
            if (source === 'all') return true; // Show all patterns
            return pattern.source === source; // Match the source
        })
		.filter((pattern) => {
			// Filter patterns based on the synced status
			if (filters.synced === 'all') return true; // Show all patterns
			return pattern.synced === (filters.synced === 'yes'); // Match the synced status
		})
        setFilteredPatterns(updatedFilteredPatterns);

    };

    return (
        <div className="pattern-manager__pattern-browser">

            {isLoading && <p>{__('Loading patterns...', 'pattern-manager')}</p>}

            {error && <p style={{ color: 'red' }}>{error}</p>}

            {!isLoading && !error && (
                <>
                    <PatternGrid
                        onPatternClick={onPatternClick}
                        patterns={filteredPatterns}
                        editorSettings={editorSettings}
                    />
                    <PatternBrowserPanel
                        patterns={patterns}
                        editorSettings={editorSettings}
                        onFilterChange={handleFilterChange}
                    />
                </>
            )}
        </div>
    );
};

export default PatternBrowser;
