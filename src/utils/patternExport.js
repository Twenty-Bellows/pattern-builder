/**
 * The two files a pattern can be handed to the browser as, shared by every panel that offers
 * them.
 */

/**
 * Hand the browser a file without a round trip to the server.
 *
 * @param {string} filename Download filename.
 * @param {string} contents File contents.
 * @param {string} mime     MIME type.
 */
export function downloadFile( filename, contents, mime ) {
	const url = window.URL.createObjectURL(
		new window.Blob( [ contents ], { type: mime } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.URL.revokeObjectURL( url );
}

/**
 * Download a pattern as the JSON the create-from-JSON flows read.
 *
 * @param {Object} pattern The pattern's fields, with a bare `slug`.
 */
export function downloadPatternJson( pattern ) {
	downloadFile(
		`${ pattern.slug || 'pattern' }.json`,
		JSON.stringify(
			{
				title: pattern.title,
				slug: pattern.slug,
				description: pattern.description || '',
				categories: pattern.categories || [],
				keywords: pattern.keywords || [],
				viewportWidth: pattern.viewportWidth || undefined,
				synced: !! pattern.synced,
				content: pattern.content,
			},
			null,
			2
		),
		'application/json'
	);
}

/**
 * Download a pattern's block markup on its own.
 *
 * @param {Object} pattern The pattern's fields, with a bare `slug`.
 */
export function downloadPatternMarkup( pattern ) {
	downloadFile(
		`${ pattern.slug || 'pattern' }.html`,
		pattern.content,
		'text/html'
	);
}
