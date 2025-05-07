import { __, _x } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import PatternGrid from './components/PatternGrid';
import PatternPreview from './components/PatternPreview';

import './pattern-manager.scss';

const PatternManager = () => {

	const [selectedPattern, setSelectedPattern] = useState(null);

	return (
		<div className="pattern-manager_container">

		{ selectedPattern ? (
			<PatternPreview pattern={selectedPattern} />
		) : (
			<PatternGrid onPatternClick={setSelectedPattern} />
		) }

		</div>
	);
}

export default PatternManager;
