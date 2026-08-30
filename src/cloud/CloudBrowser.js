import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	Button,
	Modal,
	SearchControl,
	SelectControl,
	Spinner,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
} from '@wordpress/components';
import { cloudUpload, closeSmall } from '@wordpress/icons';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import { fetchAllPatterns } from '../utils/resolvers';
import './cloud.scss';

const BASE = '/pattern-builder/v1/cloud';

export const CLOUD_LIBRARY = 'cloud-library';
export const CLOUD_DIRECTORY = 'cloud-directory';

/**
 * Whether the current page load is the OAuth callback from the service.
 *
 * @return {Object|null} { code, state } or null.
 */
export function readConnectCallback() {
	const params = new URLSearchParams( window.location.search );
	if ( params.get( 'pbcloud-callback' ) && params.get( 'code' ) ) {
		return {
			code: params.get( 'code' ),
			state: params.get( 'state' ) || '',
		};
	}
	return null;
}

function cleanConnectCallbackUrl() {
	const url = new URL( window.location.href );
	[ 'pbcloud-callback', 'code', 'state', 'error' ].forEach( ( key ) =>
		url.searchParams.delete( key )
	);
	window.history.replaceState( {}, '', url.toString() );
}

/**
 * A cloud pattern card: live iframe preview, title, premium badge.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.pattern    Cloud pattern summary.
 * @param {boolean}  props.isSelected Whether the card is selected.
 * @param {Function} props.onSelect   Selection callback.
 */
const PREVIEW_WIDTH = 1400;

function CloudCard( { pattern, isSelected, onSelect } ) {
	// Scale the fixed-width preview iframe to the card (measured — CSS
	// alone can't feed a container-relative number into transform:scale).
	const previewRef = useRef( null );
	const [ scale, setScale ] = useState( 0.2 );

	useEffect( () => {
		const node = previewRef.current;
		if ( ! node || typeof window.ResizeObserver === 'undefined' ) {
			return;
		}
		const observer = new window.ResizeObserver( ( entries ) => {
			const width = entries[ 0 ]?.contentRect?.width;
			if ( width ) {
				setScale( width / PREVIEW_WIDTH );
			}
		} );
		observer.observe( node );
		return () => observer.disconnect();
	}, [] );

	return (
		<button
			type="button"
			className={
				'pattern-builder-cloud__card' +
				( isSelected ? ' is-selected' : '' )
			}
			onClick={ () => onSelect( pattern ) }
		>
			<span
				className="pattern-builder-cloud__card-preview"
				ref={ previewRef }
			>
				<iframe
					title={ pattern.title }
					src={ pattern.previewUrl }
					loading="lazy"
					scrolling="no"
					tabIndex={ -1 }
					style={ {
						transform: `scale(${ scale })`,
						height: `${ Math.ceil( ( 2 / 3 ) * PREVIEW_WIDTH ) }px`,
					} }
				/>
			</span>
			<span className="pattern-builder-cloud__card-title">
				<span>{ pattern.title }</span>
				{ pattern.premium && (
					<span className="pattern-builder-cloud__badge">
						{ __( 'Premium', 'pattern-builder' ) }
					</span>
				) }
			</span>
		</button>
	);
}

/**
 * The details/actions column for a selected cloud pattern.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.pattern    Cloud pattern summary.
 * @param {string}   props.source     'library' or 'directory'.
 * @param {Function} props.onDownload Called with (pattern, destination).
 * @param {Function} props.onDelete   Called with the pattern (library only).
 * @param {boolean}  props.busy       Whether an action is in flight.
 */
function CloudDetails( { pattern, source, onDownload, onDelete, busy } ) {
	return (
		<VStack
			spacing={ 3 }
			className="pattern-builder-cloud__details"
			as="aside"
		>
			<Heading level={ 2 } size={ 15 }>
				{ pattern.title }
			</Heading>
			{ pattern.description && <p>{ pattern.description }</p> }
			{ pattern.categories?.length > 0 && (
				<p className="pattern-builder-cloud__meta">
					{ __( 'Collections:', 'pattern-builder' ) }{ ' ' }
					{ pattern.categories
						.map( ( category ) => category.name )
						.join( ', ' ) }
				</p>
			) }
			{ source === 'directory' && pattern.author && (
				<p className="pattern-builder-cloud__meta">
					{ sprintf(
						/* translators: %s: author display name. */
						__( 'Shared by %s', 'pattern-builder' ),
						pattern.author
					) }
				</p>
			) }
			<HStack spacing={ 2 } alignment="left" wrap>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => onDownload( pattern, 'user' ) }
				>
					{ __( 'Add as user pattern', 'pattern-builder' ) }
				</Button>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => onDownload( pattern, 'theme' ) }
				>
					{ __( 'Add as theme pattern', 'pattern-builder' ) }
				</Button>
			</HStack>
			{ source === 'library' && (
				<Button
					variant="tertiary"
					isDestructive
					disabled={ busy }
					onClick={ () => onDelete( pattern ) }
				>
					{ __( 'Delete from cloud', 'pattern-builder' ) }
				</Button>
			) }
		</VStack>
	);
}

/**
 * The "Upload a pattern" modal: pick any local pattern, optionally set
 * cloud collections, and upload (or update its existing cloud copy).
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.links    Local-key → cloud link map.
 * @param {Function} props.onUpload Called with (pattern, categories, asNew).
 * @param {Function} props.onClose  Close callback.
 * @param {string}   props.busyKey  Local key of an in-flight upload.
 */
function UploadModal( { links, onUpload, onClose, busyKey } ) {
	const [ localPatterns, setLocalPatterns ] = useState( null );
	const [ categories, setCategories ] = useState( '' );

	useEffect( () => {
		fetchAllPatterns()
			.then( setLocalPatterns )
			.catch( () => setLocalPatterns( [] ) );
	}, [] );

	const categoryList = categories
		.split( ',' )
		.map( ( name ) => name.trim() )
		.filter( Boolean );

	return (
		<Modal
			title={ __(
				'Upload a pattern to your library',
				'pattern-builder'
			) }
			onRequestClose={ onClose }
			className="pattern-builder-cloud__upload-modal"
		>
			<TextControl
				__nextHasNoMarginBottom
				label={ __(
					'Cloud collections (comma-separated, optional)',
					'pattern-builder'
				) }
				help={ __(
					'Patterns are private until you make a collection public on patternbuilderwp.com.',
					'pattern-builder'
				) }
				value={ categories }
				onChange={ setCategories }
			/>
			{ localPatterns === null && <Spinner /> }
			{ localPatterns !== null && localPatterns.length === 0 && (
				<p>
					{ __( 'No local patterns to upload.', 'pattern-builder' ) }
				</p>
			) }
			<ul className="pattern-builder-cloud__upload-list">
				{ ( localPatterns || [] ).map( ( pattern ) => {
					const localKey =
						( pattern.source === 'user' ? 'user:' : 'theme:' ) +
						pattern.id;
					const linked = !! links[ localKey ];
					return (
						<li key={ localKey }>
							<span className="pattern-builder-cloud__upload-title">
								{ pattern.title }
								<small>
									{ pattern.source === 'user'
										? __(
												'User pattern',
												'pattern-builder'
										  )
										: __(
												'Theme pattern',
												'pattern-builder'
										  ) }
								</small>
							</span>
							<HStack spacing={ 2 } expanded={ false }>
								<Button
									variant="secondary"
									size="small"
									isBusy={ busyKey === localKey }
									disabled={ !! busyKey }
									onClick={ () =>
										onUpload( pattern, categoryList, false )
									}
								>
									{ linked
										? __(
												'Update cloud copy',
												'pattern-builder'
										  )
										: __( 'Upload', 'pattern-builder' ) }
								</Button>
								{ linked && (
									<Button
										variant="tertiary"
										size="small"
										disabled={ !! busyKey }
										onClick={ () =>
											onUpload(
												pattern,
												categoryList,
												true
											)
										}
									>
										{ __(
											'Upload as new',
											'pattern-builder'
										) }
									</Button>
								) }
							</HStack>
						</li>
					);
				} ) }
			</ul>
		</Modal>
	);
}

/**
 * The cloud browsing surface: connect state, My Cloud Library, and the
 * Public Directory — rendered in place of the local grid when a cloud rail
 * item is active.
 *
 * @param {Object}   props              Component props.
 * @param {string}   props.view         CLOUD_LIBRARY or CLOUD_DIRECTORY.
 * @param {Function} props.onDownloaded Called after a pattern lands locally.
 */
export function CloudBrowser( { view, onDownloaded } ) {
	const [ status, setStatus ] = useState( null );
	const [ items, setItems ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ pages, setPages ] = useState( 1 );
	const [ collections, setCollections ] = useState( [] );
	const [ collection, setCollection ] = useState( '' );
	const [ selected, setSelected ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ isUploadOpen, setIsUploadOpen ] = useState( false );
	const [ links, setLinks ] = useState( {} );
	const [ uploadBusyKey, setUploadBusyKey ] = useState( '' );

	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const isLibrary = view === CLOUD_LIBRARY;

	const refreshStatus = useCallback( () => {
		return apiFetch( { path: `${ BASE }/status` } )
			.then( ( data ) => {
				setStatus( data );
				return data;
			} )
			.catch( () => setStatus( { connected: false } ) );
	}, [] );

	// Complete the OAuth callback if this page load carries one.
	useEffect( () => {
		const callback = readConnectCallback();
		if ( ! callback ) {
			refreshStatus();
			return;
		}
		cleanConnectCallbackUrl();
		apiFetch( {
			path: `${ BASE }/connect/complete`,
			method: 'POST',
			data: callback,
		} )
			.then( ( data ) => {
				setStatus( data );
				createSuccessNotice(
					__(
						'Connected to patternbuilderwp.com.',
						'pattern-builder'
					),
					{ type: 'snackbar' }
				);
			} )
			.catch( ( error ) => {
				createErrorNotice(
					error.message ||
						__(
							'The connection could not be completed.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				);
				refreshStatus();
			} );
	}, [ refreshStatus, createSuccessNotice, createErrorNotice ] );

	const loadItems = useCallback( () => {
		if ( ! status?.connected && isLibrary ) {
			return;
		}
		setItems( null );
		const query = new URLSearchParams( { page: String( page ) } );
		if ( search ) {
			query.set( 'search', search );
		}
		if ( ! isLibrary && collection ) {
			query.set( 'category', collection );
		}
		apiFetch( {
			path: `${ BASE }/${
				isLibrary ? 'library' : 'directory'
			}?${ query }`,
		} )
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
	}, [ status, isLibrary, page, search, collection, createErrorNotice ] );

	useEffect( loadItems, [ loadItems ] );

	// Directory view: load the collection filter options once.
	useEffect( () => {
		if ( isLibrary ) {
			return;
		}
		apiFetch( { path: `${ BASE }/collections` } )
			.then( ( data ) =>
				setCollections( Array.isArray( data ) ? data : [] )
			)
			.catch( () => setCollections( [] ) );
	}, [ isLibrary ] );

	// Library view: load local link map for the upload modal.
	useEffect( () => {
		if ( ! isLibrary || ! status?.connected ) {
			return;
		}
		apiFetch( { path: `${ BASE }/links` } )
			.then( ( data ) => setLinks( data || {} ) )
			.catch( () => setLinks( {} ) );
	}, [ isLibrary, status ] );

	const connect = () => {
		setBusy( true );
		apiFetch( { path: `${ BASE }/connect`, method: 'POST' } )
			.then( ( data ) => {
				window.location.assign( data.authorizeUrl );
			} )
			.catch( ( error ) => {
				setBusy( false );
				createErrorNotice(
					error.message ||
						__(
							'Could not start the connection.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				);
			} );
	};

	const disconnect = () => {
		apiFetch( { path: `${ BASE }/disconnect`, method: 'POST' } ).then(
			() => {
				setStatus( { connected: false } );
				setItems( null );
				createSuccessNotice(
					__(
						'Disconnected from patternbuilderwp.com.',
						'pattern-builder'
					),
					{ type: 'snackbar' }
				);
			}
		);
	};

	const download = ( pattern, destination ) => {
		setBusy( true );
		apiFetch( {
			path: `${ BASE }/download`,
			method: 'POST',
			data: {
				source: isLibrary ? 'library' : 'directory',
				cloudId: pattern.id,
				destination,
			},
		} )
			.then( ( result ) => {
				const message =
					destination === 'theme'
						? sprintf(
								/* translators: %s: pattern title. */
								__(
									'“%s” added to your theme patterns.',
									'pattern-builder'
								),
								result.title
						  )
						: sprintf(
								/* translators: %s: pattern title. */
								__(
									'“%s” added to your user patterns.',
									'pattern-builder'
								),
								result.title
						  );
				createSuccessNotice( message, { type: 'snackbar' } );
				onDownloaded?.();
			} )
			.catch( ( error ) => {
				createErrorNotice(
					error.message ||
						__(
							'The pattern could not be downloaded.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				);
			} )
			.finally( () => setBusy( false ) );
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
		setBusy( true );
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
				refreshStatus();
				loadItems();
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
			} )
			.finally( () => setBusy( false ) );
	};

	const uploadLocal = ( pattern, categories, asNew ) => {
		const localKey =
			( pattern.source === 'user' ? 'user:' : 'theme:' ) + pattern.id;
		setUploadBusyKey( localKey );
		apiFetch( {
			path: `${ BASE }/upload`,
			method: 'POST',
			data: {
				patternType: pattern.source === 'user' ? 'user' : 'theme',
				patternId: pattern.id,
				categories,
				asNew,
			},
		} )
			.then( ( result ) => {
				const message = result.updated
					? sprintf(
							/* translators: %s: pattern title. */
							__(
								'“%s” updated in your cloud library.',
								'pattern-builder'
							),
							pattern.title
					  )
					: sprintf(
							/* translators: %s: pattern title. */
							__(
								'“%s” uploaded to your cloud library.',
								'pattern-builder'
							),
							pattern.title
					  );
				createSuccessNotice( message, { type: 'snackbar' } );
				setLinks( ( previous ) => ( {
					...previous,
					[ localKey ]: { cloudId: result.pattern?.id },
				} ) );
				refreshStatus();
				loadItems();
			} )
			.catch( ( error ) => {
				createErrorNotice(
					error.message ||
						__(
							'The pattern could not be uploaded.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				);
			} )
			.finally( () => setUploadBusyKey( '' ) );
	};

	if ( status === null ) {
		return (
			<div className="pattern-builder-cloud__loading">
				<Spinner />
			</div>
		);
	}

	// Disconnected: the library needs an account; the directory browses anonymously.
	if ( ! status.connected && isLibrary ) {
		return (
			<VStack
				spacing={ 4 }
				className="pattern-builder-cloud__connect"
				alignment="center"
			>
				<Heading level={ 2 } size={ 18 }>
					{ __( 'Your patterns, on every site.', 'pattern-builder' ) }
				</Heading>
				<p>
					{ __(
						'Connect to patternbuilderwp.com to keep a pattern library in the cloud: upload patterns from this site, download them anywhere, and share collections with the community.',
						'pattern-builder'
					) }
				</p>
				<Button
					variant="primary"
					icon={ cloudUpload }
					isBusy={ busy }
					onClick={ connect }
				>
					{ __(
						'Connect to patternbuilderwp.com',
						'pattern-builder'
					) }
				</Button>
				{ status.error && (
					<p className="pattern-builder-cloud__meta">
						{ status.error }
					</p>
				) }
			</VStack>
		);
	}

	const usage = status.usage;

	return (
		<div className="pattern-builder-cloud">
			<HStack
				alignment="left"
				spacing={ 4 }
				wrap
				className="pattern-builder-cloud__toolbar"
			>
				<SearchControl
					__nextHasNoMarginBottom
					className="pattern-builder-cloud__search"
					value={ search }
					onChange={ ( value ) => {
						setSearch( value );
						setPage( 1 );
					} }
					label={ __( 'Search cloud patterns', 'pattern-builder' ) }
				/>
				{ ! isLibrary && collections.length > 0 && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Collection', 'pattern-builder' ) }
						hideLabelFromVision
						value={ collection }
						options={ [
							{
								label: __(
									'All collections',
									'pattern-builder'
								),
								value: '',
							},
							...collections.map( ( item ) => ( {
								label:
									item.visibility === 'premium'
										? sprintf(
												/* translators: %s: collection name. */
												__(
													'%s (Premium)',
													'pattern-builder'
												),
												item.name
										  )
										: item.name,
								value: String( item.id ),
							} ) ),
						] }
						onChange={ ( value ) => {
							setCollection( value );
							setPage( 1 );
						} }
					/>
				) }
				{ isLibrary && status.connected && (
					<Button
						variant="primary"
						icon={ cloudUpload }
						onClick={ () => setIsUploadOpen( true ) }
					>
						{ __( 'Upload a pattern', 'pattern-builder' ) }
					</Button>
				) }
			</HStack>

			{ isLibrary && status.connected && (
				<HStack
					alignment="left"
					spacing={ 2 }
					className="pattern-builder-cloud__account"
				>
					<span>
						{ sprintf(
							/* translators: 1: account name, 2: tier. */
							__( 'Connected as %1$s (%2$s)', 'pattern-builder' ),
							status.account?.name || '',
							status.tier === 'pro'
								? __( 'Pro', 'pattern-builder' )
								: __( 'Free', 'pattern-builder' )
						) }
					</span>
					{ usage && usage.cap > 0 && (
						<span className="pattern-builder-cloud__meta">
							{ sprintf(
								/* translators: 1: stored count, 2: cap. */
								__(
									'%1$d of %2$d patterns stored',
									'pattern-builder'
								),
								usage.stored,
								usage.cap
							) }
						</span>
					) }
					<Button
						variant="tertiary"
						icon={ closeSmall }
						onClick={ disconnect }
					>
						{ __( 'Disconnect', 'pattern-builder' ) }
					</Button>
				</HStack>
			) }

			{ ! status.connected && ! isLibrary && (
				<p className="pattern-builder-cloud__meta">
					{ __(
						'Browsing the public directory. Connect from My Cloud Library to upload and manage your own patterns.',
						'pattern-builder'
					) }
				</p>
			) }

			<div className="pattern-builder-cloud__content">
				<div className="pattern-builder-cloud__grid-column">
					{ items === null && <Spinner /> }
					{ items !== null && items.length === 0 && (
						<p>{ __( 'No patterns found.', 'pattern-builder' ) }</p>
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

				{ selected && (
					<CloudDetails
						pattern={ selected }
						source={ isLibrary ? 'library' : 'directory' }
						onDownload={ download }
						onDelete={ deleteCloudPattern }
						busy={ busy }
					/>
				) }
			</div>

			{ isUploadOpen && (
				<UploadModal
					links={ links }
					busyKey={ uploadBusyKey }
					onUpload={ uploadLocal }
					onClose={ () => setIsUploadOpen( false ) }
				/>
			) }
		</div>
	);
}
