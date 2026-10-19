/**
 * Tests for the key and the message that connect an agent.
 */

import { basicAuthorization, connectMessage } from '../admin/agentConnect';

describe( 'basicAuthorization', () => {
	it( 'encodes login and password as RFC 7617 does', () => {
		expect( basicAuthorization( 'Aladdin', 'open sesame' ) ).toBe(
			'Basic QWxhZGRpbjpvcGVuIHNlc2FtZQ=='
		);
	} );

	it( 'encodes a login outside ASCII as UTF-8', () => {
		expect( basicAuthorization( 'josé', 'pw' ) ).toBe(
			`Basic ${ Buffer.from( 'josé:pw', 'utf8' ).toString( 'base64' ) }`
		);
	} );
} );

describe( 'connectMessage', () => {
	const connection = {
		siteName: 'Kiln & Co',
		serverName: 'example-com',
		endpoint: 'https://example.com/wp-json/pattern-builder/v1/mcp',
		authorization: 'Basic YWRtaW46c2VjcmV0',
	};
	const message = connectMessage( connection );

	it( 'names the site and the server', () => {
		expect( message ).toContain( '"Kiln & Co"' );
		expect( message ).toContain( 'named "example-com"' );
	} );

	it( 'gives the command, with the endpoint and the header', () => {
		expect( message ).toContain(
			'claude mcp add --transport http --scope local example-com https://example.com/wp-json/pattern-builder/v1/mcp --header "Authorization: Basic YWRtaW46c2VjcmV0"'
		);
	} );

	/**
	 * The key is a password for the site, so it is never offered a place in the project:
	 * there is no file to write, and Claude asks before falling back to one.
	 */
	it( 'keeps the password out of the project folder', () => {
		expect( message ).toContain( 'Use exactly that scope.' );
		expect( message ).toContain(
			'ask me before saving the password in any file in this folder'
		);
		expect( message ).not.toContain( '.mcp.json' );
		expect( message ).not.toContain( '{' );
	} );

	it( 'asks Claude to say where it was saved and how to disconnect', () => {
		expect( message ).toContain(
			"for this folder only, in Claude Code's own settings, not in the project"
		);
		expect( message ).toContain( 'revoking it on the Agents tab' );
	} );

	it( 'leaves the site name out when there is none', () => {
		expect( connectMessage( { ...connection, siteName: '' } ) ).toMatch(
			/^Please connect this project to my WordPress site so/
		);
	} );
} );
