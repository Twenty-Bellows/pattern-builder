import { useSelect, useDispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { parse, createBlock, serialize } from '@wordpress/blocks';

import { PatternPreview } from './PatternPreview';
import { navigateToPattern } from '../utils/patternNavigation';

/**
 * The blocks an inserted copy of a pattern consists of.
 *
 * A synced pattern is inserted as a reference to itself — a `core/pattern`
 * block naming it — so the copy keeps following the pattern file. Anything
 * else is inserted as a copy of its blocks.
 *
 * @param {Object} pattern The pattern.
 * @return {Array} Blocks to insert.
 */
function getInsertionBlocks( pattern ) {
	if ( pattern.synced && pattern.name ) {
		return [ createBlock( 'core/pattern', { slug: pattern.name } ) ];
	}

	const blocks = parse( pattern.content );

	// Give the first block the metadata name.
	if ( blocks.length > 0 ) {
		blocks[ 0 ].attributes.metadata = {
			...( blocks[ 0 ].attributes.metadata || {} ),
			name: pattern.title,
		};
	}

	return blocks;
}

export const PatternList = ( { patterns, onEdit } ) => {
	const { insertBlocks } = useDispatch( 'core/block-editor' );

	const { onNavigateToEntityRecord } = useSelect( ( select ) => {
		const { getSettings } = select( blockEditorStore );
		return {
			onNavigateToEntityRecord: getSettings().onNavigateToEntityRecord,
		};
	}, [] );

	const handlePatternClick = ( pattern ) => {
		insertBlocks( getInsertionBlocks( pattern ) );
	};

	const handlePatternEditClick = ( pattern ) => {
		if ( onEdit ) {
			onEdit( pattern );
			return;
		}

		navigateToPattern( pattern, onNavigateToEntityRecord );
	};

	const handleDragStart = ( event, pattern ) => {
		// The editor parses blocks straight from dropped HTML.
		event.dataTransfer.effectAllowed = 'copy';
		event.dataTransfer.setData(
			'text/html',
			serialize( getInsertionBlocks( pattern ) )
		);

		// Add drag image styling.
		const dragImage = event.target.cloneNode( true );
		dragImage.style.width = '300px';
		dragImage.style.opacity = '0.8';
		document.body.appendChild( dragImage );
		event.dataTransfer.setDragImage( dragImage, 0, 0 );
		setTimeout( () => document.body.removeChild( dragImage ), 0 );
	};

	const renderPattern = ( pattern ) => {
		return (
			<div
				key={ pattern.id || pattern.name }
				draggable={ true }
				onDragStart={ ( e ) => handleDragStart( e, pattern ) }
			>
				<PatternPreview
					pattern={ pattern }
					onClick={ handlePatternClick }
					onEditClick={ handlePatternEditClick }
				/>
			</div>
		);
	};

	return (
		<VStack spacing={ 4 }>
			{ patterns.map( ( pattern ) => renderPattern( pattern ) ) }
		</VStack>
	);
};
