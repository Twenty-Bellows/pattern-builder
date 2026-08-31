import { BlockPreview } from '@wordpress/block-editor';

// Cloud previews render at this width whatever the pattern declares, so
// local ones do too: the same pattern then lays out — and wraps — the same
// in every grid.
const PREVIEW_WIDTH = 1400;

/**
 * A browse-grid pattern card: the pattern rendered at the grid's design
 * width and scaled into a fixed square tile, centered when it is shorter
 * than the tile and cropped when it is taller — the Site Editor's pattern
 * grid, and the same tile the cloud grid uses. Nothing here measures
 * anything: the tile and the scale are fixed in CSS. Clicking selects the
 * pattern; the card carries no actions.
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
						viewportWidth={ PREVIEW_WIDTH }
					/>
				</BlockPreview.Async>
			</span>
			<span className="pattern-builder-card__title">
				<span>{ pattern.title }</span>
			</span>
		</button>
	);
};
