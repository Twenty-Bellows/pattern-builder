import { BlockPreview } from '@wordpress/block-editor';
import { parse } from '@wordpress/blocks';
import { Composite } from '@wordpress/components';

function PatternPreviewPlaceholder() {
	return (
		<div className="pattern-builder__preview-grid-item pattern-builder__preview-grid-item-preview" />
	);
}

const PatternPreview = ({ pattern, onClick }) => {

	const { title, content } = pattern;
	const blocks = parse(content);

	return (
		<Composite
			onClick={ () => {
				if ( onClick ) {
					onClick( pattern );
				}
			} }
		>
			<div className="pattern-builder__preview-grid-item">
			<BlockPreview.Async
				placeholder={ <PatternPreviewPlaceholder /> }
			>
				<BlockPreview
					blocks={ blocks }
					viewportWidth={ 800 }
				/>
			</BlockPreview.Async>
			</div>

			<p className='pattern-builder__preview-grid-item-title'>{title}</p>
		</Composite>
	);
}
export default PatternPreview;
