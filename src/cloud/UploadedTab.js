import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import { CloudCard, CloudDetails, useDownloadFlow } from './CloudBrowser';
import { CollectionPicker, createCollection } from './CollectionPicker';
import { isListed } from './collections';

const BASE = '/pattern-builder/v1/cloud';

/**
 * The rail entries the browser chrome shows for the Uploaded tab: the
 * account's collections, Personal first with its meter.
 *
 * @param {Array}  collections The account's collections.
 * @param {Object} personal    The /cloud/status personal meter, or undefined.
 * @return {Array} Rail descriptors: { slug, label, count }.
 */
export function railFor( collections, personal ) {
	return ( collections || [] ).map( ( item ) => {
		let count = String( item.count ?? '' );
		if ( item.personal && personal && personal.cap > 0 ) {
			count = sprintf(
				/* translators: 1: patterns in Personal, 2: the cap. */
				__( '%1$d of %2$d', 'pattern-builder' ),
				personal.count ?? item.count ?? 0,
				personal.cap
			);
		}
		return {
			slug: String( item.id ),
			label: item.personal
				? __( '🔒 Personal', 'pattern-builder' )
				: item.title,
			count,
		};
	} );
}

/**
 * The New collection dialog: a name and a description, and the visibility
 * only where the account may choose. A free account is told the
 * collection will be public, and why.
 *
 * @param {Object}   props            Component props.
 * @param {boolean}  props.canPrivate Whether the account may build in private.
 * @param {Function} props.onCreated  Called with the created collection.
 * @param {Function} props.onClose    Closes the dialog.
 * @param {Function} props.onGoPro    Opens the upgrade, or null.
 */
function NewCollectionModal( { canPrivate, onCreated, onClose, onGoPro } ) {
	const [ name, setName ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ visibility, setVisibility ] = useState(
		canPrivate ? 'private' : 'public'
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = ( event ) => {
		event.preventDefault();
		if ( busy || ! name.trim() ) {
			return;
		}
		setBusy( true );
		setError( '' );
		createCollection(
			name.trim(),
			description.trim(),
			canPrivate ? visibility : ''
		)
			.then( ( created ) => onCreated( created ) )
			.catch( ( err ) => {
				setBusy( false );
				setError(
					err.message ||
						__(
							'The collection could not be created.',
							'pattern-builder'
						)
				);
			} );
	};

	return (
		<Modal
			title={ __( 'New collection', 'pattern-builder' ) }
			onRequestClose={ onClose }
			className="pattern-builder-cloud__destination-modal"
		>
			<form onSubmit={ submit }>
				<VStack spacing={ 3 }>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Name', 'pattern-builder' ) }
						value={ name }
						onChange={ setName }
						required
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Description', 'pattern-builder' ) }
						value={ description }
						onChange={ setDescription }
						rows={ 3 }
					/>
					{ canPrivate ? (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Visibility', 'pattern-builder' ) }
							value={ visibility }
							onChange={ setVisibility }
							options={ [
								{
									value: 'private',
									label: __(
										'Private — only you',
										'pattern-builder'
									),
								},
								{
									value: 'public',
									label: __(
										'Public — listed in the community',
										'pattern-builder'
									),
								},
							] }
						/>
					) : (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'This collection will be public: on a free account, every collection other than Personal is listed in the community as soon as its patterns pass the checks. Building a collection in private is a Pattern Builder Pro feature — or keep the work local.',
								'pattern-builder'
							) }
							{ onGoPro && (
								<>
									{ ' ' }
									<Button variant="link" onClick={ onGoPro }>
										{ __( 'Go Pro', 'pattern-builder' ) }
									</Button>
								</>
							) }
						</Notice>
					) }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<HStack alignment="right" spacing={ 2 }>
						<Button
							variant="tertiary"
							onClick={ onClose }
							disabled={ busy }
						>
							{ __( 'Cancel', 'pattern-builder' ) }
						</Button>
						<Button
							variant="primary"
							type="submit"
							isBusy={ busy }
							disabled={ busy || ! name.trim() }
						>
							{ __( 'Create collection', 'pattern-builder' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
		</Modal>
	);
}

/**
 * The delete prompt: delete the collection's patterns, or move them to
 * Personal. A refused move (past the cap) shows the upgrade prompt and
 * leaves delete available.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.collection The collection.
 * @param {Function} props.onDeleted  Called once it is gone.
 * @param {Function} props.onClose    Closes the prompt.
 * @param {Function} props.onGoPro    Opens the upgrade, or null.
 */
function DeleteCollectionModal( { collection, onDeleted, onClose, onGoPro } ) {
	const [ busy, setBusy ] = useState( false );
	const [ refused, setRefused ] = useState( null );

	const run = ( patterns ) => {
		setBusy( true );
		setRefused( null );
		apiFetch( {
			path: `${ BASE }/library/collections/${ collection.id }?patterns=${ patterns }`,
			method: 'DELETE',
		} )
			.then( () => onDeleted() )
			.catch( ( error ) => {
				setBusy( false );
				setRefused( {
					message:
						error.message ||
						__( 'That did not work.', 'pattern-builder' ),
					upgrade: error.data?.upgrade_url,
				} );
			} );
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: collection title. */
				__( 'Delete “%s”?', 'pattern-builder' ),
				collection.title
			) }
			onRequestClose={ onClose }
			className="pattern-builder-cloud__destination-modal"
		>
			<VStack spacing={ 3 }>
				<p className="pattern-builder-cloud__meta">
					{ sprintf(
						/* translators: %d: pattern count. */
						_n(
							'It holds %d pattern. Delete it too, or move it to Personal?',
							'It holds %d patterns. Delete them too, or move them to Personal?',
							collection.count || 0,
							'pattern-builder'
						),
						collection.count || 0
					) }
				</p>
				{ refused && (
					<Notice status="warning" isDismissible={ false }>
						{ refused.message }
						{ refused.upgrade && onGoPro && (
							<>
								{ ' ' }
								<Button variant="link" onClick={ onGoPro }>
									{ __( 'Go Pro', 'pattern-builder' ) }
								</Button>
							</>
						) }
					</Notice>
				) }
				<HStack alignment="right" spacing={ 2 } wrap>
					<Button
						variant="tertiary"
						onClick={ onClose }
						disabled={ busy }
					>
						{ __( 'Cancel', 'pattern-builder' ) }
					</Button>
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () => run( 'move' ) }
					>
						{ __( 'Move patterns to Personal', 'pattern-builder' ) }
					</Button>
					<Button
						variant="primary"
						isDestructive
						isBusy={ busy }
						disabled={ busy }
						onClick={ () => run( 'delete' ) }
					>
						{ __( 'Delete patterns too', 'pattern-builder' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}

/**
 * The selected collection's header: rename, describe, visibility (as the
 * account may), delete. Personal offers only a description.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.collection The collection.
 * @param {boolean}  props.canPrivate Whether the account may make it private.
 * @param {Function} props.onChanged  Called with the updated collection.
 * @param {Function} props.onDelete   Opens the delete prompt.
 */
function CollectionHeader( { collection, canPrivate, onChanged, onDelete } ) {
	const [ editing, setEditing ] = useState( false );
	const [ name, setName ] = useState( collection.title );
	const [ description, setDescription ] = useState(
		collection.description || ''
	);
	const [ busy, setBusy ] = useState( false );
	const { createErrorNotice } = useDispatch( noticesStore );

	useEffect( () => {
		setName( collection.title );
		setDescription( collection.description || '' );
		setEditing( false );
	}, [ collection.id, collection.title, collection.description ] );

	const update = ( data ) => {
		setBusy( true );
		return apiFetch( {
			path: `${ BASE }/library/collections/${ collection.id }`,
			method: 'PUT',
			data,
		} )
			.then( ( updated ) => {
				setBusy( false );
				setEditing( false );
				onChanged( updated );
			} )
			.catch( ( error ) => {
				setBusy( false );
				createErrorNotice(
					error.message ||
						__( 'That did not work.', 'pattern-builder' ),
					{ type: 'snackbar' }
				);
			} );
	};

	const visibilityOptions = [
		{
			value: 'public',
			label: __( 'Public', 'pattern-builder' ),
		},
	];
	if ( canPrivate || collection.visibility === 'private' ) {
		visibilityOptions.unshift( {
			value: 'private',
			label: __( 'Private', 'pattern-builder' ),
		} );
	}
	if ( collection.visibility === 'premium' ) {
		visibilityOptions.push( {
			value: 'premium',
			label: __( 'Premium', 'pattern-builder' ),
		} );
	}

	return (
		<div className="pattern-builder-collection-view__header">
			<div className="pattern-builder-collection-view__text">
				{ editing ? (
					<VStack spacing={ 2 }>
						{ ! collection.personal && (
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Name', 'pattern-builder' ) }
								value={ name }
								onChange={ setName }
							/>
						) }
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Description', 'pattern-builder' ) }
							value={ description }
							onChange={ setDescription }
							rows={ 2 }
						/>
						<HStack alignment="left" spacing={ 2 }>
							<Button
								variant="primary"
								isBusy={ busy }
								disabled={ busy }
								onClick={ () =>
									update(
										collection.personal
											? { description }
											: { name, description }
									)
								}
							>
								{ __( 'Save', 'pattern-builder' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setEditing( false ) }
							>
								{ __( 'Cancel', 'pattern-builder' ) }
							</Button>
						</HStack>
					</VStack>
				) : (
					<>
						<Heading level={ 2 } size={ 20 }>
							{ collection.personal
								? __( 'Personal', 'pattern-builder' )
								: collection.title }
						</Heading>
						<Text variant="muted">
							{ collection.personal
								? __(
										'Your private collection. Always private; cannot be renamed or deleted.',
										'pattern-builder'
								  )
								: sprintf(
										/* translators: %d: pattern count. */
										_n(
											'%d pattern',
											'%d patterns',
											collection.count || 0,
											'pattern-builder'
										),
										collection.count || 0
								  ) }
						</Text>
						{ collection.description && (
							<p className="pattern-builder-collection-view__description">
								{ collection.description }
							</p>
						) }
					</>
				) }
			</div>
			{ ! editing && (
				<HStack
					alignment="right"
					spacing={ 2 }
					className="pattern-builder-collection-view__actions"
					wrap
				>
					{ ! collection.personal && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Visibility', 'pattern-builder' ) }
							hideLabelFromVision
							value={ collection.visibility }
							disabled={ busy }
							options={ visibilityOptions }
							onChange={ ( visibility ) =>
								update( { visibility } )
							}
						/>
					) }
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => setEditing( true ) }
					>
						{ collection.personal
							? __( 'Describe', 'pattern-builder' )
							: __( 'Rename or describe', 'pattern-builder' ) }
					</Button>
					{ ! collection.personal && (
						<Button
							variant="tertiary"
							isDestructive
							disabled={ busy }
							onClick={ onDelete }
						>
							{ __( 'Delete', 'pattern-builder' ) }
						</Button>
					) }
				</HStack>
			) }
		</div>
	);
}

/**
 * The Uploaded tab: the account's collections down the rail (Personal
 * first, with its meter), the selected collection's header and actions,
 * its patterns as the grid, and a details sidebar with Save, Move to
 * collection, and Delete from cloud.
 *
 * @param {Object}   props               Component props.
 * @param {Element}  props.chrome        The account bar and notices the shell renders.
 * @param {Object}   props.status        The /cloud/status payload.
 * @param {Function} props.refreshStatus Re-fetches the status (the Personal meter).
 * @param {string}   props.search        Search term, owned by the browser chrome.
 * @param {string}   props.collection    The rail selection: a collection id, or '' for all.
 * @param {Function} props.onCollections Reports the collections for the rail.
 * @param {Function} props.onDownloaded  Called after a pattern lands locally.
 * @param {Function} props.onEditLocal   Opens an installed local copy's editor.
 * @param {Function} props.onGoPro       Opens the upgrade, or null.
 */
export function UploadedTab( {
	chrome,
	status,
	refreshStatus,
	search,
	collection,
	onCollections,
	onDownloaded,
	onEditLocal,
	onGoPro,
} ) {
	const [ collections, setCollections ] = useState( null );
	const [ items, setItems ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ pages, setPages ] = useState( 1 );
	const [ selected, setSelected ] = useState( null );
	const [ creating, setCreating ] = useState( false );
	const [ deleting, setDeleting ] = useState( null );
	const [ moveTo, setMoveTo ] = useState( 0 );
	const [ moving, setMoving ] = useState( false );

	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const { busy, requestDownload, modals } = useDownloadFlow( {
		source: 'library',
		onDownloaded,
	} );

	const canPrivate = !! status?.entitlements?.can_create_private;
	const selectedId = Number( collection ) || 0;
	const current = ( collections || [] ).find(
		( item ) => item.id === selectedId
	);

	const loadCollections = useCallback( () => {
		return apiFetch( { path: `${ BASE }/library/collections` } )
			.then( ( data ) => {
				const list = Array.isArray( data ) ? data : [];
				setCollections( list );
				onCollections?.( railFor( list, status?.personal ) );
				return list;
			} )
			.catch( () => {
				setCollections( [] );
				onCollections?.( [] );
				return [];
			} );
	}, [ onCollections, status?.personal ] );

	useEffect( () => {
		loadCollections();
	}, [ loadCollections ] );

	const loadItems = useCallback( () => {
		setItems( null );
		const query = new URLSearchParams( { page: String( page ) } );
		if ( search ) {
			query.set( 'search', search );
		}
		if ( selectedId ) {
			query.set( 'collection', String( selectedId ) );
		}
		apiFetch( { path: `${ BASE }/library?${ query }` } )
			.then( ( data ) => {
				setItems( data.items || [] );
				setPages( data.pages || 1 );
			} )
			.catch( ( error ) => {
				setItems( [] );
				createErrorNotice(
					error.message ||
						__( 'Could not load patterns.', 'pattern-builder' ),
					{ type: 'snackbar' }
				);
			} );
	}, [ page, search, selectedId, createErrorNotice ] );

	useEffect( loadItems, [ loadItems ] );

	// A new search or rail selection restarts paging.
	useEffect( () => {
		setPage( 1 );
		setSelected( null );
	}, [ search, selectedId ] );

	// Everything that changes counts: reload the rail and the meter.
	const changed = () => {
		loadCollections();
		refreshStatus();
		loadItems();
	};

	const deleteCloudPattern = ( pattern ) => {
		const confirmed =
			// eslint-disable-next-line no-alert
			window.confirm(
				__(
					'Delete this pattern from your cloud library? Sites that downloaded it keep their copies.',
					'pattern-builder'
				)
			);
		if ( ! confirmed ) {
			return;
		}
		apiFetch( {
			path: `${ BASE }/library/${ pattern.id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setSelected( null );
				createSuccessNotice(
					__(
						'Pattern deleted from your cloud library.',
						'pattern-builder'
					),
					{ type: 'snackbar' }
				);
				changed();
			} )
			.catch( ( error ) => {
				createErrorNotice(
					error.message ||
						__(
							'Could not delete the pattern.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				);
			} );
	};

	const movePattern = () => {
		if ( ! selected || ! moveTo || moving ) {
			return;
		}
		setMoving( true );
		apiFetch( {
			path: `${ BASE }/library/${ selected.id }`,
			method: 'PUT',
			data: { collection: moveTo },
		} )
			.then( ( updated ) => {
				setMoving( false );
				setSelected( updated );
				createSuccessNotice(
					sprintf(
						/* translators: %s: collection title. */
						__( 'Moved to %s.', 'pattern-builder' ),
						updated.collection?.title || ''
					),
					{ type: 'snackbar' }
				);
				changed();
			} )
			.catch( ( error ) => {
				setMoving( false );
				createErrorNotice(
					error.message ||
						__( 'Could not move the pattern.', 'pattern-builder' ),
					{ type: 'snackbar' }
				);
			} );
	};

	return (
		<>
			<main className="pattern-builder-browser__main pattern-builder-cloud">
				{ chrome }

				{ status?.overPolicy && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'This account holds more than a free account may: a private collection, or a Personal over the cap. Nothing is taken away, and nothing more can be added to what is over. Make a collection public, move patterns into Personal within the cap, delete, or go Pro.',
							'pattern-builder'
						) }
						{ onGoPro && (
							<>
								{ ' ' }
								<Button variant="link" onClick={ onGoPro }>
									{ __( 'Go Pro', 'pattern-builder' ) }
								</Button>
							</>
						) }
					</Notice>
				) }

				<HStack alignment="left" spacing={ 2 }>
					<Button
						variant="secondary"
						size="compact"
						onClick={ () => setCreating( true ) }
					>
						{ __( 'New collection', 'pattern-builder' ) }
					</Button>
				</HStack>

				{ current && (
					<CollectionHeader
						collection={ current }
						canPrivate={ canPrivate }
						onChanged={ () => changed() }
						onDelete={ () => setDeleting( current ) }
					/>
				) }

				<div className="pattern-builder-cloud__content">
					<div className="pattern-builder-cloud__grid-column">
						{ items === null && <Spinner /> }
						{ items !== null && items.length === 0 && (
							<p>
								{ __(
									'No patterns found.',
									'pattern-builder'
								) }
							</p>
						) }
						<div className="pattern-builder-cloud__grid">
							{ ( items || [] ).map( ( pattern ) => (
								<CloudCard
									key={ pattern.id }
									pattern={ pattern }
									isSelected={ selected?.id === pattern.id }
									onSelect={ ( picked ) =>
										setSelected(
											selected?.id === picked.id
												? null
												: picked
										)
									}
								/>
							) ) }
						</div>
						{ pages > 1 && (
							<HStack
								alignment="center"
								spacing={ 2 }
								className="pattern-builder-cloud__pagination"
							>
								<Button
									variant="tertiary"
									disabled={ page <= 1 }
									onClick={ () => setPage( page - 1 ) }
								>
									{ __( 'Previous', 'pattern-builder' ) }
								</Button>
								<span>
									{ sprintf(
										/* translators: 1: current page, 2: total pages. */
										__(
											'Page %1$d of %2$d',
											'pattern-builder'
										),
										page,
										pages
									) }
								</span>
								<Button
									variant="tertiary"
									disabled={ page >= pages }
									onClick={ () => setPage( page + 1 ) }
								>
									{ __( 'Next', 'pattern-builder' ) }
								</Button>
							</HStack>
						) }
					</div>
				</div>

				{ modals }

				{ creating && (
					<NewCollectionModal
						canPrivate={ canPrivate }
						onGoPro={ onGoPro }
						onClose={ () => setCreating( false ) }
						onCreated={ ( created ) => {
							setCreating( false );
							createSuccessNotice(
								isListed( created )
									? sprintf(
											/* translators: %s: collection title. */
											__(
												'“%s” created. It is public.',
												'pattern-builder'
											),
											created.title
									  )
									: sprintf(
											/* translators: %s: collection title. */
											__(
												'“%s” created.',
												'pattern-builder'
											),
											created.title
									  ),
								{ type: 'snackbar' }
							);
							changed();
						} }
					/>
				) }

				{ deleting && (
					<DeleteCollectionModal
						collection={ deleting }
						onGoPro={ onGoPro }
						onClose={ () => setDeleting( null ) }
						onDeleted={ () => {
							setDeleting( null );
							createSuccessNotice(
								__( 'Collection deleted.', 'pattern-builder' ),
								{ type: 'snackbar' }
							);
							changed();
						} }
					/>
				) }
			</main>

			<aside className="pattern-builder-browser__details">
				{ selected ? (
					<CloudDetails
						pattern={ selected }
						source="library"
						onDownload={ requestDownload }
						onDelete={ deleteCloudPattern }
						onEditLocal={ onEditLocal }
						busy={ busy }
					>
						{ collections && collections.length > 1 && (
							<PanelBody
								title={ __( 'Collection', 'pattern-builder' ) }
								initialOpen
							>
								<VStack spacing={ 2 }>
									<CollectionPicker
										label={ __(
											'Move to collection',
											'pattern-builder'
										) }
										collections={ collections }
										value={
											moveTo ||
											selected.collection?.id ||
											0
										}
										onChange={ setMoveTo }
										onCreated={ () => loadCollections() }
										disabled={ moving }
									/>
									<Button
										variant="secondary"
										isBusy={ moving }
										disabled={
											moving ||
											! moveTo ||
											moveTo === selected.collection?.id
										}
										onClick={ movePattern }
									>
										{ __( 'Move', 'pattern-builder' ) }
									</Button>
								</VStack>
							</PanelBody>
						) }
					</CloudDetails>
				) : (
					<div className="pattern-builder-details is-empty">
						<Text variant="muted">
							{ __( 'No pattern selected.', 'pattern-builder' ) }
						</Text>
					</div>
				) }
			</aside>
		</>
	);
}
