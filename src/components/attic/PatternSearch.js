import { __ } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch, dispatch } from '@wordpress/data';
import { createBlock, parse } from '@wordpress/blocks';
import PatternList from './PatternList';

const PatternSearch = () => {

	const patterns = useSelect((select) => select('pattern-builder').getAllPatterns(), []);

	const [searchTerm, setSearchTerm] = useState('');
	const [filteredPatterns, setFilteredPatterns] = useState(patterns);

	const { insertBlocks } = useDispatch('core/block-editor');

	// dispatch fetch all patterns if not loaded
	useEffect(() => {
		if (!patterns || patterns.length === 0) {
			dispatch('pattern-builder').fetchAllPatterns();
		}
	}, [patterns]);

	useEffect(() => {

		if (!searchTerm) {
			setFilteredPatterns(patterns);
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
			})

			setFilteredPatterns(filtered);
		}
	}, [searchTerm, patterns]);

	const onClickPattern = (pattern) => {
		// Handle synced patterns by creating a core/block reference
		if (pattern.synced && pattern.id) {
			const blockReference = createBlock('core/block', {
				ref: pattern.id
			});
			insertBlocks(blockReference);
		} else {
			const blocks = parse(pattern.content);
			// give the first block the metadata name
			blocks[0].attributes.metadata = {
				name: pattern.title,
			};

			insertBlocks(blocks);
		}
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
				<PatternList
					patterns={filteredPatterns}
				/>
			</div>


		</>
	);
};

export default PatternSearch;
