import { __ } from '@wordpress/i18n';
import { TextControl, Card, CardBody } from '@wordpress/components';
import { __experimentalBlockPatternsList as BlockPatternsList } from '@wordpress/block-editor';
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useAsyncList } from '@wordpress/compose';

const PatternSearch = () => {

	const [searchTerm, setSearchTerm] = useState('');
	const [filteredPatterns, setFilteredPatterns] = useState([]);

	const patterns = useSelect((select) => select('pattern-builder').getAllPatterns(), []);

	useEffect(() => {

		if (!searchTerm) {
			setFilteredPatterns([]);
		}

		else {
			const lowerCaseSearchTerm = searchTerm.toLowerCase();
			const filtered = patterns.filter((pattern) => {
				return (
					// search pattern title
					pattern.title?.toLowerCase().includes(lowerCaseSearchTerm)

					// search pattern categories
					|| (pattern.categories && pattern.categories.some((category) => category.toLowerCase().includes(lowerCaseSearchTerm)))

					// search pattern keywords
					|| (pattern.keywords && pattern.keywords.some((keyword) => keyword.toLowerCase().includes(lowerCaseSearchTerm)))
				);
			});

			setFilteredPatterns(filtered);
		}
	}, [searchTerm]);

	const onClickPattern = (pattern) => {
		console.log('Pattern clicked:', pattern);
	};

	return (
		<>
			<TextControl
				label="Search Patterns"
				value={searchTerm}
				onChange={setSearchTerm}
				placeholder="Type to search..."
			/>

			{filteredPatterns.length === 0 && searchTerm && (
				<div className="pattern-builder__no-results">
					<p>{__('No patterns found.', 'pattern-builder')}</p>
				</div>
			)}

			{filteredPatterns.length === 0 && !searchTerm && (
				<div className="pattern-builder__no-results">
					<p>{__('Start typing to search for patterns with matching categories, keywords or titles.', 'pattern-builder')}</p>
				</div>
			)}

			<div className='pattern-builder__pattern-search-results'>
				<BlockPatternsList
					isDraggable
					blockPatterns={filteredPatterns}
					shownPatterns={useAsyncList(filteredPatterns)}
					onClickPattern={onClickPattern}
					label={__('Pattern Search Results', 'pattern-builder')}
					showTitlesAsTooltip={false}
				/>
			</div>


		</>
	);
};

export default PatternSearch;
