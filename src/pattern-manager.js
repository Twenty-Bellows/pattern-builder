import { __, _x } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { dispatch, useSelect } from '@wordpress/data';

import { PatternBrowser } from './components/PatternBrowser';
import PatternEditor from './components/PatternEditor';

import './store';

import './pattern-manager.scss';

const PatternManager = () => {
    // Initialize the data store when the component mounts
    useEffect(() => {
        dispatch('pattern-manager').fetchEditorConfiguration();
        dispatch('pattern-manager').fetchAllPatterns();
    }, []);

    // Get the active pattern from the store
	const selectedPattern = useSelect((select) =>
		select('pattern-manager').getActivePattern()
	);

    return (
        <div className="pattern-manager__container">
            {selectedPattern ? (
                <PatternEditor
                    pattern={selectedPattern}
                    onClose={() => dispatch('pattern-manager').setActivePattern(null)}
                />
            ) : (
                <PatternBrowser
                    onPatternClick={(pattern) =>
                        dispatch('pattern-manager').setActivePattern(pattern)
                    }
                />
            )}
        </div>
    );
};

export default PatternManager;
