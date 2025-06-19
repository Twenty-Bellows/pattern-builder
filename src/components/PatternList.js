import { useSelect, useDispatch } from '@wordpress/data';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { parse, createBlock, serialize } from '@wordpress/blocks';
import PatternPreview from './PatternPreview';
import { store as blockEditorStore } from '@wordpress/block-editor';


export const PatternList = ({ patterns }) => {
	const { insertBlocks } = useDispatch('core/block-editor');

	const { onNavigateToEntityRecord } = useSelect(
		(select) => {
			const { getSettings } = select(blockEditorStore);
			return {
				onNavigateToEntityRecord: getSettings().onNavigateToEntityRecord,
			};
		},
		[]
	);

	const handlePatternClick = (pattern) => {
		if (pattern.synced && pattern.id) {
			const blockReference = createBlock('core/block', {
				ref: pattern.id
			});
			insertBlocks(blockReference);
		} else {
			const blocks = parse(pattern.content);
			// Give the first block the metadata name
			if (blocks.length > 0) {
				blocks[0].attributes.metadata = {
					name: pattern.title,
				};
			}
			insertBlocks(blocks);
		}
	};

	const handlePatternEditClick = (pattern) => {
		onNavigateToEntityRecord({
			postId: pattern.id,
			postType: 'wp_block'
		});
	}

	const handleDragStart = (event, pattern) => {
		// Set the drag data in the format WordPress expects
		event.dataTransfer.effectAllowed = 'copy';
		event.dataTransfer.setData('wp-blocks', pattern.dragData);
		event.dataTransfer.setData('text/html', serialize(pattern.blocks));

		// Add drag image styling
		const dragImage = event.target.cloneNode(true);
		dragImage.style.width = '300px';
		dragImage.style.opacity = '0.8';
		document.body.appendChild(dragImage);
		event.dataTransfer.setDragImage(dragImage, 0, 0);
		setTimeout(() => document.body.removeChild(dragImage), 0);
	};

	const renderPattern = (pattern) => {
		return (
			<div
				key={pattern.id || pattern.name}
				draggable={true}
				onDragStart={(e) => handleDragStart(e, pattern)}
			>
					<PatternPreview pattern={pattern} onClick={handlePatternClick} onEditClick={handlePatternEditClick} />

			</div>
		);
	};

	return (
		<VStack spacing={4}>
			{patterns.map(pattern => renderPattern(pattern))}
		</VStack>
	);
};
