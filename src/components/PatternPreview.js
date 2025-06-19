import { __ } from '@wordpress/i18n';
import { BlockPreview } from '@wordpress/block-editor';
import { parse } from '@wordpress/blocks';
import { Composite } from '@wordpress/components';
import {
	Button,
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	__experimentalText as Text,
	__experimentalHeading as Heading,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import './PatternPreview.scss';

function PatternPreviewPlaceholder() {
	return (
		<div className="pattern-builder__preview-grid-item pattern-builder__preview-grid-item-preview" />
	);
}

const PatternPreview = ({ pattern, onClick, onEditClick }) => {

	const { title, content, description } = pattern;
	const blocks = parse(content);

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
export default PatternPreview;
