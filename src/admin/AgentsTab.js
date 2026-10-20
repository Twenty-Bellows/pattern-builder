import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { humanTimeDiff } from '@wordpress/date';
import { Icon, caution, check } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { basicAuthorization, connectMessage } from './agentConnect';

const PASSWORDS = '/wp/v2/users/me/application-passwords';
const ADAPTER_SLUG = 'mcp-adapter';
const ADAPTER_PLUGIN = 'mcp-adapter/mcp-adapter';

/**
 * Whether the site lets the new key through: some hosts strip the `Authorization` header
 * before WordPress sees it, and then nothing Claude sends would arrive as anyone.
 *
 * @param {string} endpoint      The MCP endpoint.
 * @param {string} authorization The `Authorization` header value.
 * @return {Promise<boolean|null>} True when accepted, false when turned away, null when the
 *                                 check could not be made.
 */
async function acceptsKey( endpoint, authorization ) {
	try {
		const response = await window.fetch( endpoint, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				Authorization: authorization,
			},
			body: JSON.stringify( {
				jsonrpc: '2.0',
				id: 1,
				method: 'initialize',
				params: {
					protocolVersion: '2025-11-25',
					capabilities: {},
					clientInfo: { name: 'pattern-builder-check', version: '1' },
				},
			} ),
		} );

		return response.ok;
	} catch {
		return null;
	}
}

/**
 * When something happened, as core reports it: GMT, without a zone.
 *
 * @param {string|null} value A date from the Application Passwords route.
 * @return {string} E.g. "2 hours ago", or "Never".
 */
function when( value ) {
	return value
		? humanTimeDiff( `${ value }Z` )
		: __( 'Never', 'pattern-builder' );
}

/**
 * One line of the readiness list.
 *
 * @param {Object}  props          Component props.
 * @param {boolean} props.ok       Whether this is in place.
 * @param {string}  props.label    What it is.
 * @param {Object}  props.children What to say about it.
 */
function Check( { ok, label, children } ) {
	return (
		<li
			className={
				'pattern-builder-agents__check' +
				( ok ? ' is-ok' : ' is-blocked' )
			}
		>
			<Icon icon={ ok ? check : caution } />
			<div>
				<strong>{ label }</strong>
				<div className="pattern-builder-agents__check-detail">
					{ children }
				</div>
			</div>
		</li>
	);
}

/**
 * The adapter's line: what is running, or the button that puts it right.
 *
 * @param {Object} props         Component props.
 * @param {Object} props.adapter The adapter's state, as the PHP side reported it.
 */
function AdapterCheck( { adapter } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const fix = () => {
		setBusy( true );
		setError( '' );

		const request =
			'inactive' === adapter.status
				? {
						path: `/wp/v2/plugins/${ ADAPTER_PLUGIN }`,
						method: 'POST',
						data: { status: 'active' },
				  }
				: {
						path: '/wp/v2/plugins',
						method: 'POST',
						data: { slug: ADAPTER_SLUG, status: 'active' },
				  };

		apiFetch( request )
			.then( () =>
				window.location.assign(
					addQueryArgs( window.location.href, { tab: 'agents' } )
				)
			)
			.catch( ( failure ) => {
				setBusy( false );
				setError(
					failure?.message ||
						__(
							'MCP Adapter could not be installed.',
							'pattern-builder'
						)
				);
			} );
	};

	if ( 'active' === adapter.status ) {
		return (
			<Check ok label={ __( 'MCP Adapter', 'pattern-builder' ) }>
				{ adapter.provider
					? sprintf(
							/* translators: 1: version number, 2: the plugin that bundles it. */
							__(
								'Version %1$s is running, bundled with %2$s.',
								'pattern-builder'
							),
							adapter.version,
							adapter.provider
					  )
					: sprintf(
							/* translators: %s: version number. */
							__( 'Version %s is running.', 'pattern-builder' ),
							adapter.version
					  ) }
			</Check>
		);
	}

	return (
		<Check ok={ false } label={ __( 'MCP Adapter', 'pattern-builder' ) }>
			<p>
				{ 'inactive' === adapter.status
					? __(
							'Installed, but not active. It is the plugin that lets Claude reach Pattern Builder’s tools.',
							'pattern-builder'
					  )
					: __(
							'Not installed. It is the plugin that lets Claude reach Pattern Builder’s tools.',
							'pattern-builder'
					  ) }
			</p>
			{ adapter.canFix ? (
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ fix }
				>
					{ 'inactive' === adapter.status
						? __( 'Activate MCP Adapter', 'pattern-builder' )
						: __(
								'Install and activate MCP Adapter',
								'pattern-builder'
						  ) }
				</Button>
			) : (
				<p>
					{ __(
						'Ask an administrator who can install plugins to add MCP Adapter from WordPress.org.',
						'pattern-builder'
					) }
				</p>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</Check>
	);
}

/**
 * The message for a connection just created, shown once: the password inside it cannot be
 * read back.
 *
 * @param {Object}   props         Component props.
 * @param {Object}   props.created The message, and whether the site accepted its key.
 * @param {Function} props.onDone  Called when the person is finished with it.
 */
function CreatedConnection( { created, onDone } ) {
	const [ copied, setCopied ] = useState( false );
	const copyRef = useCopyToClipboard( created.message, () => {
		setCopied( true );
		window.setTimeout( () => setCopied( false ), 2000 );
	} );

	return (
		<div className="pattern-builder-agents__created">
			<p>
				<strong>
					{ __(
						'Paste this message into Claude.',
						'pattern-builder'
					) }
				</strong>{ ' ' }
				{ __(
					'Open Claude Code in the folder you use for this site and paste it in. Claude saves the connection for that folder only, in its own settings rather than in your project, and tells you to start a new session. The message holds a password for this site, and it will not be shown again.',
					'pattern-builder'
				) }
			</p>
			<textarea
				className="pattern-builder-agents__message"
				readOnly
				rows={ 12 }
				value={ created.message }
				onFocus={ ( event ) => event.target.select() }
				aria-label={ __( 'Message for Claude', 'pattern-builder' ) }
			/>
			<div className="pattern-builder-agents__created-actions">
				<Button variant="primary" ref={ copyRef }>
					{ copied
						? __( 'Copied', 'pattern-builder' )
						: __( 'Copy message', 'pattern-builder' ) }
				</Button>
				<Button variant="tertiary" onClick={ onDone }>
					{ __( 'Done', 'pattern-builder' ) }
				</Button>
			</div>
			{ 'pending' === created.accepted && (
				<p className="pattern-builder-agents__accepted">
					<Spinner />
					{ __(
						'Checking that this site accepts the connection…',
						'pattern-builder'
					) }
				</p>
			) }
			{ true === created.accepted && (
				<p className="pattern-builder-agents__accepted is-ok">
					<Icon icon={ check } />
					{ __(
						'This site accepts the connection.',
						'pattern-builder'
					) }
				</p>
			) }
			{ false === created.accepted && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This site turned the connection away. Some hosts remove the Authorization header before WordPress sees it; ask your host to pass it through to WordPress.',
						'pattern-builder'
					) }
				</Notice>
			) }
		</div>
	);
}

/**
 * The Agents tab: what connecting Claude to this site needs, a way to create the connection
 * as a message to paste into Claude, and the connections made so far.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.agents The state the PHP side reported for connecting an agent.
 */
export function AgentsTab( { agents } ) {
	const {
		appId,
		endpoint,
		serverName,
		siteName,
		login,
		abilities,
		adapter,
		appPasswords,
	} = agents;
	const canConnect =
		abilities &&
		'active' === adapter.status &&
		'available' === appPasswords;

	const [ name, setName ] = useState( __( 'Claude', 'pattern-builder' ) );
	const [ creating, setCreating ] = useState( false );
	const [ created, setCreated ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ connections, setConnections ] = useState( null );

	const refreshConnections = useCallback( () => {
		if ( 'available' !== appPasswords ) {
			setConnections( [] );
			return;
		}

		apiFetch( { path: PASSWORDS } )
			.then( ( items ) =>
				setConnections(
					items.filter( ( item ) => item.app_id === appId )
				)
			)
			.catch( () => setConnections( [] ) );
	}, [ appId, appPasswords ] );

	useEffect( refreshConnections, [ refreshConnections ] );

	const create = async () => {
		setCreating( true );
		setError( '' );

		try {
			const item = await apiFetch( {
				path: PASSWORDS,
				method: 'POST',
				data: { name, app_id: appId },
			} );
			const authorization = basicAuthorization( login, item.password );

			setCreated( {
				message: connectMessage( {
					siteName,
					serverName,
					endpoint,
					authorization,
				} ),
				accepted: 'pending',
			} );
			refreshConnections();

			const accepted = await acceptsKey( endpoint, authorization );
			setCreated( ( current ) =>
				current ? { ...current, accepted } : current
			);
		} catch ( failure ) {
			setError(
				failure?.message ||
					__(
						'The connection could not be created.',
						'pattern-builder'
					)
			);
		} finally {
			setCreating( false );
		}
	};

	const revoke = ( connection ) => {
		// eslint-disable-next-line no-alert
		const sure = window.confirm(
			sprintf(
				/* translators: %s: the connection's name. */
				__(
					'Revoke “%s”? Claude will no longer reach this site through it.',
					'pattern-builder'
				),
				connection.name
			)
		);

		if ( ! sure ) {
			return;
		}

		apiFetch( {
			path: `${ PASSWORDS }/${ connection.uuid }`,
			method: 'DELETE',
		} )
			.then( refreshConnections )
			.catch( ( failure ) => setError( failure?.message || '' ) );
	};

	return (
		<div className="pattern-builder-agents">
			<h2>{ __( 'Connect an agent', 'pattern-builder' ) }</h2>
			<p>
				{ __(
					'Connect Claude to this site and it can read your design system and patterns, and create and edit patterns, through Pattern Builder’s tools. Each connection is a password only Claude uses, and you can revoke it at any time.',
					'pattern-builder'
				) }
			</p>

			<h3>{ __( 'Before you start', 'pattern-builder' ) }</h3>
			<ul className="pattern-builder-agents__checks">
				<Check
					ok={ abilities }
					label={ __( 'Pattern Builder’s tools', 'pattern-builder' ) }
				>
					{ abilities
						? __( 'Ready.', 'pattern-builder' )
						: __(
								'These need WordPress 6.9 or later.',
								'pattern-builder'
						  ) }
				</Check>
				<AdapterCheck adapter={ adapter } />
				<Check
					ok={ 'available' === appPasswords }
					label={ __( 'Application Passwords', 'pattern-builder' ) }
				>
					{ 'available' === appPasswords &&
						__( 'Available for your account.', 'pattern-builder' ) }
					{ 'insecure' === appPasswords &&
						__(
							'WordPress only offers these on sites served over https. Move the site to https; local development sites are exempt.',
							'pattern-builder'
						) }
					{ 'disabled' === appPasswords &&
						__(
							'Turned off on this site, or for your account.',
							'pattern-builder'
						) }
				</Check>
			</ul>

			<h3>{ __( 'Create a connection', 'pattern-builder' ) }</h3>
			{ created ? (
				<CreatedConnection
					created={ created }
					onDone={ () => setCreated( null ) }
				/>
			) : (
				<div className="pattern-builder-agents__create">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Connection name', 'pattern-builder' ) }
						help={ __(
							'Name it after where Claude runs, so you can tell your connections apart later.',
							'pattern-builder'
						) }
						value={ name }
						onChange={ setName }
						disabled={ ! canConnect }
					/>
					<Button
						variant="primary"
						isBusy={ creating }
						disabled={ ! canConnect || creating || ! name.trim() }
						onClick={ create }
					>
						{ __( 'Create connection', 'pattern-builder' ) }
					</Button>
					{ ! canConnect && (
						<p className="pattern-builder-agents__note">
							{ __(
								'Finish the steps above first.',
								'pattern-builder'
							) }
						</p>
					) }
				</div>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<h3>{ __( 'Your connections', 'pattern-builder' ) }</h3>
			{ null === connections && <Spinner /> }
			{ connections && 0 === connections.length && (
				<p>{ __( 'No connections yet.', 'pattern-builder' ) }</p>
			) }
			{ connections && connections.length > 0 && (
				<table className="widefat striped pattern-builder-agents__connections">
					<thead>
						<tr>
							<th>{ __( 'Name', 'pattern-builder' ) }</th>
							<th>{ __( 'Created', 'pattern-builder' ) }</th>
							<th>{ __( 'Last used', 'pattern-builder' ) }</th>
							<th>
								<span className="screen-reader-text">
									{ __( 'Actions', 'pattern-builder' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ connections.map( ( connection ) => (
							<tr key={ connection.uuid }>
								<td>{ connection.name }</td>
								<td>{ when( connection.created ) }</td>
								<td>{ when( connection.last_used ) }</td>
								<td>
									<Button
										variant="link"
										isDestructive
										onClick={ () => revoke( connection ) }
									>
										{ __( 'Revoke', 'pattern-builder' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<h3>{ __( 'Skills', 'pattern-builder' ) }</h3>
			<p className="pattern-builder-agents__note">
				{ __(
					'Claude reads Pattern Builder’s authoring guides through the get-authoring-guide tool. To keep a copy with your project, ask it to install either skill from this site:',
					'pattern-builder'
				) }
			</p>
			<ul className="pattern-builder-agents__skills">
				<li>
					<code>pattern-author</code>
					{ ' — ' }
					{ __(
						'writing block patterns that use the theme’s design tokens and check as valid.',
						'pattern-builder'
					) }
				</li>
				<li>
					<code>design-reproduction</code>
					{ ' — ' }
					{ __(
						'rebuilding a design that already exists, such as a website, a screenshot or a Figma file. Install it alongside pattern-author.',
						'pattern-builder'
					) }
				</li>
			</ul>
		</div>
	);
}
