import { __, _x } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { getAllPatterns } from './resolvers';

const PatternManager = () => {
	const [patterns, setPatterns] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState(null);

	useEffect(() => {
		getAllPatterns()
			.then((data) => {
				setPatterns(data);
				setIsLoading(false);
			})
			.catch((err) => {
				setError(err.message || 'Something went wrong.');
				setIsLoading(false);
			});
	}, []);

	return (
		<div className="pattern-manager-modal">
			<h2>{_x('Hello Pattern Manager', 'UI String', 'pattern-manager')}</h2>
			<p>{_x('This is the Pattern Manager modal.', 'UI String', 'pattern-manager')}</p>

			{isLoading && <p>{__('Loading patterns...', 'pattern-manager')}</p>}
			{error && <p style={{ color: 'red' }}>{error}</p>}

			<ul>
				{patterns.map((pattern, index) => (
					<li key={index}>
						{pattern.title?.rendered || pattern.title || pattern.name}
					</li>
				))}
			</ul>
		</div>
	);
}

export default PatternManager;
