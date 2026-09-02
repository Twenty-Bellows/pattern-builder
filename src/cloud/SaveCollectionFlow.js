import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

import { TokensList } from './CloudBrowser';
import { planInstall, summarizeInstall, unionTokens } from './collections';

const BASE = '/pattern-builder/v1/cloud';

/**
 * Save a whole collection to this site: one destination choice, one
 * design-tokens step for the union of what every pattern needs, then the
 * patterns one after another with "3 of 12" progress — the ones already
 * installed from this collection skipped, the failures listed at the end
 * with the rest installed. A premium collection on a free account gets the
 * Pro prompt before any of it.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.collection  The collection summary.
 * @param {Array}    props.patterns    Its pattern summaries, `installed` marked.
 * @param {Object}   props.status      The /cloud/status payload.
 * @param {Function} props.onGoPro     Opens the upgrade.
 * @param {Function} props.onClose     Closes the flow.
 * @param {Function} props.onInstalled Called once anything landed.
 */
export function SaveCollectionFlow( {
	collection,
	patterns,
	status,
	onGoPro,
	onClose,
	onInstalled,
} ) {
	const locked =
		collection.visibility === 'premium' && status?.tier !== 'pro';
	const [ step, setStep ] = useState( locked ? 'locked' : 'destination' );
	const [ destination, setDestination ] = useState( 'user' );
	const [ missing, setMissing ] = useState( [] );
	const [ addTokens, setAddTokens ] = useState( false );
	const [ results, setResults ] = useState( [] );
	const [ current, setCurrent ] = useState( 0 );
	const cancelled = useRef( false );

	const plan = planInstall( patterns, collection );

	useEffect( () => {
		return () => {
			cancelled.current = true;
		};
	}, [] );

	// Which tokens the site lacks across the whole collection: one check.
	const chooseDestination = ( where ) => {
		setDestination( where );
		const tokens = unionTokens( plan.toInstall );
		if ( ! tokens.length ) {
			setStep( 'progress' );
			return;
		}
		setStep( 'checking' );
		apiFetch( {
			path: `${ BASE }/tokens/check`,
			method: 'POST',
			data: { tokens },
		} )
			.then( ( check ) => {
				if ( check.missing?.length ) {
					setMissing( check.missing );
					setStep( 'tokens' );
				} else {
					setStep( 'progress' );
				}
			} )
			.catch( () => setStep( 'progress' ) );
	};

	// The downloads, one after another, never stopping on a failure.
	useEffect( () => {
		if ( step !== 'progress' ) {
			return;
		}
		let index = 0;
		const collected = plan.skipped.map( ( pattern ) => ( {
			pattern,
			status: 'skipped',
		} ) );

		const next = () => {
			if ( cancelled.current ) {
				return;
			}
			if ( index >= plan.toInstall.length ) {
				setResults( collected );
				setStep( 'done' );
				if ( collected.some( ( r ) => r.status === 'installed' ) ) {
					onInstalled?.();
				}
				return;
			}
			const pattern = plan.toInstall[ index ];
			setCurrent( index + 1 );
			apiFetch( {
				path: `${ BASE }/download`,
				method: 'POST',
				data: {
					source: 'directory',
					cloudId: pattern.id,
					destination,
					addTokens,
					mine: !! pattern.mine,
					collection: {
						owner: collection.owner,
						slug: collection.slug,
						title: collection.title,
					},
				},
			} )
				.then( () =>
					collected.push( { pattern, status: 'installed' } )
				)
				.catch( ( error ) =>
					collected.push( {
						pattern,
						status: 'failed',
						message:
							error.message ||
							__( 'Could not be installed.', 'pattern-builder' ),
					} )
				)
				.finally( () => {
					index++;
					next();
				} );
		};
		next();
		// The run starts once, when the step turns to progress.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ step ] );

	const summary = summarizeInstall( results );
	const total = plan.toInstall.length;

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: collection title. */
				__( 'Save “%s” to this site', 'pattern-builder' ),
				collection.title
			) }
			onRequestClose={ onClose }
			isDismissible={ step !== 'progress' && step !== 'checking' }
			shouldCloseOnClickOutside={ false }
			className="pattern-builder-cloud__destination-modal"
		>
			{ step === 'locked' && (
				<VStack spacing={ 3 }>
					<p className="pattern-builder-cloud__meta">
						{ __(
							'This is a premium collection. Pattern Builder Pro accounts can save it — every pattern in it, or any one of them.',
							'pattern-builder'
						) }
					</p>
					<HStack spacing={ 2 } alignment="left">
						{ onGoPro && (
							<Button variant="primary" onClick={ onGoPro }>
								{ __( 'Go Pro', 'pattern-builder' ) }
							</Button>
						) }
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Not now', 'pattern-builder' ) }
						</Button>
					</HStack>
				</VStack>
			) }

			{ step === 'destination' && (
				<VStack spacing={ 3 }>
					<p className="pattern-builder-cloud__meta">
						{ sprintf(
							/* translators: 1: patterns to install, 2: patterns already here. */
							_n(
								'%1$d pattern will be saved; %2$d already installed from this collection will be skipped.',
								'%1$d patterns will be saved; %2$d already installed from this collection will be skipped.',
								total,
								'pattern-builder'
							),
							total,
							plan.skipped.length
						) }{ ' ' }
						{ __(
							'User patterns live in this site’s database; theme patterns are written into the active theme as files.',
							'pattern-builder'
						) }
					</p>
					<HStack spacing={ 3 } alignment="left" wrap>
						<Button
							variant="primary"
							disabled={ ! total }
							onClick={ () => chooseDestination( 'user' ) }
						>
							{ __( 'User', 'pattern-builder' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ ! total }
							onClick={ () => chooseDestination( 'theme' ) }
						>
							{ __( 'Theme', 'pattern-builder' ) }
						</Button>
					</HStack>
				</VStack>
			) }

			{ step === 'checking' && <Spinner /> }

			{ step === 'tokens' && (
				<VStack spacing={ 3 }>
					<p className="pattern-builder-cloud__meta">
						{ destination === 'theme'
							? __(
									'Across the collection, these design tokens are ones this site doesn’t define yet. They’ll be added to the active theme’s theme.json, where the patterns are going. Tokens your site already defines keep your values.',
									'pattern-builder'
							  )
							: __(
									'Across the collection, these design tokens are ones this site doesn’t define yet. They’ll be added to your site styles (Global Styles), where the patterns are going — revertable in the editor. Tokens your site already defines keep your values.',
									'pattern-builder'
							  ) }
					</p>
					<TokensList missing={ missing } />
					<HStack alignment="right" spacing={ 2 }>
						<Button
							variant="tertiary"
							onClick={ () => {
								setAddTokens( false );
								setStep( 'progress' );
							} }
						>
							{ __( 'Save without tokens', 'pattern-builder' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => {
								setAddTokens( true );
								setStep( 'progress' );
							} }
						>
							{ __( 'Add tokens & save', 'pattern-builder' ) }
						</Button>
					</HStack>
				</VStack>
			) }

			{ step === 'progress' && (
				<VStack spacing={ 3 } alignment="center">
					<Spinner />
					<p className="pattern-builder-cloud__progress">
						{ sprintf(
							/* translators: 1: current pattern number, 2: total. */
							__( 'Saving %1$d of %2$d…', 'pattern-builder' ),
							current,
							total
						) }
					</p>
				</VStack>
			) }

			{ step === 'done' && (
				<VStack spacing={ 3 }>
					<Notice
						status={ summary.failed.length ? 'warning' : 'success' }
						isDismissible={ false }
					>
						{ sprintf(
							/* translators: 1: installed count, 2: skipped count, 3: failed count. */
							__(
								'%1$d saved, %2$d already here, %3$d could not be saved.',
								'pattern-builder'
							),
							summary.installed,
							summary.skipped,
							summary.failed.length
						) }
					</Notice>
					{ summary.failed.length > 0 && (
						<ul className="pattern-builder-cloud__failures">
							{ summary.failed.map( ( failure ) => (
								<li key={ failure.title }>
									<strong>{ failure.title }</strong>
									{ failure.message
										? ` — ${ failure.message }`
										: '' }
								</li>
							) ) }
						</ul>
					) }
					<HStack alignment="right">
						<Button variant="primary" onClick={ onClose }>
							{ __( 'Done', 'pattern-builder' ) }
						</Button>
					</HStack>
				</VStack>
			) }
		</Modal>
	);
}
