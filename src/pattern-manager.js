import { __, _x } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import PatternGrid from './components/PatternGrid';
import PatternEditor from './components/PatternEditor';

import './pattern-manager.scss';

const PatternManager = () => {

	const [selectedPattern, setSelectedPattern] = useState(null);

	return (
		<div className="pattern-manager_container">

		{ selectedPattern ? (
			<PatternEditor
				pattern={selectedPattern}
				onClose={ () => setSelectedPattern(null) }
			/>
		) : (
			<PatternGrid onPatternClick={setSelectedPattern} />
		) }

		</div>
	);
}

export default PatternManager;
