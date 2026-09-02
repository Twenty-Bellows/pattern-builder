import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';
import { arrowLeft } from '@wordpress/icons';

import { CloudCard } from './CloudBrowser';

/**
 * One community collection opened: a header with its title, owner,
 * description and count, "Save collection to this site", and then its
 * patterns as the grid the rest of the browser uses.
 *
 * @param {Object}   props                  Component props.
 * @param {Object}   props.collection       The collection with its patterns, or null while loading.
 * @param {Function} props.onBack           Returns to the landing.
 * @param {Function} props.onSaveCollection Starts the whole-collection save.
 * @param {Object}   props.selected         The selected pattern.
 * @param {Function} props.onSelect         Selects a pattern.
 * @param {boolean}  props.busy             Whether a save is in flight.
 */
export function CollectionView( {
	collection,
	onBack,
	onSaveCollection,
	selected,
	onSelect,
	busy,
} ) {
	if ( ! collection ) {
		return <Spinner />;
	}

	const patterns = collection.patterns || [];
	const installed = patterns.filter(
		( pattern ) => pattern.installed
	).length;

	return (
		<div className="pattern-builder-collection-view">
			<Button
				variant="link"
				icon={ arrowLeft }
				onClick={ onBack }
				className="pattern-builder-collection-view__back"
			>
				{ __( 'All collections', 'pattern-builder' ) }
			</Button>

			<div className="pattern-builder-collection-view__header">
				<div className="pattern-builder-collection-view__text">
					<Heading level={ 2 } size={ 20 }>
						{ collection.title }
						{ collection.visibility === 'premium' && (
							<span className="pattern-builder-cloud__badge">
								{ __( 'Premium', 'pattern-builder' ) }
							</span>
						) }
					</Heading>
					<Text variant="muted">
						{ sprintf(
							/* translators: 1: owner display name, 2: pattern count. */
							_n(
								'By %1$s · %2$d pattern',
								'By %1$s · %2$d patterns',
								patterns.length,
								'pattern-builder'
							),
							collection.ownerName || '',
							patterns.length
						) }
						{ installed > 0 &&
							' · ' +
								sprintf(
									/* translators: %d: how many of the patterns are installed here. */
									__(
										'%d installed on this site',
										'pattern-builder'
									),
									installed
								) }
					</Text>
					{ collection.description && (
						<p className="pattern-builder-collection-view__description">
							{ collection.description }
						</p>
					) }
				</div>
				<HStack
					alignment="right"
					className="pattern-builder-collection-view__actions"
				>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy || ! patterns.length }
						onClick={ onSaveCollection }
					>
						{ __(
							'Save collection to this site',
							'pattern-builder'
						) }
					</Button>
				</HStack>
			</div>

			{ patterns.length === 0 && (
				<p>
					{ __(
						'Nothing in this collection yet.',
						'pattern-builder'
					) }
				</p>
			) }
			<div className="pattern-builder-cloud__grid">
				{ patterns.map( ( pattern ) => (
					<CloudCard
						key={ pattern.id }
						pattern={ pattern }
						isSelected={ selected?.id === pattern.id }
						onSelect={ ( picked ) =>
							onSelect(
								selected?.id === picked.id ? null : picked
							)
						}
					/>
				) ) }
			</div>
		</div>
	);
}
