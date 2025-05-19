import { __, _x } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { dispatch, useSelect, useDispatch } from '@wordpress/data';

import { PatternBrowser } from './components/PatternBrowser';
import PatternEditor from './components/PatternEditor';
import { SnackbarList } from '@wordpress/components';

import './utils/store';

import './PatternManager.scss';

const PatternManager = () => {

    const notices = useSelect((select) => select('core/notices').getNotices());
    const { removeNotice } = useDispatch('core/notices');

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
			<SnackbarList
				notices={notices}
				onRemove={removeNotice}
			/>
		</div>
    );
};

export default PatternManager;
