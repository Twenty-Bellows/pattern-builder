import { __ } from '@wordpress/i18n';
import { TextControl, Card, CardBody } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';

import PatternPreview from './PatternPreview';

import { getAllPatterns } from '../resolvers';



const PatternSearch = () => {

	const [searchTerm, setSearchTerm] = useState('');
	const [patterns, setPatterns] = useState([]);
	const [filteredPatterns, setFilteredPatterns] = useState([]);

	useEffect(() => {
		getAllPatterns()
			.then((patterns) => {
				setPatterns(patterns);
			});
	}, []);

	useEffect(() => {

		if (!searchTerm) {
			setFilteredPatterns([]);
		}

		else {
			const lowerCaseSearchTerm = searchTerm.toLowerCase();
			const filtered = patterns.filter((pattern) => {
				return (
					// search pattern title
					pattern.title.toLowerCase().includes(lowerCaseSearchTerm) ||

					// search pattern categories
					(pattern.categories && pattern.categories.some((category) => category.name.toLowerCase().includes(lowerCaseSearchTerm)))
				);
			});
			setFilteredPatterns(filtered);
		}
	}, [searchTerm]);

	return (
		<>
			<TextControl
				label="Search Patterns"
				value={searchTerm}
				onChange={setSearchTerm}
				placeholder="Type to search..."
			/>

			{filteredPatterns.length === 0 && searchTerm && (
				<div className="pattern-manager__no-results">
					<p>{__('No patterns found.', 'pattern-manager')}</p>
				</div>
			)}

			{filteredPatterns.length === 0 && !searchTerm && (
				<div className="pattern-manager__no-results">
					<p>{__('Start typing to search for patterns with matching categories, keywords or titles.', 'pattern-manager')}</p>
				</div>
			)}

			<div className='pattern-manager__pattern-search-results'>
				{filteredPatterns.map((pattern, index) => (
					<Card key={index}>
						<CardBody>
							<PatternPreview pattern={pattern} />
							<p style={{fontSize:'10px'}}>{pattern.categories.map( (category) => {
								if (typeof category === 'string') {
									return category;
								}
								return category.name;
							}).join(', ') }</p>
						</CardBody>
					</Card>
				))}
			</div>


		</>
	);
};

export default PatternSearch;
