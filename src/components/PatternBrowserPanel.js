import { __, _x } from '@wordpress/i18n';
import { useNavigator } from '@wordpress/components';
import { PatternList } from './PatternList';
import { useMemo } from 'react';

export const PatternBrowserPanel = ( { allPatterns } ) => {
	const navigator = useNavigator();
	const category = navigator.params.category || 'all';

	const patterns = useMemo( () => {
		return allPatterns.filter( ( pattern ) => {
			if ( category === 'all' ) {
				return true;
			}
			if ( category === 'uncategorized' ) {
				return ! pattern.categories.length && pattern.inserter;
			}
			if ( category === 'hidden' ) {
				return pattern.inserter === false;
			}
			return pattern.categories.includes( category );
		} );
	}, [ allPatterns, category ] );

	return (
		<>
			<p>{ __( 'Click or drag to add pattern.', 'pattern-builder' ) }</p>
			<PatternList patterns={ patterns } />
		</>
	);
};
