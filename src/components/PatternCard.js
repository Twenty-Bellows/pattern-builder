/**
 * A browse-grid pattern card: the site's own render of the pattern, framed at
 * the grid's design width and scaled into a fixed square tile — centered when
 * it is shorter than the tile and cropped when it is taller — the Site
 * Editor's pattern grid, and the same tile the cloud grid uses. Nothing here
 * measures anything: the tile and the scale are fixed in CSS, and the tile
 * document centers its own content. Clicking selects the pattern; the card
 * carries no actions.
 *
 * The frame runs no scripts (the tile document carries none either): it is a
 * picture of the pattern, from the same origin so the login cookie reaches it.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.pattern    The pattern.
 * @param {string}   props.tileUrl    Where the site draws its tile.
 * @param {boolean}  props.isSelected Whether the card is selected.
 * @param {Function} props.onSelect   Called with the pattern on click.
 */
export const PatternCard = ( { pattern, tileUrl, isSelected, onSelect } ) => (
	<button
		type="button"
		className={
			'pattern-builder-card' + ( isSelected ? ' is-selected' : '' )
		}
		aria-pressed={ isSelected }
		onClick={ () => onSelect( pattern ) }
	>
		<span className="pattern-builder-card__preview">
			<iframe
				title={ pattern.title }
				src={ tileUrl }
				loading="lazy"
				scrolling="no"
				tabIndex={ -1 }
				sandbox="allow-same-origin"
			/>
		</span>
		<span className="pattern-builder-card__title">
			<span>{ pattern.title }</span>
		</span>
	</button>
);
