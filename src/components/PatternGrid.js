import { __, _x } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { BlockEditorProvider } from '@wordpress/block-editor';
import { getAllPatterns, getEditorSettings } from '../resolvers';
import PatternPreview from '../components/PatternPreview';

export const PatternGrid = ({onPatternClick}) => {

	const [patterns, setPatterns] = useState([]);
	const [ editorSettings, setEditorSettings ] = useState( {} );
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
		getEditorSettings()
			.then((data) => {
				setEditorSettings(data);
			});
	}, []);


	return (
		<BlockEditorProvider
			settings={{
				...editorSettings,
				isPreviewMode: true,
				focusMode: false,
			}}>
		<div className="pattern-manager_preview-grid">

			{isLoading && <p>{__('Loading patterns...', 'pattern-manager')}</p>}

			{error && <p style={{ color: 'red' }}>{error}</p>}

			{patterns.map((pattern, index) => (
				<div className="pattern-manager_preview-grid-item" key={index}>
					<PatternPreview onClick={onPatternClick} pattern={pattern} />
				</div>
			))}

		</div>
		</BlockEditorProvider>
	);
}

export default PatternGrid;
