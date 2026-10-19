/**
 * The parts of connecting an agent that are worked out rather than fetched: the key an
 * Application Password becomes, and the message that asks Claude to connect with it.
 */

/**
 * The value of an HTTP Basic `Authorization` header: `login:password`, UTF-8 encoded, in
 * base64.
 *
 * @param {string} login    The WordPress user's login.
 * @param {string} password The Application Password.
 * @return {string} E.g. `Basic YWRtaW46...`.
 */
export function basicAuthorization( login, password ) {
	// btoa() takes one byte per character, so the UTF-8 bytes go in as characters.
	const bytes = encodeURIComponent( `${ login }:${ password }` ).replace(
		/%([0-9A-F]{2})/g,
		( match, hex ) => String.fromCharCode( parseInt( hex, 16 ) )
	);

	return `Basic ${ btoa( bytes ) }`;
}

/**
 * The message a person pastes into Claude to connect it to this site. Claude does the
 * setup itself, so the person never handles a command or a config file.
 *
 * It is always saved for this folder only, in Claude Code's own settings (local scope):
 * the key is an administrator's password for the site, and a project folder is what gets
 * committed, synced and shared. Claude is asked to say so plainly, since the person will
 * not find it in the folder.
 *
 * @param {Object} connection               The connection.
 * @param {string} connection.siteName      The site's title.
 * @param {string} connection.serverName    The name to give the MCP server.
 * @param {string} connection.endpoint      The MCP endpoint URL.
 * @param {string} connection.authorization The `Authorization` header value.
 * @return {string} The message.
 */
export function connectMessage( {
	siteName,
	serverName,
	endpoint,
	authorization,
} ) {
	return [
		`Please connect this project to my WordPress site${
			siteName ? ` "${ siteName }"` : ''
		} so you can use its Pattern Builder tools.`,
		'',
		`Add it as an MCP server named "${ serverName }", for this folder only and saved in Claude Code's own settings rather than in the project, by running:`,
		'',
		`claude mcp add --transport http --scope local ${ serverName } ${ endpoint } --header "Authorization: ${ authorization }"`,
		'',
		'Use exactly that scope. If the claude command is not available, stop and tell me, and ask me before saving the password in any file in this folder: it is a password for my site.',
		'',
		"When it is connected, tell me in plain words where it was saved: for this folder only, in Claude Code's own settings, not in the project. Tell me I can disconnect it by revoking it on the Agents tab of Pattern Builder on my site, or by asking you to remove the server.",
		'',
		'Then tell me to start a new session so the connection loads. In that session, read the site’s authoring guide with the pattern-builder-get-authoring-guide tool before writing any pattern.',
	].join( '\n' );
}
