import { __ } from '@wordpress/i18n';
import { BlockPreview } from '@wordpress/block-editor';
import {
	Button,
	Card,
	CardHeader,
	CardBody,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

import './PatternPreview.scss';

function PatternPreviewPlaceholder() {
	return (
		<div className="pattern-builder__preview-grid-item pattern-builder__preview-grid-item-preview" />
	);
}

export const PatternPreview = ({ pattern, onClick, onEditClick }) => {

	const { title, description } = pattern;
	const blocks = pattern.getBlocks();

	return (

		<Card className="pattern-builder__pattern-preview" onClick={() => {
				if (onClick) {
					onClick(pattern);
				}
			}}
		>
			<CardHeader className="pattern-builder__pattern-preview__header">
				<Text>{title}</Text>
				<Button variant='primary' onClick={(event)=>{
					event.stopPropagation(); // Prevent the card click event
					if (onEditClick) {
						onEditClick(pattern);
					}
				}}>{__('Edit', 'pattern-builder')}</Button>
			</CardHeader>
			<CardBody>
				<VStack>
					<BlockPreview.Async
						placeholder={<PatternPreviewPlaceholder />}
					>
						<BlockPreview
							blocks={blocks}
							viewportWidth={800}
						/>
					</BlockPreview.Async>
					<Text variant="muted" size="11px">{description}</Text>
				</VStack>
			</CardBody>
		</Card>
	);
}
