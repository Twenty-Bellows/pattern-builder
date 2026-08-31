import { BlockPreview } from '@wordpress/block-editor';
import { useEffect, useRef, useState } from '@wordpress/element';

// Cloud previews render at this width whatever the pattern declares, so
// local ones do too: the same pattern then lays out — and wraps — the same
// in every grid.
const PREVIEW_WIDTH = 1400;

/**
 * A browse-grid pattern card: the whole pattern scaled to fit the card,
 * centered, with the title beneath — the same shell and fit the cloud grid
 * uses. Clicking selects the pattern; the card carries no actions.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.pattern    The pattern.
 * @param {boolean}  props.isSelected Whether the card is selected.
 * @param {Function} props.onSelect   Called with the pattern on click.
 */
export const PatternCard = ( { pattern, isSelected, onSelect } ) => {
	const blocks = pattern.getBlocks();

	// BlockPreview scales content to the box width; anything taller than the
	// box would be cropped, so measure the laid-out height and scale again to
	// contain it (offsetHeight ignores the transform, so it stays stable).
	const boxRef = useRef( null );
	const fitRef = useRef( null );
	const [ box, setBox ] = useState( { width: 0, height: 0 } );
	const [ contentHeight, setContentHeight ] = useState( 0 );

	useEffect( () => {
		const boxNode = boxRef.current;
		const fitNode = fitRef.current;
		if (
			! boxNode ||
			! fitNode ||
			typeof window.ResizeObserver === 'undefined'
		) {
			return;
		}

		const observer = new window.ResizeObserver( () => {
			const rect = boxNode.getBoundingClientRect();
			setBox( { width: rect.width, height: rect.height } );
			setContentHeight( fitNode.offsetHeight );
		} );

		observer.observe( boxNode );
		observer.observe( fitNode );
		return () => observer.disconnect();
	}, [] );

	const scale =
		box.height && contentHeight
			? Math.min( 1, box.height / contentHeight )
			: 1;
	const offsetX = ( box.width - box.width * scale ) / 2;
	const offsetY = Math.max( 0, ( box.height - contentHeight * scale ) / 2 );

	return (
		<button
			type="button"
			className={
				'pattern-builder-card' + ( isSelected ? ' is-selected' : '' )
			}
			aria-pressed={ isSelected }
			onClick={ () => onSelect( pattern ) }
		>
			<span className="pattern-builder-card__preview" ref={ boxRef }>
				<span
					className="pattern-builder-card__fit"
					ref={ fitRef }
					style={ {
						transform: `translate(${ offsetX }px, ${ offsetY }px) scale(${ scale })`,
					} }
				>
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
			</span>
			<span className="pattern-builder-card__title">
				<span>{ pattern.title }</span>
			</span>
		</button>
	);
};
