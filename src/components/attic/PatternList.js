import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { parse, createBlock, serialize } from '@wordpress/blocks';
import { useDispatch } from '@wordpress/data';

const PatternList = ({ patterns }) => {
	const { insertBlocks } = useDispatch('core/block-editor');

	// Create draggable data for each pattern
	const patternsWithDragData = useMemo(() => {
		return patterns.map(pattern => {
			let blocks;

			if (pattern.synced && pattern.id) {
				// For synced patterns, create a core/block reference
				blocks = [createBlock('core/block', { ref: pattern.id })];
			} else {
				// For unsynced patterns, parse the content
				blocks = parse(pattern.content || '');
			}

			const dragData = {
				type: 'block',
				blocks: blocks,
				srcClientIds: []
			};

			return {
				...pattern,
				blocks,
				dragData: JSON.stringify(dragData)
			};
		});
	}, [patterns]);

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
				className="pattern-list__item"
				draggable={true}
				onDragStart={(e) => handleDragStart(e, pattern)}
			>
				<Button
					className="pattern-list__item-button"
					onClick={() => handlePatternClick(pattern)}
				>
					<div className="pattern-list__item-preview">
						<div className="pattern-list__item-title">
							{pattern.title}
						</div>
						{pattern.description && (
							<div className="pattern-list__item-description">
								{pattern.description}
							</div>
						)}
						{pattern.synced && (
							<div className="pattern-list__item-badge">
								{__('Synced', 'pattern-builder')}
							</div>
						)}
					</div>
				</Button>
			</div>
		);
	};

	return (
		<div className="pattern-list">
			{patternsWithDragData.map(pattern => renderPattern(pattern))}
		</div>
	);
};

export default PatternList;
