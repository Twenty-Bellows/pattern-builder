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

import { TelemetryOffer } from '../components/TelemetryPrompt';
import {
	hasDeclinedTelemetry,
	setTelemetryState,
	track,
} from '../utils/telemetry';
import { needsNewerWordPress } from './collections';
import { openCheckout } from './checkout';
import { CommunityTab } from './CommunityTab';
import { UploadedTab } from './UploadedTab';

import './cloud.scss';

/**
 * What this site's WordPress can render.
 */
const SITE_WORDPRESS = window.patternBuilderAdmin?.wordPressVersion || '';

const BASE = '/pattern-builder/v1/cloud';
const UPGRADE_POLL_INTERVAL = 5000;
const UPGRADE_POLL_TIMEOUT = 3 * 60 * 1000;

export const CLOUD_LIBRARY = 'cloud-library';
export const CLOUD_DIRECTORY = 'cloud-directory';

/**
 * The password rule, as the service enforces it: eight characters with an upper-case
 * letter, a digit and a symbol.
 *
 * @param {string} password Candidate password.
 * @return {string} What is missing, or '' when it passes.
 */
export function passwordProblem( password ) {
	const missing = [];
	if ( password.length < 8 ) {
		missing.push( __( 'at least 8 characters', 'pattern-builder' ) );
	}
	if ( ! /\p{Lu}/u.test( password ) ) {
		missing.push( __( 'an upper-case letter', 'pattern-builder' ) );
	}
	if ( ! /\d/.test( password ) ) {
		missing.push( __( 'a number', 'pattern-builder' ) );
	}
	if ( ! /[^\p{L}\p{N}\s]/u.test( password ) ) {
		missing.push( __( 'a symbol', 'pattern-builder' ) );
	}
	return missing.length
		? sprintf(
				/* translators: %s: comma-separated list of what the password lacks. */
				__( 'Your password needs %s.', 'pattern-builder' ),
				missing.join( ', ' )
		  )
		: '';
}

/**
 * The handle rule, as the service enforces it: three to thirty-two characters of lower-case
 * letters, numbers and single hyphens, starting with a letter.
 *
 * @param {string} handle Candidate handle.
 * @return {string} What is wrong, or '' when it passes.
 */
export function handleProblem( handle ) {
	const value = String( handle || '' ).trim();

	if ( value.length < 3 || value.length > 32 ) {
		return __(
			'Your handle is between 3 and 32 characters.',
			'pattern-builder'
		);
	}
	if ( ! /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test( value ) ) {
		return __(
			'Your handle uses lower-case letters, numbers and single hyphens, and starts with a letter.',
			'pattern-builder'
		);
	}

	return '';
}

const HANDLE_RULE = __(
	'Lower-case letters, numbers and hyphens. This is the first part of the name of every pattern you share, and it cannot be changed later.',
	'pattern-builder'
);

const PASSWORD_RULE = __(
	'At least 8 characters, with an upper-case letter, a number and a symbol.',
	'pattern-builder'
);

/**
 * Sign in / create an account / start a password reset without leaving wp-admin;
 * credentials relay through this site's proxy, which stores only the returned token.
 *
 * @param {Object}   props             Component props.
 * @param {Function} props.onConnected Receives the fresh status payload.
 * @param {string}   props.intro       Why to connect, for this tab.
 * @param {string}   props.title       The title for the panel.
 */
function ConnectPanel( { onConnected, intro, title } ) {
	const [ mode, setMode ] = useState( 'login' );
	const [ email, setEmail ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ name, setName ] = useState( '' );
	const [ handle, setHandle ] = useState( '' );
	const [ marketing, setMarketing ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ offerTelemetry, setOfferTelemetry ] = useState(
		hasDeclinedTelemetry()
	);

	const isSignup = mode === 'signup';
	const isForgot = mode === 'forgot';

	const switchMode = ( next ) => {
		setMode( next );
		setError( '' );
		setNotice( '' );
	};

	const canSubmit = isForgot
		? !! email
		: !! email &&
		  !! password &&
		  ( ! isSignup || ( !! handle && marketing !== null ) );

	const submit = ( event ) => {
		event.preventDefault();
		if ( busy || ! canSubmit ) {
			return;
		}

		if ( isSignup ) {
			const problem =
				handleProblem( handle ) || passwordProblem( password );
			if ( problem ) {
				setError( problem );
				return;
			}
		}

		setBusy( true );
		setError( '' );
		setNotice( '' );

		if ( isForgot ) {
			apiFetch( {
				path: `${ BASE }/password/forgot`,
				method: 'POST',
				data: { email },
			} )
				.then( ( data ) => {
					setBusy( false );
					setNotice(
						data.message ||
							__(
								'If that address has an account, a reset link is on its way.',
								'pattern-builder'
							)
					);
				} )
				.catch( ( err ) => {
					setBusy( false );
					setError(
						err.message ||
							__(
								'That did not work. Try again.',
								'pattern-builder'
							)
					);
				} );
			return;
		}

		apiFetch( {
			path: `${ BASE }/${ isSignup ? 'signup' : 'login' }`,
			method: 'POST',
			data: isSignup
				? {
						email,
						password,
						handle,
						name,
						marketing: marketing === true,
				  }
				: { email, password },
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

	title = isForgot ? __( 'Reset your password', 'pattern-builder' ) : title;

	return (
		<form className="pattern-builder-cloud__connect" onSubmit={ submit }>
			<VStack spacing={ 4 }>
				<Heading
					level={ 2 }
					size={ 18 }
					className="pattern-builder-cloud__connect-title"
				>
					{ title }
				</Heading>
				<p className="pattern-builder-cloud__connect-intro">
					{ isForgot
						? __(
								'Enter your account’s email and we’ll send a link to reset your password.',
								'pattern-builder'
						  )
						: intro }
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
				{ isSignup && (
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Handle', 'pattern-builder' ) }
						value={ handle }
						onChange={ ( next ) =>
							setHandle( next.toLowerCase().trim() )
						}
						autoComplete="username"
						help={ HANDLE_RULE }
						required
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
				{ ! isForgot && (
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Password', 'pattern-builder' ) }
						type="password"
						value={ password }
						onChange={ setPassword }
						autoComplete={
							isSignup ? 'new-password' : 'current-password'
						}
						help={ isSignup ? PASSWORD_RULE : undefined }
						required
					/>
				) }
				{ isSignup && (
					<fieldset className="pattern-builder-cloud__consent">
						<legend>
							{ __(
								'Can we email you news and offers?',
								'pattern-builder'
							) }
						</legend>
						<p className="pattern-builder-cloud__consent-hint">
							{ __(
								'We send out occasional notes about new pattern collections and features. No more than a couple a month.',
								'pattern-builder'
							) }
						</p>
						<HStack spacing={ 2 } justify="flex-start">
							<Button
								variant={
									marketing === true ? 'primary' : 'secondary'
								}
								aria-pressed={ marketing === true }
								onClick={ () => setMarketing( true ) }
							>
								{ __(
									'Yes, keep me posted',
									'pattern-builder'
								) }
							</Button>
							<Button
								variant={
									marketing === false
										? 'primary'
										: 'secondary'
								}
								aria-pressed={ marketing === false }
								onClick={ () => setMarketing( false ) }
							>
								{ __( 'No thanks', 'pattern-builder' ) }
							</Button>
						</HStack>
					</fieldset>
				) }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ notice && (
					<Notice status="success" isDismissible={ false }>
						{ notice }
					</Notice>
				) }
				<Button
					variant="primary"
					type="submit"
					icon={ isForgot ? undefined : cloudUpload }
					isBusy={ busy }
					disabled={ busy || ! canSubmit }
				>
					{ isForgot &&
						__( 'Email me a reset link', 'pattern-builder' ) }
					{ isSignup &&
						__( 'Create account & connect', 'pattern-builder' ) }
					{ ! isForgot &&
						! isSignup &&
						__( 'Sign in & connect', 'pattern-builder' ) }
				</Button>
				<VStack spacing={ 1 } alignment="center">
					{ ! isForgot && (
						<Button
							variant="link"
							onClick={ () =>
								switchMode( isSignup ? 'login' : 'signup' )
							}
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
					) }
					{ ! isSignup && (
						<Button
							variant="link"
							onClick={ () =>
								switchMode( isForgot ? 'login' : 'forgot' )
							}
						>
							{ isForgot
								? __( 'Back to sign in', 'pattern-builder' )
								: __(
										'Forgot your password?',
										'pattern-builder'
								  ) }
						</Button>
					) }
				</VStack>
				{ offerTelemetry && (
					<TelemetryOffer
						onAnswer={ () => setOfferTelemetry( false ) }
					/>
				) }
			</VStack>
		</form>
	);
}

/**
 * Disconnect, behind a prompt.
 *
 * @param {Object}   props                Component props.
 * @param {Function} props.onDisconnected Called once the token is gone.
 */
function DisconnectButton( { onDisconnected } ) {
	const [ isConfirming, setIsConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const confirm = () => {
		setError( '' );
		setIsConfirming( true );
	};

	const disconnect = () => {
		setBusy( true );
		setError( '' );
		apiFetch( { path: `${ BASE }/disconnect`, method: 'POST' } )
			.then( () => onDisconnected() )
			.catch( ( err ) => {
				setBusy( false );
				setError(
					err.message ||
						__( 'That did not work. Try again.', 'pattern-builder' )
				);
			} );
	};

	return (
		<>
			<Button variant="tertiary" icon={ closeSmall } onClick={ confirm }>
				{ __( 'Disconnect', 'pattern-builder' ) }
			</Button>
			{ isConfirming && (
				<Modal
					title={ __(
						'Disconnect from patternbuilderwp.com?',
						'pattern-builder'
					) }
					onRequestClose={ () => setIsConfirming( false ) }
					className="pattern-builder-cloud__destination-modal"
				>
					<VStack spacing={ 3 }>
						<p className="pattern-builder-cloud__meta">
							{ __(
								'Patterns already on this site stay, and so does your cloud library. Sign in again any time to upload or install.',
								'pattern-builder'
							) }
						</p>
						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						) }
						<HStack alignment="right" spacing={ 2 } wrap>
							<Button
								variant="tertiary"
								onClick={ () => setIsConfirming( false ) }
								disabled={ busy }
							>
								{ __( 'Cancel', 'pattern-builder' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								isBusy={ busy }
								disabled={ busy }
								onClick={ disconnect }
							>
								{ __( 'Disconnect', 'pattern-builder' ) }
							</Button>
						</HStack>
					</VStack>
				</Modal>
			) }
		</>
	);
}

/**
 * A cloud pattern card: the service's preview document rendered at the grid's design width
 * and scaled into the same fixed square tile the local cards use.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.pattern    Cloud pattern summary.
 * @param {boolean}  props.isSelected Whether the card is selected.
 * @param {Function} props.onSelect   Selection callback.
 */
export function CloudCard( { pattern, isSelected, onSelect } ) {
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
 * The details sidebar for a selected cloud pattern, the same shell the local sidebar uses.
 * title, kind, and the actions on top.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.pattern     Cloud pattern summary.
 * @param {string}   props.source      'library' or 'directory'.
 * @param {Function} props.onDownload  Called with the pattern to save.
 * @param {Function} props.onDelete    Called with the pattern (library only).
 * @param {Function} props.onEditLocal Called with { source, id } of the local copy.
 * @param {boolean}  props.busy        Whether an action is in flight.
 * @param {Element}  props.children    Extra panels the tab renders in the sidebar.
 */
export function CloudDetails( {
	pattern,
	source,
	onDownload,
	onDelete,
	onEditLocal,
	busy,
	children,
} ) {
	const [ installed, setInstalled ] = useState( undefined );
	const needsWordPress = needsNewerWordPress( pattern, SITE_WORDPRESS );

	useEffect( () => {
		if ( busy ) {
			return;
		}
		if ( ! pattern.namespace ) {
			setInstalled( null );
			return;
		}
		setInstalled( undefined );
		apiFetch( {
			path: addQueryArgs( `${ BASE }/pattern-state`, {
				name: pattern.namespace,
			} ),
		} )
			.then( ( data ) => setInstalled( data.installed || null ) )
			.catch( () => setInstalled( null ) );
	}, [ pattern.namespace, busy ] );

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
							disabled={ busy || !! needsWordPress }
							onClick={ () => onDownload( pattern ) }
							className="pattern-builder-details__action-button"
						>
							{ __( 'Install Pattern', 'pattern-builder' ) }
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
						{ !! needsWordPress && (
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: 1: required WordPress version, 2: this site's WordPress version. */
									__(
										'Needs WordPress %1$s or newer. This site runs %2$s, and would lose part of this pattern on import.',
										'pattern-builder'
									),
									needsWordPress,
									SITE_WORDPRESS
								) }
							</Notice>
						) }
						{ pattern.description && (
							<Text variant="muted">{ pattern.description }</Text>
						) }
						{ pattern.collection?.title && (
							<Text variant="muted" size="12px">
								{ sprintf(
									/* translators: %s: collection title. */
									__( 'Collection: %s', 'pattern-builder' ),
									pattern.collection.title
								) }
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

				{ children }

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
export function DestinationModal( { pattern, busy, onConfirm, onClose } ) {
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
 * The missing-tokens step of a download: the pattern references design tokens this site
 * doesn't define.
 *
 * @param {Object}   props             Component props.
 * @param {Array}    props.missing     Tokens the site lacks.
 * @param {string}   props.destination 'user' or 'theme' — where the pattern is going.
 * @param {boolean}  props.busy        Whether the download is in flight.
 * @param {Function} props.onConfirm   Proceed with the download.
 * @param {Function} props.onClose     Close/cancel callback.
 */
export function TokensModal( {
	missing,
	destination,
	busy,
	onConfirm,
	onClose,
} ) {
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
			<TokensList missing={ missing } />
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
 * The design tokens a download would add, listed with a swatch for colors.
 *
 * @param {Object} props         Component props.
 * @param {Array}  props.missing Tokens the site lacks.
 */
export function TokensList( { missing } ) {
	const typeLabels = {
		color: __( 'Color', 'pattern-builder' ),
		gradient: __( 'Gradient', 'pattern-builder' ),
		spacing: __( 'Spacing', 'pattern-builder' ),
		fontSize: __( 'Font size', 'pattern-builder' ),
		fontFamily: __( 'Font family', 'pattern-builder' ),
	};

	return (
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
	);
}

/**
 * The single-pattern save: a destination, then the tokens the site lacks, then the download
 * — as a hook, so the Community and Uploaded tabs share one flow and render its two modals
 * where they like.
 *
 * @param {Object}   options              Hook options.
 * @param {string}   options.source       'library' or 'directory'.
 * @param {Function} options.onDownloaded Called after a pattern lands locally.
 * @return {Object} { busy, requestDownload, modals }
 */
export function useDownloadFlow( { source, onDownloaded } ) {
	const [ busy, setBusy ] = useState( false );
	const [ pendingDownload, setPendingDownload ] = useState( null );
	const [ pendingDestination, setPendingDestination ] = useState( null );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const requestDownload = ( pattern ) => {
		const needs = needsNewerWordPress( pattern, SITE_WORDPRESS );
		if ( needs ) {
			createErrorNotice(
				sprintf(
					/* translators: 1: pattern title, 2: required WordPress version, 3: this site's WordPress version. */
					__(
						'“%1$s” needs WordPress %2$s or newer, and this site runs %3$s.',
						'pattern-builder'
					),
					pattern.title,
					needs,
					SITE_WORDPRESS
				),
				{ type: 'snackbar' }
			);
			return;
		}

		setPendingDestination( pattern );
	};
	const performDownload = ( pattern, destination, addTokens = false ) => {
		setBusy( true );
		apiFetch( {
			path: `${ BASE }/download`,
			method: 'POST',
			data: {
				source,
				cloudId: pattern.id,
				destination,
				addTokens,
				collection: pattern.collection
					? {
							owner: pattern.collection.owner,
							slug: pattern.collection.slug,
							title: pattern.collection.title,
					  }
					: null,
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

	const modals = (
		<>
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
			{ pendingDownload && (
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
			) }
		</>
	);

	return { busy, requestDownload, modals };
}

/**
 * The cloud browsing surface: connect state and the account bar, then the Uploaded tab (the
 * account's collections and patterns) or the Community tab (public collections first, then
 * patterns) — rendered in place of the local grid when a cloud tab is active.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.view          CLOUD_LIBRARY or CLOUD_DIRECTORY.
 * @param {Function} props.onDownloaded  Called after a pattern lands locally.
 * @param {Function} props.onEditLocal   Opens an installed local copy's editor.
 * @param {string}   props.search        Search term, owned by the browser chrome.
 * @param {string}   props.collection    The Uploaded tab's rail selection: a collection id,
 *                                       or '' for all.
 * @param {Function} props.onCollections Reports the Uploaded tab's collections for the
 *                                       rail.
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
	const [ awaitingUpgrade, setAwaitingUpgrade ] = useState( false );

	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const isLibrary = view === CLOUD_LIBRARY;

	const refreshStatus = useCallback( () => {
		return apiFetch( { path: `${ BASE }/status` } )
			.then( ( data ) => {
				setStatus( data );
				if ( data?.telemetry ) {
					setTelemetryState( data.telemetry );
				}
				return data;
			} )
			.catch( () => setStatus( { connected: false } ) );
	}, [] );

	useEffect( () => {
		refreshStatus();
	}, [ refreshStatus ] );
	useEffect( () => {
		track( isLibrary ? 'cloud_browsed' : 'community_browsed' );
	}, [ isLibrary ] );
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
	const goPro = () => {
		track( 'upgrade_opened' );
		if ( ! status?.checkout ) {
			window.open( status.upgradeUrl, '_blank', 'noopener' );
			awaitUpgrade();
			return;
		}
		openCheckout( status.checkout, {
			onSynced: ( next ) => {
				setStatus( next );
				if ( next?.tier === 'pro' ) {
					createSuccessNotice(
						__(
							'Pattern Builder Pro is active.',
							'pattern-builder'
						),
						{ type: 'snackbar' }
					);
				}
			},
			onClosed: () => refreshStatus(),
		} ).catch( () => {
			window.open( status.upgradeUrl, '_blank', 'noopener' );
			awaitUpgrade();
		} );
		awaitUpgrade();
	};

	const canGoPro =
		status?.tier !== 'pro' && !! ( status?.checkout || status?.upgradeUrl );

	const resendVerification = () => {
		apiFetch( { path: `${ BASE }/verify/resend`, method: 'POST' } )
			.then( ( data ) =>
				createSuccessNotice(
					data.message ||
						__(
							'A new confirmation email is on its way.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				)
			)
			.catch( ( err ) =>
				createErrorNotice(
					err.message ||
						__(
							'That did not work. Try again.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				)
			);
	};

	const disconnected = () => {
		setStatus( { connected: false } );
		onCollections?.( [] );
		createSuccessNotice(
			__( 'Disconnected from patternbuilderwp.com.', 'pattern-builder' ),
			{ type: 'snackbar' }
		);
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
	if ( ! status.connected ) {
		return (
			<main className="pattern-builder-browser__main">
				<ConnectPanel
					intro={
						isLibrary
							? __(
									'Keep a pattern library on patternbuilderwp.com: upload patterns from this site, download them anywhere, and share collections with the community. Private by default, public if you want to share.',
									'pattern-builder'
							  )
							: __(
									'Sign in to browse community collections and add them to this site. A free account you can use to keep your own patterns in the cloud too.',
									'pattern-builder'
							  )
					}
					title={
						isLibrary
							? __(
									'Take your patterns with you',
									'pattern-builder'
							  )
							: __( 'Community Collections', 'pattern-builder' )
					}
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

	const accountBar = isLibrary && (
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

			{ canGoPro && (
				<Button
					variant="primary"
					size="small"
					disabled={
						status.account && status.account.verified === false
					}
					onClick={ goPro }
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

			{ status.serviceUrl && (
				<Button
					variant="tertiary"
					href={ `${ status.serviceUrl }/account/` }
					target="_blank"
					rel="noreferrer"
				>
					{ __( 'My Account', 'pattern-builder' ) }
				</Button>
			) }

			<DisconnectButton onDisconnected={ disconnected } />
		</HStack>
	);

	const verifyNotice = status.account &&
		status.account.verified === false && (
			<Notice
				status="warning"
				isDismissible={ false }
				className="pattern-builder-cloud__verify"
				actions={ [
					{
						label: __( 'Send it again', 'pattern-builder' ),
						onClick: resendVerification,
						variant: 'link',
					},
				] }
			>
				{ __(
					'Confirm your email address to upload patterns, install from the community, and go Pro. The link is in your inbox.',
					'pattern-builder'
				) }
			</Notice>
		);

	const chrome = (
		<>
			{ accountBar }
			{ verifyNotice }
		</>
	);

	if ( isLibrary ) {
		return (
			<UploadedTab
				chrome={ chrome }
				status={ status }
				refreshStatus={ refreshStatus }
				search={ search }
				collection={ collection }
				onCollections={ onCollections }
				onDownloaded={ onDownloaded }
				onEditLocal={ onEditLocal }
				onGoPro={ canGoPro ? goPro : null }
			/>
		);
	}

	return (
		<CommunityTab
			chrome={ chrome }
			status={ status }
			search={ search }
			onDownloaded={ onDownloaded }
			onEditLocal={ onEditLocal }
			onGoPro={ canGoPro ? goPro : null }
		/>
	);
}
