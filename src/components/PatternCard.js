import { BlockPreview } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';

/**
 * A browse-grid pattern card: a uniform 1:1 preview with the title beneath.
 * Clicking selects the pattern (details live in the sidebar) — the card
 * itself carries no actions.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.pattern    The pattern.
 * @param {boolean}  props.isSelected Whether the card is selected.
 * @param {Function} props.onSelect   Called with the pattern on click.
 */
export const PatternCard = ( { pattern, isSelected, onSelect } ) => {
	const blocks = pattern.getBlocks();

	const badges = [
		pattern.source === 'theme'
			? __( 'Theme', 'pattern-builder' )
			: __( 'User', 'pattern-builder' ),
	];

	if ( ! pattern.synced ) {
		badges.push( __( 'Not synced', 'pattern-builder' ) );
	}

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
			<span className="pattern-builder-card__footer">
				<Text truncate weight={ 500 }>
					{ pattern.title }
				</Text>
				<Text variant="muted" size="11px">
					{ badges.join( ' · ' ) }
				</Text>
			</span>
		</button>
	);
};
