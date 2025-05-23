import { __, _x } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { dispatch, useSelect, useDispatch } from '@wordpress/data';

import { PatternBrowser } from './components/PatternBrowser';
import PatternEditor from './components/PatternEditor';
import { SnackbarList } from '@wordpress/components';

import './utils/store';

import './PatternBuilder.scss';

const PatternBuilder = () => {

    const notices = useSelect((select) => select('core/notices').getNotices());
    const { removeNotice } = useDispatch('core/notices');

    useEffect(() => {
        dispatch('pattern-builder').fetchEditorConfiguration();
        dispatch('pattern-builder').fetchAllPatterns();
    }, []);

    // Get the active pattern from the store
	const selectedPattern = useSelect((select) =>
		select('pattern-builder').getActivePattern()
	);

    return (
        <div className="pattern-builder__container">
            {selectedPattern ? (
                <PatternEditor
                    pattern={selectedPattern}
                    onClose={() => dispatch('pattern-builder').setActivePattern(null)}
                />
            ) : (
                <PatternBrowser
                    onPatternClick={(pattern) =>
                        dispatch('pattern-builder').setActivePattern(pattern)
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

export default PatternBuilder;
