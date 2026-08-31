import { BlockPreview } from '@wordpress/block-editor';

/**
 * A browse-grid pattern card: a contained preview with the title beneath,
 * the same shell the cloud grid uses. Clicking selects the pattern; the
 * card itself carries no actions.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.pattern    The pattern.
 * @param {boolean}  props.isSelected Whether the card is selected.
 * @param {Function} props.onSelect   Called with the pattern on click.
 */
export const PatternCard = ( { pattern, isSelected, onSelect } ) => {
	const blocks = pattern.getBlocks();

	return (
		<button
			type="button"
			className={
				'pattern-builder-card' + ( isSelected ? ' is-selected' : '' )
			}
			aria-pressed={ isSelected }
			onClick={ () => onSelect( pattern ) }
		>
			<span className="pattern-builder-card__preview">
				<BlockPreview.Async
					placeholder={
						<span className="pattern-builder-card__placeholder" />
					}
				>
					<BlockPreview
						blocks={ blocks }
						viewportWidth={ pattern.viewportWidth || 1200 }
					/>
				</BlockPreview.Async>
			</span>
			<span className="pattern-builder-card__title">
				<span>{ pattern.title }</span>
			</span>
		</button>
	);
};
