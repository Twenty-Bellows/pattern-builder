import { __, _x } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

import { getAllPatterns } from './resolvers';
import PatternPreview from './components/PatternPreview';

import './pattern-manager.scss';

const PatternManager = () => {

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
		<div className="pattern-manager_container">

			{isLoading && <p>{__('Loading patterns...', 'pattern-manager')}</p>}

			{error && <p style={{ color: 'red' }}>{error}</p>}

			<div className="pattern-manager_preview-grid">
				{patterns.map((pattern, index) => (
					<div className="pattern-manager_preview-grid-item" key={index}>
						<PatternPreview pattern={pattern} />
					</div>
				))}
			</div>

		</div>
	);
}

export default PatternManager;
