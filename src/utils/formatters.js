import { parse } from '@wordpress/blocks';

export function validateBlockMarkup( blockMarkup ) {
	try {
		const result = parse( blockMarkup );
		if ( result.length === 0 ) {
			return false;
		}
		for ( const block of result ) {
			if ( ! validateBlock( block ) ) {
				return false;
			}
		}
	} catch ( e ) {
		return false;
	}
	return true;
}

function validateBlock( block ) {
	if ( ! block || ! block.isValid ) {
		return false;
	}
	for ( const childBlock of block.innerBlocks ) {
		if ( ! validateBlock( childBlock ) ) {
			return false;
		}
	}
	return true;
}

export function formatBlockMarkup( blockMarkup ) {
	return indentBlockMarkup( addNewLinesToBlockMarkup( blockMarkup ) ).trim();
}

function addNewLinesToBlockMarkup( blockMarkup ) {
	return (
		blockMarkup

			// Add newlines before and after each comment
			.replace(
				/<!--(.*?)-->/gs,
				( _, content ) => `\n<!-- ${ content.trim() } -->\n`
			)

			.replace( /\/ -->/g, '/-->' )

			// Normalize multiple newlines into a single one
			.replace( /\n{2,}/g, '\n' )
	);
}

function indentBlockMarkup( blockMarkup ) {
	const lines = blockMarkup.split( '\n' ).map( ( line ) => line.trim() );
	const indentStr = '  ';
	let indentLevel = 0;
	const output = [];

	for ( let line of lines ) {
		// Detect closing tags/comments (should reduce indent before rendering)
		const isClosingComment = /^<!--\s*\/[\w:-]+\s*-->$/.test( line );
		const isClosingTag = /^<\/[\w:-]+>$/.test( line );

		if ( isClosingComment || isClosingTag ) {
			indentLevel = Math.max( indentLevel - 1, 0 );
		}

		output.push( indentStr.repeat( indentLevel ) + line );

		// Detect opening comment (not self-closing)
		const isOpeningComment =
			/^<!--\s*[\w:-]+\b.*-->$/.test( line ) &&
			! line.endsWith( '/ -->' );

		// Detect opening tag (not self-closing)
		const isOpeningTag = /^<([\w:-]+)(\s[^>]*)?>$/.test( line );

		// Self-closing HTML tag
		const isSelfClosingTag = /^<[^>]+\/>$/.test( line );

		// Self-closing block markup
		const isSelfClosingComment = /^<!--.*\/\s*-->$/.test( line );

		if (
			( isOpeningComment || isOpeningTag ) &&
			! isSelfClosingTag &&
			! isSelfClosingComment
		) {
			indentLevel++;
		}
	}
	return output.join( '\n' );
}

function formatBlockCommentJSON( blockMarkup ) {
	return blockMarkup.replace( /<!--(.*?)-->/gs, ( _, rawContent ) => {
		let content = rawContent.trim();

		const isSelfClosing = content.endsWith( '/' );
		if ( isSelfClosing ) {
			content = content.slice( 0, -1 ).trim();
		}

		const match = content.match( /^([\w:-]+)\s+({.*})$/s );
		if ( match ) {
			const blockName = match[ 1 ];
			const jsonPart = match[ 2 ];

			try {
				const parsed = JSON.parse( jsonPart );
				const keys = Object.keys( parsed );

				let formattedJson;
				if ( keys.length === 1 ) {
					formattedJson = JSON.stringify( parsed );
				} else {
					formattedJson = JSON.stringify( parsed, null, 2 );
				}

				const slash = isSelfClosing ? ' /' : '';
				return `<!-- ${ blockName } ${ formattedJson }${ slash } -->`;
			} catch ( e ) {
				// Fail silently: keep comment as-is
			}
		}

		return `<!-- ${ content } -->`;
	} );
}
