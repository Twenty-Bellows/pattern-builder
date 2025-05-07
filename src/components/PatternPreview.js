import { BlockPreview } from '@wordpress/block-editor';
import { parse } from '@wordpress/blocks';
import { Composite } from '@wordpress/components';

const PatternPreview = ({ pattern, onClick }) => {

	const { title, content } = pattern;
	const blocks = parse(content);

	return (
		<Composite
			onClick={ () => {
				console.log( 'Pattern clicked:', pattern );
				if ( onClick ) {
					onClick( pattern );
				}
			} }
		>
			<p>{title}</p>
			<BlockPreview
				blocks={ blocks }
				viewportWidth={ 400 }
				minHeight={ 200 }
			/>
		</Composite>
	);
}
export default PatternPreview;
