import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * A collection as a tile: a collage of up to four of its previews rendered
 * the way pattern tiles are — the service's preview document at the design
 * width, scaled by a constant the stylesheet knows — or its cover when it
 * has one; then the title, the owner, the count and a Premium badge, and
 * how many of its patterns this site already has.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.collection The collection summary.
 * @param {number}   props.installed  How many of its patterns are installed here.
 * @param {Function} props.onOpen     Called with the collection.
 */
export function CollectionTile( { collection, installed = 0, onOpen } ) {
	const previews = ( collection.previews || [] ).slice( 0, 4 );
	const cells = previews.length === 3 ? previews.slice( 0, 2 ) : previews;

	return (
		<button
			type="button"
			className="pattern-builder-collection-tile"
			onClick={ () => onOpen( collection ) }
		>
			<span
				className={
					'pattern-builder-collection-tile__art' +
					( collection.cover ? ' has-cover' : '' ) +
					( cells.length === 1 ? ' is-single' : '' )
				}
			>
				{ collection.cover && (
					<img src={ collection.cover } alt="" loading="lazy" />
				) }
				{ ! collection.cover &&
					cells.map( ( url ) => (
						<span
							key={ url }
							className="pattern-builder-collection-tile__cell"
						>
							<iframe
								title={ collection.title }
								src={ url }
								loading="lazy"
								scrolling="no"
								tabIndex={ -1 }
							/>
						</span>
					) ) }
				{ ! collection.cover && cells.length === 0 && (
					<span className="pattern-builder-collection-tile__empty">
						{ __( 'Nothing shared yet', 'pattern-builder' ) }
					</span>
				) }
			</span>
			<span className="pattern-builder-collection-tile__title">
				<span>{ collection.title }</span>
				{ collection.visibility === 'premium' && (
					<span className="pattern-builder-cloud__badge">
						{ __( 'Premium', 'pattern-builder' ) }
					</span>
				) }
			</span>
			<span className="pattern-builder-collection-tile__meta">
				{ sprintf(
					/* translators: 1: owner display name, 2: pattern count. */
					_n(
						'%1$s · %2$d pattern',
						'%1$s · %2$d patterns',
						collection.count || 0,
						'pattern-builder'
					),
					collection.ownerName || '',
					collection.count || 0
				) }
				{ installed > 0 && (
					<>
						{ ' · ' }
						{ sprintf(
							/* translators: 1: installed count, 2: total count. */
							__( '%1$d of %2$d installed', 'pattern-builder' ),
							installed,
							collection.count || 0
						) }
					</>
				) }
			</span>
		</button>
	);
}
