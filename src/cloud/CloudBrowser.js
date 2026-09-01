import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Flex,
	FlexItem,
	Modal,
	Notice,
	PanelBody,
	Spinner,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';
import { cloudUpload, closeSmall } from '@wordpress/icons';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { addQueryArgs } from '@wordpress/url';

import './cloud.scss';

const BASE = '/pattern-builder/v1/cloud';

// How long to keep asking whether a purchase has landed, and how often.
const UPGRADE_POLL_INTERVAL = 5000;
const UPGRADE_POLL_TIMEOUT = 3 * 60 * 1000;

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
 * A cloud pattern card: the service's preview document rendered at the
 * grid's design width and scaled into the same fixed square tile the local
 * cards use. The tile and the scale are fixed in CSS, and the preview
 * document centers its own content — nothing measures anything, so the two
 * grids cannot drift apart.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.pattern    Cloud pattern summary.
 * @param {boolean}  props.isSelected Whether the card is selected.
 * @param {Function} props.onSelect   Selection callback.
 */
function CloudCard( { pattern, isSelected, onSelect } ) {
	return (
		<button
			type="button"
			className={
				'pattern-builder-card pattern-builder-card--cloud' +
				( isSelected ? ' is-selected' : '' )
			}
			aria-pressed={ isSelected }
			onClick={ () => onSelect( pattern ) }
		>
			<span className="pattern-builder-card__preview">
				<iframe
					title={ pattern.title }
					src={ pattern.previewUrl }
					loading="lazy"
					scrolling="no"
					tabIndex={ -1 }
				/>
			</span>
			<span className="pattern-builder-card__title">
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
 * The details sidebar for a selected cloud pattern — the same shell the
 * local sidebar uses: title, kind, and the actions on top. Save picks a
 * destination; Edit appears once the pattern is installed here.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.pattern     Cloud pattern summary.
 * @param {string}   props.source      'library' or 'directory'.
 * @param {Function} props.onDownload  Called with the pattern to save.
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
		<div className="pattern-builder-details">
			<div className="pattern-builder-details__header">
				<Heading level={ 2 } size={ 16 } truncate>
					{ pattern.title }
				</Heading>
				<Text variant="muted" size="12px">
					{ source === 'library'
						? __( 'Cloud pattern', 'pattern-builder' )
						: __( 'Community pattern', 'pattern-builder' ) }
				</Text>

				<Flex className="pattern-builder-details__actions" gap={ 2 }>
					<FlexItem isBlock>
						<Button
							__next40pxDefaultSize
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () => onDownload( pattern ) }
							className="pattern-builder-details__action-button"
						>
							{ __( 'Save', 'pattern-builder' ) }
						</Button>
					</FlexItem>
					{ !! installed && (
						<FlexItem isBlock>
							<Button
								__next40pxDefaultSize
								variant="secondary"
								disabled={ busy }
								onClick={ () =>
									onEditLocal( {
										source: installed.type,
										id: installed.id,
									} )
								}
								className="pattern-builder-details__action-button"
							>
								{ __( 'Edit', 'pattern-builder' ) }
							</Button>
						</FlexItem>
					) }
				</Flex>
			</div>

			<div className="pattern-builder-details__panels">
				<PanelBody
					title={ __( 'Pattern Details', 'pattern-builder' ) }
					initialOpen
				>
					<VStack spacing={ 3 }>
						{ pattern.description && (
							<Text variant="muted">{ pattern.description }</Text>
						) }
						{ pattern.categories?.length > 0 && (
							<Text variant="muted" size="12px">
								{ __( 'Collections:', 'pattern-builder' ) }{ ' ' }
								{ pattern.categories
									.map( ( category ) => category.name )
									.join( ', ' ) }
							</Text>
						) }
						{ source === 'directory' && pattern.author && (
							<Text variant="muted" size="12px">
								{ sprintf(
									/* translators: %s: author display name. */
									__( 'Shared by %s', 'pattern-builder' ),
									pattern.author
								) }
							</Text>
						) }
						{ installed === undefined && <Spinner /> }
						{ !! installed && (
							<Text variant="muted" size="12px">
								{ installed.type === 'user'
									? __(
											'Installed on this site as a user pattern.',
											'pattern-builder'
									  )
									: __(
											'Installed on this site as a theme pattern.',
											'pattern-builder'
									  ) }
							</Text>
						) }
					</VStack>
				</PanelBody>

				{ source === 'library' && (
					<PanelBody
						title={ __( 'Cloud Actions', 'pattern-builder' ) }
						initialOpen
					>
						<Button
							variant="tertiary"
							isDestructive
							disabled={ busy }
							onClick={ () => onDelete( pattern ) }
						>
							{ __( 'Delete from cloud', 'pattern-builder' ) }
						</Button>
					</PanelBody>
				) }
			</div>
		</div>
	);
}

/**
 * Where a saved cloud pattern should land on this site.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.pattern   The pattern being saved.
 * @param {boolean}  props.busy      Whether the download is in flight.
 * @param {Function} props.onConfirm Called with 'user' or 'theme'.
 * @param {Function} props.onClose   Close/cancel callback.
 */
function DestinationModal( { pattern, busy, onConfirm, onClose } ) {
	return (
		<Modal
			title={ sprintf(
				/* translators: %s: pattern title. */
				__( 'Save “%s” to this site', 'pattern-builder' ),
				pattern.title
			) }
			onRequestClose={ onClose }
			className="pattern-builder-cloud__destination-modal"
		>
			<p className="pattern-builder-cloud__meta">
				{ __(
					'User patterns live in this site’s database; theme patterns are written into the active theme as files.',
					'pattern-builder'
				) }
			</p>
			<HStack spacing={ 3 } alignment="left" wrap>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => onConfirm( 'user' ) }
				>
					{ __( 'User', 'pattern-builder' ) }
				</Button>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => onConfirm( 'theme' ) }
				>
					{ __( 'Theme', 'pattern-builder' ) }
				</Button>
			</HStack>
		</Modal>
	);
}

/**
 * The missing-tokens step of a download: the pattern references design
 * tokens this site doesn't define. Where they go isn't a question — they
 * follow the pattern to the destination already chosen — so this lists what
 * will be added and says where.
 *
 * @param {Object}   props             Component props.
 * @param {Array}    props.missing     Tokens the site lacks.
 * @param {string}   props.destination 'user' or 'theme' — where the pattern is going.
 * @param {boolean}  props.busy        Whether the download is in flight.
 * @param {Function} props.onConfirm   Proceed with the download.
 * @param {Function} props.onClose     Close/cancel callback.
 */
function TokensModal( { missing, destination, busy, onConfirm, onClose } ) {
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
				{ destination === 'theme'
					? __(
							'The tokens below are the ones this site doesn’t define yet. They’ll be added to the active theme’s theme.json, where the pattern itself is going, with the values from the pattern’s source site. Tokens your site already defines keep your values.',
							'pattern-builder'
					  )
					: __(
							'The tokens below are the ones this site doesn’t define yet. They’ll be added to your site styles (Global Styles), where the pattern itself is going, with the values from the pattern’s source site — revertable in the editor. Tokens your site already defines keep your values.',
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
					onClick={ onConfirm }
				>
					{ __( 'Add tokens & download', 'pattern-builder' ) }
				</Button>
			</HStack>
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
	const [ pendingDownload, setPendingDownload ] = useState( null );
	const [ pendingDestination, setPendingDestination ] = useState( null );
	const [ awaitingUpgrade, setAwaitingUpgrade ] = useState( false );

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

	/*
	 * Checkout happens on Freemius, in another tab, and the licence reaches
	 * this account by a webhook to the service — so nothing about paying
	 * passes through this screen, and without watching for it the panel
	 * still says "Free" until the page is reloaded.
	 *
	 * Two watchers, because the timing is not ours: a bounded poll after the
	 * upgrade link is opened (the webhook lands a moment after the payment,
	 * not with it), and a re-check whenever this tab is looked at again,
	 * which is what catches somebody who took their time.
	 */
	useEffect( () => {
		if ( ! awaitingUpgrade ) {
			return undefined;
		}

		let elapsed = 0;

		const timer = setInterval( async () => {
			elapsed += UPGRADE_POLL_INTERVAL;

			const data = await refreshStatus();

			if ( data?.tier === 'pro' ) {
				setAwaitingUpgrade( false );
				createSuccessNotice(
					__( 'Pattern Builder Pro is active.', 'pattern-builder' ),
					{ type: 'snackbar' }
				);
			} else if ( elapsed >= UPGRADE_POLL_TIMEOUT ) {
				// Stop guessing. The tab-focus check below still catches it,
				// and so does the next visit.
				setAwaitingUpgrade( false );
			}
		}, UPGRADE_POLL_INTERVAL );

		return () => clearInterval( timer );
	}, [ awaitingUpgrade, refreshStatus, createSuccessNotice ] );

	useEffect( () => {
		const recheck = () => {
			if ( ! document.hidden ) {
				refreshStatus();
			}
		};

		document.addEventListener( 'visibilitychange', recheck );
		return () =>
			document.removeEventListener( 'visibilitychange', recheck );
	}, [ refreshStatus ] );

	const awaitUpgrade = useCallback( () => setAwaitingUpgrade( true ), [] );

	const loadItems = useCallback( () => {
		if ( ! status?.connected && isLibrary ) {
			return;
		}
		setItems( null );
		const query = new URLSearchParams( { page: String( page ) } );
		if ( search ) {
			query.set( 'search', search );
		}
		if ( collection ) {
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

	// Save asks for a destination first; the rest of the flow is unchanged.
	const requestDownload = ( pattern ) => setPendingDestination( pattern );

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

	// `addTokens` carries the answer to the tokens modal; the tokens follow
	// the pattern to `destination`, which the server decides for itself.
	const performDownload = ( pattern, destination, addTokens = false ) => {
		setBusy( true );
		apiFetch( {
			path: `${ BASE }/download`,
			method: 'POST',
			data: {
				source: view === CLOUD_DIRECTORY ? 'directory' : 'library',
				cloudId: pattern.id,
				destination,
				addTokens,
				// Whose the cloud copy is, as the service reported it: what
				// decides whether this site is later offered an update for
				// it. The service checks again when one is attempted.
				mine: !! pattern.mine,
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
							destination === 'theme'
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

	if ( ! status ) {
		return (
			<main className="pattern-builder-browser__main">
				<div className="pattern-builder-cloud__loading">
					<Spinner />
				</div>
			</main>
		);
	}

	// The directory browses anonymously; the library needs an account.
	if ( ! status.connected && isLibrary ) {
		return (
			<main className="pattern-builder-browser__main">
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
			</main>
		);
	}

	const tokensModal = pendingDownload && (
		<TokensModal
			missing={ pendingDownload.missing }
			destination={ pendingDownload.destination }
			busy={ busy }
			onConfirm={ () =>
				performDownload(
					pendingDownload.pattern,
					pendingDownload.destination,
					true
				)
			}
			onClose={ () => setPendingDownload( null ) }
		/>
	);

	const usage = status.usage;

	return (
		<>
			<main className="pattern-builder-browser__main pattern-builder-cloud">
				{ isLibrary && status.connected && (
					<HStack
						alignment="left"
						spacing={ 2 }
						className="pattern-builder-cloud__account"
					>
						<span>
							{ sprintf(
								/* translators: 1: account name, 2: tier. */
								__(
									'Connected as %1$s (%2$s)',
									'pattern-builder'
								),
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
						{ usage && usage.cap === -1 && (
							<span className="pattern-builder-cloud__meta">
								{ sprintf(
									/* translators: 1: stored count, 2: AI credits left. */
									__(
										'%1$d patterns stored · %2$d AI credits left',
										'pattern-builder'
									),
									usage.stored,
									usage.ai_credits ?? 0
								) }
							</span>
						) }

						{ status.tier !== 'pro' && status.upgradeUrl && (
							<Button
								variant="primary"
								size="small"
								href={ status.upgradeUrl }
								target="_blank"
								rel="noreferrer"
								onClick={ awaitUpgrade }
							>
								{ __( 'Go Pro', 'pattern-builder' ) }
							</Button>
						) }

						{ status.tier === 'pro' && status.portalUrl && (
							<Button
								variant="tertiary"
								size="small"
								href={ status.portalUrl }
								target="_blank"
								rel="noreferrer"
							>
								{ __( 'Manage billing', 'pattern-builder' ) }
							</Button>
						) }

						{ awaitingUpgrade && (
							<span className="pattern-builder-cloud__meta">
								<Spinner />
								{ __(
									'Waiting for your purchase to land…',
									'pattern-builder'
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

				{ pendingDestination && (
					<DestinationModal
						pattern={ pendingDestination }
						busy={ busy }
						onConfirm={ ( destination ) => {
							const pattern = pendingDestination;
							setPendingDestination( null );
							download( pattern, destination );
						} }
						onClose={ () => setPendingDestination( null ) }
					/>
				) }

				{ tokensModal }
			</main>

			<aside className="pattern-builder-browser__details">
				{ selected ? (
					<CloudDetails
						pattern={ selected }
						source={ isLibrary ? 'library' : 'directory' }
						onDownload={ requestDownload }
						onDelete={ deleteCloudPattern }
						onEditLocal={ onEditLocal }
						busy={ busy }
					/>
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
