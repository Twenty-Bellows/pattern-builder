import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
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
import { addQueryArgs } from '@wordpress/url';

import { fetchAllPatterns } from '../utils/resolvers';
import './cloud.scss';

const BASE = '/pattern-builder/v1/cloud';

export const CLOUD_LIBRARY = 'cloud-library';
export const CLOUD_DIRECTORY = 'cloud-directory';

/**
 * Sign in / create an account without leaving wp-admin; credentials relay
 * through this site's proxy, which stores only the returned token.
 *
 * @param {Object}   props             Component props.
 * @param {Function} props.onConnected Receives the fresh status payload.
 */
function ConnectPanel( { onConnected } ) {
	const [ mode, setMode ] = useState( 'login' );
	const [ email, setEmail ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const isSignup = mode === 'signup';

	const submit = ( event ) => {
		event.preventDefault();
		if ( busy || ! email || ! password ) {
			return;
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `${ BASE }/${ isSignup ? 'signup' : 'login' }`,
			method: 'POST',
			data: isSignup ? { email, password, name } : { email, password },
		} )
			.then( ( data ) => onConnected( data ) )
			.catch( ( err ) => {
				setBusy( false );
				setError(
					err.message ||
						__(
							'The connection failed. Try again.',
							'pattern-builder'
						)
				);
			} );
	};

	return (
		<form className="pattern-builder-cloud__connect" onSubmit={ submit }>
			<VStack spacing={ 4 }>
				<Heading
					level={ 2 }
					size={ 18 }
					className="pattern-builder-cloud__connect-title"
				>
					{ __( 'Your patterns, on every site.', 'pattern-builder' ) }
				</Heading>
				<p className="pattern-builder-cloud__connect-intro">
					{ __(
						'Keep a pattern library on patternbuilderwp.com: upload patterns from this site, download them anywhere, and share collections with the community.',
						'pattern-builder'
					) }
				</p>
				{ isSignup && (
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Display name', 'pattern-builder' ) }
						value={ name }
						onChange={ setName }
						autoComplete="name"
					/>
				) }
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Email', 'pattern-builder' ) }
					type="email"
					value={ email }
					onChange={ setEmail }
					autoComplete="email"
					required
				/>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Password', 'pattern-builder' ) }
					type="password"
					value={ password }
					onChange={ setPassword }
					autoComplete={
						isSignup ? 'new-password' : 'current-password'
					}
					help={
						isSignup
							? __( 'At least 8 characters.', 'pattern-builder' )
							: undefined
					}
					required
				/>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<Button
					variant="primary"
					type="submit"
					icon={ cloudUpload }
					isBusy={ busy }
					disabled={ busy || ! email || ! password }
				>
					{ isSignup
						? __( 'Create account & connect', 'pattern-builder' )
						: __( 'Sign in & connect', 'pattern-builder' ) }
				</Button>
				<Button
					variant="link"
					onClick={ () => {
						setMode( isSignup ? 'login' : 'signup' );
						setError( '' );
					} }
				>
					{ isSignup
						? __(
								'Already have an account? Sign in',
								'pattern-builder'
						  )
						: __(
								'New here? Create a free account',
								'pattern-builder'
						  ) }
				</Button>
			</VStack>
		</form>
	);
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
	// Contain-fit the fixed-width preview so the whole pattern shows; the
	// cross-origin preview document reports its height via postMessage.
	const previewRef = useRef( null );
	const iframeRef = useRef( null );
	const [ box, setBox ] = useState( { width: 0, height: 0 } );
	const [ contentHeight, setContentHeight ] = useState( 0 );

	useEffect( () => {
		const node = previewRef.current;
		if ( ! node || typeof window.ResizeObserver === 'undefined' ) {
			return;
		}
		const observer = new window.ResizeObserver( ( entries ) => {
			const rect = entries[ 0 ]?.contentRect;
			if ( rect?.width ) {
				setBox( { width: rect.width, height: rect.height } );
			}
		} );
		observer.observe( node );
		return () => observer.disconnect();
	}, [] );

	useEffect( () => {
		const onMessage = ( event ) => {
			if (
				event.source !== iframeRef.current?.contentWindow ||
				event.data?.type !== 'pbwp-preview-size' ||
				! event.data.height
			) {
				return;
			}
			setContentHeight(
				Math.min( 4000, Math.max( 200, Number( event.data.height ) ) )
			);
		};
		window.addEventListener( 'message', onMessage );
		return () => window.removeEventListener( 'message', onMessage );
	}, [] );

	const docHeight = contentHeight || Math.ceil( ( 2 / 3 ) * PREVIEW_WIDTH );
	const widthFit = box.width ? box.width / PREVIEW_WIDTH : 0.2;
	const scale = box.height
		? Math.min( widthFit, box.height / docHeight )
		: widthFit;

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
					ref={ iframeRef }
					title={ pattern.title }
					src={ pattern.previewUrl }
					loading="lazy"
					scrolling="no"
					tabIndex={ -1 }
					style={ {
						transform: `scale(${ scale })`,
						height: `${ docHeight }px`,
						marginLeft: `${ Math.max(
							0,
							( box.width - PREVIEW_WIDTH * scale ) / 2
						) }px`,
						marginTop: `${ Math.max(
							0,
							( box.height - docHeight * scale ) / 2
						) }px`,
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
 * The details/actions column for a selected cloud pattern; an installed
 * pattern offers Edit instead of the download actions.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.pattern     Cloud pattern summary.
 * @param {string}   props.source      'library' or 'directory'.
 * @param {Function} props.onDownload  Called with (pattern, destination).
 * @param {Function} props.onDelete    Called with the pattern (library only).
 * @param {Function} props.onEditLocal Called with { source, id } of the local copy.
 * @param {boolean}  props.busy        Whether an action is in flight.
 */
function CloudDetails( {
	pattern,
	source,
	onDownload,
	onDelete,
	onEditLocal,
	busy,
} ) {
	// undefined = looking it up, null = not installed, else { type, id, title }.
	const [ installed, setInstalled ] = useState( undefined );

	useEffect( () => {
		if ( busy ) {
			return; // Re-check once the in-flight action (e.g. a download) lands.
		}
		setInstalled( undefined );
		apiFetch( {
			path: addQueryArgs( `${ BASE }/pattern-state`, {
				cloudId: pattern.id,
			} ),
		} )
			.then( ( data ) => setInstalled( data.installed || null ) )
			.catch( () => setInstalled( null ) );
	}, [ pattern.id, busy ] );

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
			{ installed === undefined && <Spinner /> }
			{ !! installed && (
				<>
					<p className="pattern-builder-cloud__meta pattern-builder-cloud__installed">
						{ installed.type === 'user'
							? __(
									'Installed on this site as a user pattern.',
									'pattern-builder'
							  )
							: __(
									'Installed on this site as a theme pattern.',
									'pattern-builder'
							  ) }
					</p>
					<HStack spacing={ 2 } alignment="left" wrap>
						<Button
							variant="primary"
							disabled={ busy }
							onClick={ () =>
								onEditLocal( {
									source: installed.type,
									id: installed.id,
								} )
							}
						>
							{ __( 'Edit pattern', 'pattern-builder' ) }
						</Button>
					</HStack>
				</>
			) }
			{ installed === null && (
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
			) }
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
 * The missing-tokens step of a download: the pattern references design
 * tokens this site doesn't define, so ask where to put them (the
 * destination's own definitions always win and are never listed here).
 *
 * @param {Object}   props           Component props.
 * @param {Array}    props.missing   Tokens the site lacks.
 * @param {boolean}  props.busy      Whether the download is in flight.
 * @param {Function} props.onConfirm Called with 'user' or 'theme'.
 * @param {Function} props.onClose   Close/cancel callback.
 */
function TokensModal( { missing, busy, onConfirm, onClose } ) {
	const [ destination, setDestination ] = useState( 'user' );

	const typeLabels = {
		color: __( 'Color', 'pattern-builder' ),
		gradient: __( 'Gradient', 'pattern-builder' ),
		spacing: __( 'Spacing', 'pattern-builder' ),
		fontSize: __( 'Font size', 'pattern-builder' ),
		fontFamily: __( 'Font family', 'pattern-builder' ),
	};

	return (
		<Modal
			title={ __(
				'This pattern brings new design tokens',
				'pattern-builder'
			) }
			onRequestClose={ onClose }
			className="pattern-builder-cloud__tokens-modal"
		>
			<p className="pattern-builder-cloud__meta">
				{ __(
					'The pattern references design tokens this site doesn’t define yet. They’ll be added with the values from the pattern’s source site; tokens your site already defines keep your values.',
					'pattern-builder'
				) }
			</p>
			<ul className="pattern-builder-cloud__tokens-list">
				{ missing.map( ( token ) => (
					<li key={ `${ token.type }:${ token.slug }` }>
						{ ( token.type === 'color' ||
							token.type === 'gradient' ) && (
							<span
								className="pattern-builder-cloud__token-swatch"
								style={ { background: token.value } }
							/>
						) }
						<span className="pattern-builder-cloud__token-name">
							{ token.name || token.slug }
						</span>
						<span className="pattern-builder-cloud__token-meta">
							{ typeLabels[ token.type ] || token.type }
						</span>
						<code className="pattern-builder-cloud__token-value">
							{ token.value }
						</code>
					</li>
				) ) }
			</ul>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Add them to', 'pattern-builder' ) }
				value={ destination }
				options={ [
					{
						label: __(
							'Site styles (Global Styles) — recommended, revertable in the editor',
							'pattern-builder'
						),
						value: 'user',
					},
					{
						label: __(
							'The active theme’s theme.json file — ships with the theme',
							'pattern-builder'
						),
						value: 'theme',
					},
				] }
				onChange={ setDestination }
			/>
			<HStack
				alignment="right"
				spacing={ 2 }
				className="pattern-builder-cloud__tokens-actions"
			>
				<Button
					variant="tertiary"
					onClick={ onClose }
					disabled={ busy }
				>
					{ __( 'Cancel', 'pattern-builder' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => onConfirm( destination ) }
				>
					{ __( 'Add tokens & download', 'pattern-builder' ) }
				</Button>
			</HStack>
		</Modal>
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
 * @param {Object}   props               Component props.
 * @param {string}   props.view          CLOUD_LIBRARY or CLOUD_DIRECTORY.
 * @param {Function} props.onDownloaded  Called after a pattern lands locally.
 * @param {Function} props.onEditLocal   Opens an installed local copy's editor.
 * @param {string}   props.search        Search term, owned by the browser chrome.
 * @param {string}   props.collection    Active collection filter ('' for all).
 * @param {Function} props.onCollections Reports this view's collections for the rail.
 */
export function CloudBrowser( {
	view,
	onDownloaded,
	onEditLocal,
	search = '',
	collection = '',
	onCollections,
} ) {
	const [ status, setStatus ] = useState( null );
	const [ items, setItems ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ pages, setPages ] = useState( 1 );
	const [ selected, setSelected ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ isUploadOpen, setIsUploadOpen ] = useState( false );
	const [ links, setLinks ] = useState( {} );
	const [ uploadBusyKey, setUploadBusyKey ] = useState( '' );
	const [ pendingDownload, setPendingDownload ] = useState( null );

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

	useEffect( () => {
		refreshStatus();
	}, [ refreshStatus ] );

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

	// A new search or collection filter restarts paging.
	useEffect( () => setPage( 1 ), [ search, collection, view ] );

	useEffect( () => {
		if ( ! onCollections ) {
			return;
		}
		if ( isLibrary && ! status?.connected ) {
			onCollections( [] );
			return;
		}
		apiFetch( {
			path: `${ BASE }/${ isLibrary ? 'categories' : 'collections' }`,
		} )
			.then( ( data ) =>
				onCollections( Array.isArray( data ) ? data : [] )
			)
			.catch( () => onCollections( [] ) );
	}, [ isLibrary, status, onCollections ] );

	useEffect( () => {
		if ( ! isLibrary || ! status?.connected ) {
			return;
		}
		apiFetch( { path: `${ BASE }/links` } )
			.then( ( data ) => setLinks( data || {} ) )
			.catch( () => setLinks( {} ) );
	}, [ isLibrary, status ] );

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

	// Tokens this site lacks need a destination before the download (§4a).
	const download = ( pattern, destination ) => {
		if ( ! pattern.tokens?.length ) {
			performDownload( pattern, destination );
			return;
		}
		setBusy( true );
		apiFetch( {
			path: `${ BASE }/tokens/check`,
			method: 'POST',
			data: { tokens: pattern.tokens },
		} )
			.then( ( check ) => {
				if ( check.missing?.length ) {
					setBusy( false );
					setPendingDownload( {
						pattern,
						destination,
						missing: check.missing,
					} );
				} else {
					performDownload( pattern, destination );
				}
			} )
			.catch( () => performDownload( pattern, destination ) );
	};

	const performDownload = ( pattern, destination, tokenDestination ) => {
		setBusy( true );
		apiFetch( {
			path: `${ BASE }/download`,
			method: 'POST',
			data: {
				source: view === CLOUD_DIRECTORY ? 'directory' : 'library',
				cloudId: pattern.id,
				destination,
				tokenDestination,
			},
		} )
			.then( ( result ) => {
				setPendingDownload( null );
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
				const writtenCount = Object.values(
					result.tokensWritten || {}
				).reduce( ( sum, slugs ) => sum + slugs.length, 0 );
				if ( writtenCount > 0 ) {
					createSuccessNotice(
						sprintf(
							/* translators: 1: token count, 2: where they were added. */
							_n(
								'%1$d design token added to %2$s.',
								'%1$d design tokens added to %2$s.',
								writtenCount,
								'pattern-builder'
							),
							writtenCount,
							tokenDestination === 'theme'
								? __( 'theme.json', 'pattern-builder' )
								: __( 'Site styles', 'pattern-builder' )
						),
						{ type: 'snackbar' }
					);
				}
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

	// The directory browses anonymously; the library needs an account.
	if ( ! status.connected && isLibrary ) {
		return (
			<ConnectPanel
				onConnected={ ( data ) => {
					setStatus( data );
					createSuccessNotice(
						__(
							'Connected to patternbuilderwp.com.',
							'pattern-builder'
						),
						{ type: 'snackbar' }
					);
				} }
			/>
		);
	}

	const tokensModal = pendingDownload && (
		<TokensModal
			missing={ pendingDownload.missing }
			busy={ busy }
			onConfirm={ ( tokenDestination ) =>
				performDownload(
					pendingDownload.pattern,
					pendingDownload.destination,
					tokenDestination
				)
			}
			onClose={ () => setPendingDownload( null ) }
		/>
	);

	const usage = status.usage;

	return (
		<div className="pattern-builder-cloud">
			<HStack
				alignment="left"
				spacing={ 4 }
				wrap
				className="pattern-builder-cloud__toolbar"
			>
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

				{ selected ? (
					<CloudDetails
						pattern={ selected }
						source={ isLibrary ? 'library' : 'directory' }
						onDownload={ download }
						onDelete={ deleteCloudPattern }
						onEditLocal={ onEditLocal }
						busy={ busy }
					/>
				) : (
					<div className="pattern-builder-cloud__details is-empty">
						<p className="pattern-builder-cloud__meta">
							{ __( 'No pattern selected.', 'pattern-builder' ) }
						</p>
					</div>
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

			{ tokensModal }
		</div>
	);
}
