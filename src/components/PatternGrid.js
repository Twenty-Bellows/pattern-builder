import { __, _x } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

import { getAllPatterns } from '../resolvers';
import PatternPreview from '../components/PatternPreview';

export const PatternGrid = ({onPatternClick}) => {

	const [patterns, setPatterns] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState(null);

	useEffect(() => {
		getAllPatterns()
			.then((patterns) => {
				setPatterns(patterns);
				setIsLoading(false);
			})
			.catch((err) => {
				setError(err.message || 'Something went wrong.');
				setIsLoading(false);
			});
	}, []);


	return (
		<div className="pattern-manager_preview-grid">

			{isLoading && <p>{__('Loading patterns...', 'pattern-manager')}</p>}

			{error && <p style={{ color: 'red' }}>{error}</p>}

			{patterns.map((pattern, index) => (
				<div className="pattern-manager_preview-grid-item" key={index}>
					<PatternPreview onClick={onPatternClick} pattern={pattern} />
				</div>
			))}

		</div>
	);
}

export default PatternGrid;
