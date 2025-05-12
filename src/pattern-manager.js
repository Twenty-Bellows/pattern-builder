import { __, _x } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import { PatternBrowser } from './components/PatternBrowser';
import PatternEditor from './components/PatternEditor';

import './pattern-manager.scss';

const PatternManager = () => {

	const [selectedPattern, setSelectedPattern] = useState(null);

	return (
		<div className="pattern-manager__container">

		{ selectedPattern ? (
			<PatternEditor
				pattern={selectedPattern}
				onClose={ () => setSelectedPattern(null) }
			/>
		) : (
			<PatternBrowser onPatternClick={setSelectedPattern} />
		) }

		</div>
	);
}

export default PatternManager;
