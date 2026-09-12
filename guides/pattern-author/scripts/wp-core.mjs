/**
 * Load a WordPress install's own block editor code into Node.
 *
 * @package
 */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );

/**
 * Handles that are not packages, and where their files live.
 */
const VENDOR = {
	react: 'js/dist/vendor/react.min.js',
	'react-dom': 'js/dist/vendor/react-dom.min.js',
	'react-jsx-runtime': 'js/dist/vendor/react-jsx-runtime.min.js',
	moment: 'js/dist/vendor/moment.min.js',
	lodash: 'js/dist/vendor/lodash.min.js',
	'wp-polyfill': 'js/dist/vendor/wp-polyfill.min.js',
};

/**
 * What the vendor handles need, which the manifest does not record because they are not
 * packages.
 */
const VENDOR_DEPS = {
	'react-dom': [ 'react' ],
	'react-jsx-runtime': [ 'react' ],
};

/**
 * Declare the `content` attribute on `core/pattern`, as the site does.
 *
 * @param {Object} settings Block type settings.
 * @param {string} name     Block type name.
 * @return {Object} Filtered settings.
 */
export function addPatternContentAttribute( settings, name ) {
	if ( name !== 'core/pattern' ) {
		return settings;
	}

	return {
		...settings,
		attributes: {
			...settings.attributes,
			content: { type: 'object' },
		},
		providesContext: {
			...settings.providesContext,
			'pattern/overrides': 'content',
		},
	};
}

/**
 * Find a WordPress install.
 *
 * @param {string} [hint] A path to try before searching.
 * @return {string|null} Absolute path to the WordPress root.
 */
export function findWordPress( hint ) {
	const looksRight = ( dir ) =>
		dir &&
		fs.existsSync( path.join( dir, 'wp-includes/js/dist/blocks.min.js' ) );

	for ( const candidate of [ hint, process.env.WP_PATH ].filter( Boolean ) ) {
		const resolved = path.resolve( candidate );
		if ( looksRight( resolved ) ) {
			return resolved;
		}
		for ( const up of ancestors( resolved ) ) {
			if ( looksRight( up ) ) {
				return up;
			}
		}
	}
	for ( const up of ancestors( process.cwd() ) ) {
		if ( looksRight( up ) ) {
			return up;
		}
	}
	for ( const up of ancestors(
		path.dirname( new URL( import.meta.url ).pathname )
	) ) {
		if ( looksRight( up ) ) {
			return up;
		}
	}

	return null;
}

function* ancestors( from ) {
	let dir = path.resolve( from );
	for (;;) {
		yield dir;
		const parent = path.dirname( dir );
		if ( parent === dir ) {
			return;
		}
		dir = parent;
	}
}

/**
 * The script handles a set of packages needs, in load order.
 *
 * @param {string} wpRoot WordPress root.
 * @param {Array}  wanted Handles to resolve.
 * @return {Array} Handles, dependencies first.
 */
export function scriptOrder( wpRoot, wanted ) {
	const manifest = path.join(
		wpRoot,
		'wp-includes/assets/script-loader-packages.php'
	);
	if ( ! fs.existsSync( manifest ) ) {
		throw new Error(
			`No script manifest at ${ manifest } — is this a WordPress install?`
		);
	}

	const php = fs.readFileSync( manifest, 'utf8' );
	const deps = new Map();
	const entry =
		/'([\w-]+)\.js'\s*=>\s*array\(\s*'dependencies'\s*=>\s*array\(([^)]*)\)/g;
	let match;
	while ( ( match = entry.exec( php ) ) !== null ) {
		const handle = 'wp-' + match[ 1 ];
		const list = [ ...match[ 2 ].matchAll( /'([^']+)'/g ) ].map(
			( m ) => m[ 1 ]
		);
		deps.set( handle, list );
	}

	for ( const [ handle, list ] of Object.entries( VENDOR_DEPS ) ) {
		if ( ! deps.has( handle ) ) {
			deps.set( handle, list );
		}
	}

	const order = [];
	const seen = new Set();
	const visit = ( handle ) => {
		if ( seen.has( handle ) ) {
			return;
		}
		seen.add( handle );
		for ( const dep of deps.get( handle ) || [] ) {
			visit( dep );
		}
		order.push( handle );
	};
	wanted.forEach( visit );

	return order;
}

/**
 * Where a handle's file is.
 *
 * @param {string} wpRoot WordPress root.
 * @param {string} handle Script handle.
 * @return {string|null} Absolute path, or null when there is nothing to load.
 */
export function handleToFile( wpRoot, handle ) {
	if ( VENDOR[ handle ] ) {
		return path.join( wpRoot, 'wp-includes', VENDOR[ handle ] );
	}
	if ( ! handle.startsWith( 'wp-' ) ) {
		return null;
	}
	return path.join(
		wpRoot,
		'wp-includes/js/dist',
		handle.slice( 3 ) + '.min.js'
	);
}

/**
 * Boot the editor's block code in a DOM and hand back what validation needs.
 *
 * @param {string} wpRoot WordPress root.
 * @return {Object} The block API, plus the window it lives in.
 */
export function loadWordPressBlocks( wpRoot ) {
	const handles = scriptOrder( wpRoot, [
		'wp-blocks',
		'wp-block-editor',
		'wp-block-library',
	] );

	const sources = [];
	const missing = [];
	for ( const handle of handles ) {
		const file = handleToFile( wpRoot, handle );
		if ( ! file ) {
			continue;
		}
		if ( ! fs.existsSync( file ) ) {
			missing.push( handle );
			continue;
		}
		sources.push( {
			label: handle,
			code: fs.readFileSync( file, 'utf8' ),
		} );
	}

	return {
		...bootBlocks( sources, missing ),
		version: wordPressVersion( wpRoot ),
	};
}

/**
 * The same, for a site reachable only over HTTP.
 *
 * @param {Array}  urls    Script URLs, dependencies first.
 * @param {Object} options cacheDir, and a label for messages.
 * @return {Promise<Object>} The block API.
 */
export async function loadWordPressBlocksFromUrls( urls, options = {} ) {
	const cacheDir =
		options.cacheDir ||
		path.join(
			os.tmpdir(),
			'pattern-builder-wp-' + digest( urls.join( '\n' ) )
		);

	fs.mkdirSync( cacheDir, { recursive: true } );

	const sources = [];
	let fetched = 0;
	for ( const url of urls ) {
		const cached = path.join( cacheDir, digest( url ) + '.js' );
		if ( ! fs.existsSync( cached ) ) {
			const response = await fetch( url );
			if ( ! response.ok ) {
				throw new Error( `${ url } → HTTP ${ response.status }` );
			}
			fs.writeFileSync( cached, await response.text() );
			fetched++;
		}
		sources.push( { label: url, code: fs.readFileSync( cached, 'utf8' ) } );
	}

	if ( fetched ) {
		console.error(
			`Downloaded ${ fetched } script(s) into ${ cacheDir } — later runs reuse them.`
		);
	}

	return { ...bootBlocks( sources, [] ), version: options.version || '' };
}

/**
 * Run the editor's block code in a DOM and hand back what validation needs.
 *
 * @param {Array} sources Each with a label and its code, in load order.
 * @param {Array} missing Handles that could not be found, for the error.
 * @return {Object} The block API, plus the window it lives in.
 */
function bootBlocks( sources, missing ) {
	let JSDOM, VirtualConsole;
	try {
		( { JSDOM, VirtualConsole } = require( 'jsdom' ) );
	} catch {
		throw new Error(
			"jsdom is required to run WordPress's editor code outside a browser.\n" +
				'  npm i --no-save jsdom'
		);
	}

	const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
		url: 'http://localhost',
		runScripts: 'dangerously',
		pretendToBeVisual: true,
		virtualConsole: new VirtualConsole(),
	} );

	const win = dom.window;
	win.matchMedia = () => ( {
		matches: false,
		media: '',
		onchange: null,
		addListener() {},
		removeListener() {},
		addEventListener() {},
		removeEventListener() {},
		dispatchEvent: () => false,
	} );
	win.requestIdleCallback = ( cb ) =>
		setTimeout(
			() => cb( { didTimeout: false, timeRemaining: () => 50 } ),
			0
		);
	win.cancelIdleCallback = ( id ) => clearTimeout( id );
	win.CSS = win.CSS || { supports: () => false };

	for ( const { code } of sources ) {
		const script = win.document.createElement( 'script' );
		script.textContent = code;
		win.document.head.appendChild( script );
	}

	const wp = win.wp;
	if ( ! wp?.blocks?.parse || ! wp?.blockLibrary?.registerCoreBlocks ) {
		throw new Error(
			'WordPress loaded but the block API did not appear' +
				( missing.length
					? ` (missing: ${ missing.join( ', ' ) })`
					: '' )
		);
	}
	wp.hooks.addFilter(
		'blocks.registerBlockType',
		'pattern-builder/pattern-content-attribute',
		addPatternContentAttribute
	);

	wp.blockLibrary.registerCoreBlocks();

	return {
		parse: wp.blocks.parse,
		validateBlock: wp.blocks.validateBlock,
		getSaveContent: wp.blocks.getSaveContent,
		getBlockTypes: wp.blocks.getBlockTypes,
		parseRaw: wp.blockSerializationDefaultParser.parse,
		window: win,
	};
}

/**
 * A short, stable name for a string, used for cache file names.
 *
 * @param {string} value Anything.
 * @return {string} Sixteen hex characters.
 */
function digest( value ) {
	return crypto
		.createHash( 'sha256' )
		.update( value )
		.digest( 'hex' )
		.slice( 0, 16 );
}

/**
 * The install's version, for the line that says what this was checked against.
 *
 * @param {string} wpRoot WordPress root.
 * @return {string} Version, or an empty string.
 */
export function wordPressVersion( wpRoot ) {
	const file = path.join( wpRoot, 'wp-includes/version.php' );
	if ( ! fs.existsSync( file ) ) {
		return '';
	}
	const match = fs
		.readFileSync( file, 'utf8' )
		.match( /\$wp_version\s*=\s*'([^']+)'/ );
	return match ? match[ 1 ] : '';
}
