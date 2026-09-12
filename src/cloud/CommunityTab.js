import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
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
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import { CloudCard, CloudDetails, useDownloadFlow } from './CloudBrowser';
import { CollectionTile } from './CollectionTile';
import { CollectionView } from './CollectionView';
import { SaveCollectionFlow } from './SaveCollectionFlow';
import { collectionKey, installedFromCollection } from './collections';

const BASE = '/pattern-builder/v1/cloud';

/**
 * A paged run of tiles or cards.
 *
 * @param {Object}   props        Component props.
 * @param {number}   props.page   Current page.
 * @param {number}   props.pages  Page count.
 * @param {Function} props.onPage Called with the next page number.
 */
function Pagination( { page, pages, onPage } ) {
	if ( pages <= 1 ) {
		return null;
	}
	return (
		<HStack
			alignment="center"
			spacing={ 2 }
			className="pattern-builder-cloud__pagination"
		>
			<Button
				variant="tertiary"
				disabled={ page <= 1 }
				onClick={ () => onPage( page - 1 ) }
			>
				{ __( 'Previous', 'pattern-builder' ) }
			</Button>
			<span>
				{ sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$d of %2$d', 'pattern-builder' ),
					page,
					pages
				) }
			</span>
			<Button
				variant="tertiary"
				disabled={ page >= pages }
				onClick={ () => onPage( page + 1 ) }
			>
				{ __( 'Next', 'pattern-builder' ) }
			</Button>
		</HStack>
	);
}

/**
 * The Community tab: collections first.
 *
 * @param {Object}   props              Component props.
 * @param {Element}  props.chrome       The account bar and notices the shell renders.
 * @param {Object}   props.status       The /cloud/status payload.
 * @param {string}   props.search       Search term, owned by the browser chrome.
 * @param {Function} props.onDownloaded Called after a pattern lands locally.
 * @param {Function} props.onEditLocal  Opens an installed local copy's editor.
 * @param {Function} props.onGoPro      Opens the upgrade, or null.
 */
export function CommunityTab( {
	chrome,
	status,
	search,
	onDownloaded,
	onEditLocal,
	onGoPro,
} ) {
	const [ collections, setCollections ] = useState( null );
	const [ collectionsPage, setCollectionsPage ] = useState( 1 );
	const [ collectionsPages, setCollectionsPages ] = useState( 1 );
	const [ patterns, setPatterns ] = useState( null );
	const [ patternsPage, setPatternsPage ] = useState( 1 );
	const [ patternsPages, setPatternsPages ] = useState( 1 );
	const [ open, setOpen ] = useState( null );
	const [ opened, setOpened ] = useState( null );
	const [ selected, setSelected ] = useState( null );
	const [ installedNames, setInstalledNames ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ reloadKey, setReloadKey ] = useState( 0 );

	const { createErrorNotice } = useDispatch( noticesStore );

	const downloaded = useCallback( () => {
		setReloadKey( ( key ) => key + 1 );
		onDownloaded?.();
	}, [ onDownloaded ] );

	const { busy, requestDownload, modals } = useDownloadFlow( {
		source: 'directory',
		onDownloaded: downloaded,
	} );

	const isSearching = search.trim() !== '';
	useEffect( () => {
		apiFetch( { path: `${ BASE }/installed` } )
			.then( ( data ) =>
				setInstalledNames( Array.isArray( data ) ? data : [] )
			)
			.catch( () => setInstalledNames( [] ) );
	}, [ reloadKey ] );
	useEffect( () => {
		setCollectionsPage( 1 );
		setPatternsPage( 1 );
		setSelected( null );
		if ( search.trim() !== '' ) {
			setOpen( null );
		}
	}, [ search ] );
	useEffect( () => {
		if ( open ) {
			return;
		}
		setCollections( null );
		const query = new URLSearchParams( {
			page: String( collectionsPage ),
			per_page: isSearching ? '6' : '24',
		} );
		if ( isSearching ) {
			query.set( 'search', search.trim() );
		}
		apiFetch( { path: `${ BASE }/collections?${ query }` } )
			.then( ( data ) => {
				setCollections( data.items || [] );
				setCollectionsPages( data.pages || 1 );
			} )
			.catch( ( error ) => {
				setCollections( [] );
				createErrorNotice(
					error.message ||
						__( 'Could not load collections.', 'pattern-builder' ),
					{ type: 'snackbar' }
				);
			} );
	}, [ open, isSearching, search, collectionsPage, createErrorNotice ] );
	useEffect( () => {
		if ( open || ! isSearching ) {
			setPatterns( null );
			return;
		}
		setPatterns( null );
		const query = new URLSearchParams( {
			page: String( patternsPage ),
			search: search.trim(),
		} );
		apiFetch( { path: `${ BASE }/directory?${ query }` } )
			.then( ( data ) => {
				setPatterns( data.items || [] );
				setPatternsPages( data.pages || 1 );
			} )
			.catch( () => setPatterns( [] ) );
	}, [ open, isSearching, search, patternsPage ] );
	useEffect( () => {
		if ( ! open ) {
			setOpened( null );
			return;
		}
		setOpened( ( current ) =>
			current &&
			current.owner === open.owner &&
			current.slug === open.slug
				? current
				: null
		);
		apiFetch( {
			path: `${ BASE }/collections/${ open.owner }/${ open.slug }`,
		} )
			.then( setOpened )
			.catch( ( error ) => {
				setOpen( null );
				createErrorNotice(
					error.message ||
						__(
							'Could not load the collection.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				);
			} );
	}, [ open, reloadKey, createErrorNotice ] );

	const openCollection = ( collection ) => {
		setSelected( null );
		setOpen( { owner: collection.owner, slug: collection.slug } );
	};

	const grid = ( items ) => (
		<div className="pattern-builder-cloud__grid">
			{ items.map( ( pattern ) => (
				<CloudCard
					key={ pattern.id }
					pattern={ pattern }
					isSelected={ selected?.id === pattern.id }
					onSelect={ ( picked ) =>
						setSelected(
							selected?.id === picked.id ? null : picked
						)
					}
				/>
			) ) }
		</div>
	);

	const tiles = ( items ) => (
		<div className="pattern-builder-cloud__collections">
			{ items.map( ( collection ) => (
				<CollectionTile
					key={ collectionKey( collection ) }
					collection={ collection }
					installed={ installedFromCollection(
						installedNames,
						collection
					) }
					onOpen={ openCollection }
				/>
			) ) }
		</div>
	);

	let body;
	if ( open ) {
		body = (
			<CollectionView
				collection={ opened }
				onBack={ () => {
					setOpen( null );
					setSelected( null );
				} }
				onSaveCollection={ () => setSaving( true ) }
				selected={ selected }
				onSelect={ setSelected }
				busy={ busy }
			/>
		);
	} else if ( isSearching ) {
		body = (
			<>
				<Heading level={ 3 } size={ 14 }>
					{ __( 'Collections', 'pattern-builder' ) }
				</Heading>
				{ collections === null && <Spinner /> }
				{ collections !== null && collections.length === 0 && (
					<Text variant="muted">
						{ __( 'No collections match.', 'pattern-builder' ) }
					</Text>
				) }
				{ collections && tiles( collections ) }
				<Pagination
					page={ collectionsPage }
					pages={ collectionsPages }
					onPage={ setCollectionsPage }
				/>
				<Heading level={ 3 } size={ 14 }>
					{ __( 'Patterns', 'pattern-builder' ) }
				</Heading>
				{ patterns === null && <Spinner /> }
				{ patterns !== null && patterns.length === 0 && (
					<Text variant="muted">
						{ __( 'No patterns match.', 'pattern-builder' ) }
					</Text>
				) }
				{ patterns && grid( patterns ) }
				<Pagination
					page={ patternsPage }
					pages={ patternsPages }
					onPage={ setPatternsPage }
				/>
			</>
		);
	} else {
		body = (
			<>
				{ collections === null && <Spinner /> }
				{ collections !== null && collections.length === 0 && (
					<p>
						{ __(
							'Nobody has shared a collection yet.',
							'pattern-builder'
						) }
					</p>
				) }
				{ collections && tiles( collections ) }
				<Pagination
					page={ collectionsPage }
					pages={ collectionsPages }
					onPage={ setCollectionsPage }
				/>
			</>
		);
	}

	return (
		<>
			<main className="pattern-builder-browser__main pattern-builder-cloud">
				{ chrome }
				<div className="pattern-builder-cloud__content">
					<div className="pattern-builder-cloud__grid-column">
						{ body }
					</div>
				</div>
				{ modals }
				{ saving && opened && (
					<SaveCollectionFlow
						collection={ opened }
						patterns={ opened.patterns || [] }
						status={ status }
						onGoPro={ onGoPro }
						onClose={ () => setSaving( false ) }
						onInstalled={ downloaded }
					/>
				) }
			</main>

			<aside className="pattern-builder-browser__details">
				{ selected ? (
					<CloudDetails
						pattern={ selected }
						source="directory"
						onDownload={ requestDownload }
						onEditLocal={ onEditLocal }
						busy={ busy }
					/>
				) : (
					<div className="pattern-builder-details is-empty">
						<Text variant="muted">
							{ open
								? __(
										'No pattern selected.',
										'pattern-builder'
								  )
								: __(
										'Open a collection to browse its patterns, or search for a pattern by name.',
										'pattern-builder'
								  ) }
						</Text>
					</div>
				) }
			</aside>
		</>
	);
}
