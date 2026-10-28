#!/usr/bin/env node
/**
 * Validate block markup with the editor's own validator.
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import {
	JSDOM_SETUP,
	addPatternContentAttribute,
	findWordPress,
	loadJsdom,
	loadWordPressBlocks,
	loadWordPressBlocksFromUrls,
} from './wp-core.mjs';
const require = createRequire( import.meta.url );

/**
 * `@wordpress/block-library` reaches for browser globals as its modules load,
 * so a DOM has to exist before anything from the WordPress packages is
 * imported. None of it runs during parsing; it just has to be present.
 */
function installDom() {
	const { JSDOM, VirtualConsole } = loadJsdom();
	const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
		url: 'http://localhost',
		pretendToBeVisual: true,
		virtualConsole: new VirtualConsole(),
	} );

	globalThis.window = dom.window;
	globalThis.document = dom.window.document;
	globalThis.self = dom.window;
	Object.defineProperty( globalThis, 'navigator', {
		value: dom.window.navigator,
		configurable: true,
		writable: true,
	} );

	for ( const key of [
		'HTMLElement',
		'HTMLDocument',
		'Element',
		'Node',
		'NodeList',
		'DOMParser',
		'Event',
		'CustomEvent',
		'MouseEvent',
		'KeyboardEvent',
		'FocusEvent',
		'getComputedStyle',
		'DocumentFragment',
		'Text',
		'Range',
		'XMLSerializer',
		'File',
		'FileList',
		'Blob',
		'FormData',
		'URL',
		'URLSearchParams',
		'localStorage',
		'sessionStorage',
		'location',
		'history',
		'screen',
	] ) {
		if (
			dom.window[ key ] !== undefined &&
			globalThis[ key ] === undefined
		) {
			globalThis[ key ] = dom.window[ key ];
		}
	}

	class NoopObserver {
		observe() {}
		unobserve() {}
		disconnect() {}
		takeRecords() {
			return [];
		}
	}
	for ( const key of [
		'MutationObserver',
		'IntersectionObserver',
		'ResizeObserver',
	] ) {
		if ( globalThis[ key ] === undefined ) {
			globalThis[ key ] = NoopObserver;
		}
		if ( dom.window[ key ] === undefined ) {
			dom.window[ key ] = globalThis[ key ];
		}
	}

	globalThis.requestAnimationFrame =
		dom.window.requestAnimationFrame ||
		( ( cb ) => setTimeout( () => cb( Date.now() ), 0 ) );
	globalThis.cancelAnimationFrame =
		dom.window.cancelAnimationFrame || ( ( id ) => clearTimeout( id ) );
	globalThis.requestIdleCallback = ( cb ) =>
		setTimeout(
			() => cb( { didTimeout: false, timeRemaining: () => 50 } ),
			0
		);
	globalThis.cancelIdleCallback = ( id ) => clearTimeout( id );
	globalThis.matchMedia =
		dom.window.matchMedia ||
		( () => ( {
			matches: false,
			media: '',
			onchange: null,
			addListener() {},
			removeListener() {},
			addEventListener() {},
			removeEventListener() {},
			dispatchEvent: () => false,
		} ) );
	dom.window.matchMedia = dom.window.matchMedia || globalThis.matchMedia;
}

/**
 * Reduce a pattern's PHP to the block markup underneath it.
 *
 * @param {string} source
 */
function stripPhp( source ) {
	return source
		.replace( /^\uFEFF/, '' )
		.replace( /^\s*<\?php[\s\S]*?\?>\s*/, '' )
		.replace(
			/<\?php\s*echo\s+esc_url\([\s\S]*?\)\s*;?\s*\?>/g,
			'https://example.com/'
		)
		.replace( /<\?php[\s\S]*?\?>/g, 'placeholder' );
}

/**
 * Run a function with the console muted.
 *
 * @param {Function} fn
 */
function quietly( fn ) {
	const saved = {
		log: console.log,
		info: console.info,
		warn: console.warn,
		error: console.error,
	};
	const noop = () => {};
	Object.assign( console, {
		log: noop,
		info: noop,
		warn: noop,
		error: noop,
	} );
	try {
		return fn();
	} finally {
		Object.assign( console, saved );
	}
}

/**
 * Classes core's own stylesheets style, so one in a `className` isn't a hook.
 */
const CORE_STYLED_CLASSES = [ 'screen-reader-text' ];

/**
 * The classes in a block's `className` that aren't a block style variation's,
 * which nothing in WordPress styles unless a stylesheet of the theme's does.
 *
 * @param {Object} block A parsed block.
 * @return {string[]} The classes.
 */
function stylingHooks( block ) {
	return String( block.attributes?.className ?? '' )
		.split( /\s+/ )
		.filter(
			( name ) =>
				name &&
				! name.startsWith( 'is-style-' ) &&
				! CORE_STYLED_CLASSES.includes( name )
		);
}

/**
 * Every block in the tree, inner blocks included.
 *
 * @param {Array} blocks
 * @param {Array} acc
 */
function flatten( blocks, acc = [] ) {
	for ( const block of blocks ) {
		acc.push( block );
		if ( block.innerBlocks?.length ) {
			flatten( block.innerBlocks, acc );
		}
	}
	return acc;
}

/**
 * Did the value turn up somewhere else in the attributes?
 *
 * @param {*}      value      The authored value.
 * @param {Object} attributes Attributes after parsing.
 * @return {boolean} Whether the value is still in there somewhere.
 */
function survivedElsewhere( value, attributes ) {
	const json = JSON.stringify( value );
	if ( ! json || ! /^["[{]/.test( json ) || json.length < 4 ) {
		return false;
	}
	return JSON.stringify( attributes ?? {} ).includes( json );
}

/**
 * Attributes the author wrote that did not survive parsing.
 *
 * @param {Array} authored Blocks from the raw serialization parser.
 * @param {Array} parsed   Blocks from the full parser.
 * @param {Array} out      Accumulator.
 */
function attributeLosses( authored, parsed, out = [] ) {
	const wrote = authored.filter( ( b ) => b.blockName );
	const got = parsed.filter( ( b ) => b.name && b.name !== 'core/freeform' );
	if ( wrote.length !== got.length ) {
		return out;
	}

	for ( let i = 0; i < wrote.length; i++ ) {
		if ( wrote[ i ].blockName !== got[ i ].name ) {
			return out;
		}

		const dropped = Object.keys( wrote[ i ].attrs || {} ).filter(
			( key ) => {
				if ( got[ i ].name === 'core/pattern' && key === 'content' ) {
					return false;
				}
				if ( got[ i ].attributes?.[ key ] !== undefined ) {
					return false;
				}
				return ! survivedElsewhere(
					wrote[ i ].attrs[ key ],
					got[ i ].attributes
				);
			}
		);

		if ( dropped.length ) {
			out.push( { name: got[ i ].name, dropped } );
		}

		attributeLosses(
			wrote[ i ].innerBlocks || [],
			got[ i ].innerBlocks || [],
			out
		);
	}
	return out;
}

/**
 * Which classes a mismatch is about, when it is about classes.
 *
 * @param {string} expected
 * @param {string} actual
 */
function classDiff( expected, actual ) {
	const classesOf = ( html ) =>
		[ ...String( html ).matchAll( /\sclass="([^"]*)"/g ) ].map(
			( m ) => m[ 1 ]
		);
	const want = classesOf( expected );
	const have = classesOf( actual );

	for ( let i = 0; i < Math.max( want.length, have.length ); i++ ) {
		const wanted = ( want[ i ] || '' ).split( /\s+/ ).filter( Boolean );
		const present = ( have[ i ] || '' ).split( /\s+/ ).filter( Boolean );
		const missing = wanted.filter( ( c ) => ! present.includes( c ) );
		const extra = present.filter( ( c ) => ! wanted.includes( c ) );
		if ( missing.length || extra.length ) {
			const quote = ( list ) =>
				list.map( ( c ) => `"${ c }"` ).join( ', ' );
			return [
				missing.length ? `missing class ${ quote( missing ) }` : '',
				extra.length ? `unexpected class ${ quote( extra ) }` : '',
			]
				.filter( Boolean )
				.join( '; ' );
		}
	}
	return '';
}

/**
 * Core's own validation message, with its placeholders filled in.
 *
 * @param {Object} issue One entry of validateBlock()'s second return value.
 */
function formatIssue( issue ) {
	if ( ! issue?.args?.length ) {
		return '';
	}
	const [ message, ...values ] = issue.args;
	let i = 0;
	return String( message ).replace( /%[so]/g, () => {
		const value = values[ i++ ];
		return typeof value === 'string' ? value : JSON.stringify( value );
	} );
}

/**
 * Why a group's own left or right padding is placing its content, or an empty
 * string when it is not. Content gets its width from the layout's two sizes,
 * Regular and Wide, and its distance from the window's edges from the theme's
 * root padding, so side padding on a full-width group, or on one with nothing
 * painted behind it, is a pattern doing the theme's job. Padding inside a
 * painted plate or card keeps text off its own edges and is left alone, and so
 * is a block style variation, which may paint one. Keep in step with
 * Pattern_Builder_Composition_Checks::side_padding().
 *
 * @param {Object} block A parsed block.
 * @return {string} The reason, or ''.
 */
function sidePadding( block ) {
	const attributes = block.attributes || {};
	const padding = attributes.style?.spacing?.padding;
	if (
		block.name !== 'core/group' ||
		! padding ||
		typeof padding !== 'object'
	) {
		return '';
	}

	const sides = [ 'left', 'right' ].filter(
		( side ) =>
			padding[ side ] !== undefined &&
			! /^0(px|r?em|%)?$/.test( String( padding[ side ] ).trim() )
	);
	if ( ! sides.length ) {
		return '';
	}

	const set = `sets ${ sides.join( ' and ' ) } padding`;
	if ( attributes.align === 'full' ) {
		return `a full-width group ${ set }`;
	}

	const painted =
		attributes.backgroundColor ||
		attributes.gradient ||
		attributes.borderColor ||
		attributes.style?.color?.background ||
		attributes.style?.color?.gradient ||
		attributes.style?.background?.backgroundImage ||
		attributes.style?.border ||
		/(^|\s)is-style-/.test( attributes.className || '' );

	return painted ? '' : `a group with nothing painted behind it ${ set }`;
}

function readInput( file ) {
	if ( file === '-' ) {
		return fs.readFileSync( 0, 'utf8' );
	}
	return fs.readFileSync( file, 'utf8' );
}

/**
 * Load the block code a site serves, from the list of its editor scripts.
 *
 * @param {Array}  urls    Script URLs, dependencies first.
 * @param {string} version The WordPress version the list came from.
 * @return {Promise<Object>} The block API, with a line saying what it is.
 */
async function loadFromList( urls, version ) {
	let core;
	try {
		core = await loadWordPressBlocksFromUrls( urls, { version } );
	} catch ( err ) {
		console.error( err.message );
		process.exit( 2 );
	}

	return {
		...core,
		describe:
			`Checked against WordPress ${
				core.version || '(unknown version)'
			} ` +
			`served from ${ new URL( urls[ urls.length - 1 ] ).origin } — ` +
			`${ core.getBlockTypes().length } block types.`,
	};
}

/**
 * The site this copy of the skill was installed from, as its SKILL.md records it under
 * `metadata.source`. The copy in the plugin itself records none.
 *
 * @return {string} The site's URL, or an empty string.
 */
function skillSource() {
	const entry = path.join(
		path.dirname( fileURLToPath( import.meta.url ) ),
		'..',
		'SKILL.md'
	);

	try {
		const front = fs
			.readFileSync( entry, 'utf8' )
			.match( /^---\n([\s\S]*?)\n---/ );
		const source =
			front && front[ 1 ].match( /^\s+source:\s*"?([^"\n]+)"?\s*$/m );
		return source ? source[ 1 ].trim() : '';
	} catch {
		return '';
	}
}

/**
 * Ask a site for its editor scripts, as it runs them now. Asked on every run, so a site
 * that has updated WordPress since the last one is checked against the new code.
 *
 * @param {string} site The site's URL.
 * @return {Promise<Object>} The block API, with a line saying what it is.
 */
async function loadFromSite( site ) {
	const list = new URL( site.replace( /\/?$/, '/' ) );
	list.searchParams.set( 'rest_route', '/pattern-builder/v1/editor-scripts' );

	let body;
	try {
		const response = await fetch( list );
		if ( ! response.ok ) {
			throw new Error( `${ list } → HTTP ${ response.status }` );
		}
		body = await response.json();
	} catch ( err ) {
		console.error(
			`Could not ask ${ site } for its editor scripts: ${ err.message }\n\n` +
				'Point at a WordPress install instead:\n' +
				'  validate-pattern.mjs --wp /path/to/wordpress <file...>\n' +
				"or save the site's get-editor-scripts answer and pass it:\n" +
				'  validate-pattern.mjs --scripts scripts.json <file...>'
		);
		process.exit( 2 );
	}

	if ( ! Array.isArray( body.scripts ) || ! body.scripts.length ) {
		console.error( `${ site } listed no editor scripts.` );
		process.exit( 2 );
	}

	return loadFromList( body.scripts, body.wordpress || '' );
}

/**
 * Where the block code comes from: a list given, a WordPress install, the site this skill
 * came from, or node_modules, in that order.
 *
 * @return {Object} parse, validateBlock, getSaveContent, parseRaw, describe.
 */
async function loadCore() {
	const listed = flagValue( '--scripts' );
	if ( listed ) {
		const raw = fs.readFileSync( listed, 'utf8' );
		let urls;
		let version = '';
		try {
			const json = JSON.parse( raw );
			const body = json.scripts ? json : json.output || json.data || {};
			urls = body.scripts;
			version = body.wordpress || '';
		} catch {
			urls = raw
				.split( '\n' )
				.map( ( line ) => line.trim() )
				.filter( Boolean );
		}

		if ( ! Array.isArray( urls ) || ! urls.length ) {
			console.error( `No script URLs in ${ listed }.` );
			process.exit( 2 );
		}

		return loadFromList( urls, version );
	}

	const hint = flagValue( '--wp' );

	if ( ! process.argv.includes( '--npm' ) ) {
		const wpRoot = findWordPress( hint );
		if ( wpRoot ) {
			let core;
			try {
				core = loadWordPressBlocks( wpRoot );
			} catch ( err ) {
				console.error( err.message );
				process.exit( 2 );
			}
			return {
				...core,
				describe:
					`Checked against WordPress ${
						core.version || '(unknown version)'
					} at ${ wpRoot } — ` +
					`${ core.getBlockTypes().length } block types.`,
			};
		}
		if ( hint ) {
			console.error( `No WordPress install at ${ hint }.` );
			process.exit( 2 );
		}

		const site = flagValue( '--site' ) || skillSource();
		if ( site ) {
			return loadFromSite( site );
		}
	}
	let blocks;
	try {
		installDom();
		blocks = require( '@wordpress/blocks' );
	} catch ( err ) {
		console.error(
			'Nothing to validate against.\n\n' +
				'The cheapest fix is to point at a WordPress install, which already has\n' +
				'every byte of this and has the version your pattern is destined for:\n\n' +
				'  validate-pattern.mjs --wp /path/to/wordpress <file...>\n' +
				'  WP_PATH=/path/to/wordpress validate-pattern.mjs <file...>\n' +
				'  …or just run it from anywhere inside the install.\n\n' +
				'Or name a site running Pattern Builder, which it asks for its editor scripts:\n' +
				'  validate-pattern.mjs --site https://example.com <file...>\n\n' +
				"It still needs jsdom, to play the browser WordPress's editor code expects,\n" +
				'installed once for this machine:\n' +
				`  ${ JSDOM_SETUP }\n\n` +
				'With no WordPress anywhere, the packages themselves work, in the project:\n' +
				'  npm i --no-save @wordpress/blocks @wordpress/block-library\n\n' +
				`( ${ err.message.split( '\n' )[ 0 ] } )`
		);
		process.exit( 2 );
	}
	require( '@wordpress/hooks' ).addFilter(
		'blocks.registerBlockType',
		'pattern-builder/pattern-content-attribute',
		addPatternContentAttribute
	);

	const { registerCoreBlocks } = require( '@wordpress/block-library' );
	quietly( () => registerCoreBlocks() );
	try {
		quietly( () => require( '@wordpress/block-editor' ) );
	} catch {}
	if (
		! require( '@wordpress/hooks' ).hasFilter(
			'blocks.getSaveContent.extraProps'
		)
	) {
		console.log(
			'WARNING: @wordpress/block-editor did not load, so block-supports classes are not being checked.'
		);
	}

	const version = ( () => {
		try {
			return require( '@wordpress/block-library/package.json' ).version;
		} catch {
			return '';
		}
	} )();

	return {
		parse: blocks.parse,
		validateBlock: blocks.validateBlock,
		getSaveContent: blocks.getSaveContent,
		parseRaw: require( '@wordpress/block-serialization-default-parser' )
			.parse,
		describe:
			`Checked against @wordpress/block-library ${
				version || '?'
			} from node_modules. ` +
			'A WordPress install would be a better answer: pass --wp <path>.',
	};
}

/**
 * Read `--flag value` from the command line.
 *
 * @param {string} name Flag name.
 * @return {string} The value, or an empty string.
 */
function flagValue( name ) {
	const at = process.argv.indexOf( name );
	return at !== -1 && process.argv[ at + 1 ] ? process.argv[ at + 1 ] : '';
}

async function main() {
	const argv = process.argv.slice( 2 );
	const valueAt = [ '--wp', '--scripts', '--site' ]
		.map( ( flag ) => argv.indexOf( flag ) )
		.filter( ( at ) => at !== -1 )
		.map( ( at ) => at + 1 );
	const files = argv.filter(
		( a, i ) => ! a.startsWith( '--' ) && ! valueAt.includes( i )
	);
	if ( ! files.length ) {
		console.error(
			'Usage: validate-pattern.mjs <file...>   (or "-" for stdin)'
		);
		process.exit( 2 );
	}

	const allowOldForm = process.argv.includes( '--allow-old-form' );

	const core = await loadCore();
	const { parse, validateBlock, getSaveContent, parseRaw } = core;
	console.log( core.describe + '\n' );

	let problems = 0;
	let oldForm = 0;
	let lost = 0;
	let padded = 0;
	let hooked = 0;

	for ( const file of files ) {
		const label =
			file === '-' ? '<stdin>' : path.relative( process.cwd(), file );
		let blocks;
		let authored;
		const source = stripPhp( readInput( file ) );
		try {
			blocks = quietly( () => parse( source ) );
			authored = quietly( () => parseRaw( source ) );
		} catch ( err ) {
			console.log( `${ label }: could not parse — ${ err.message }` );
			problems++;
			continue;
		}

		for ( const loss of attributeLosses( authored, blocks ) ) {
			console.log(
				`${ label }: DROPPED ATTRIBUTE ${ loss.name } — ${ loss.dropped
					.map( ( key ) => `"${ key }"` )
					.join( ', ' ) } did not survive parsing.`
			);
			lost++;
		}

		for ( const block of flatten( blocks ) ) {
			if ( block.name === 'core/missing' ) {
				const original = block.attributes?.originalName || 'unknown';
				console.log(
					`${ label }: UNREGISTERED BLOCK "${ original }" — not available on a site with only core blocks.`
				);
				problems++;
				continue;
			}
			if ( block.name === null ) {
				continue;
			}

			const padding = sidePadding( block );
			if ( padding ) {
				const name = block.attributes?.metadata?.name;
				console.log(
					`${ label }: SIDE PADDING ${ block.name }${
						name ? ` "${ name }"` : ''
					} — ${ padding } to place its content. Use Wide or Regular instead.`
				);
				padded++;
			}

			const hooks = stylingHooks( block );
			if ( hooks.length ) {
				console.log(
					`${ label }: STYLING HOOK ${ block.name }: ${ hooks
						.map( ( name ) => `"${ name }"` )
						.join( ', ' ) } ${
						hooks.length === 1
							? "isn't a block style variation's class"
							: "aren't block style variations' classes"
					}.`
				);
				hooked++;
			}
			if ( block.originalContent === undefined ) {
				continue;
			}

			if ( ! block.isValid ) {
				const detail =
					block.validationIssues?.[ 0 ]?.log?.join?.( ' ' ) || '';
				console.log(
					`${ label }: INVALID ${ block.name }${
						detail ? ' — ' + detail : ''
					}`
				);
				problems++;
				continue;
			}
			if ( block.attributes?.metadata?.bindings ) {
				continue;
			}
			const [ current, issues ] = quietly( () => validateBlock( block ) );
			if ( current ) {
				continue;
			}

			let expected = '';
			try {
				expected = quietly( () =>
					getSaveContent(
						block.name,
						block.attributes,
						block.innerBlocks
					)
				);
			} catch {
				expected = '';
			}

			const detail =
				classDiff( expected, block.originalContent || '' ) ||
				formatIssue( issues?.[ 0 ] );

			console.log(
				`${ label }: OLD FORM ${ block.name }${
					detail ? ' — ' + detail : ''
				}`
			);
			oldForm++;
		}
	}

	if ( problems === 0 && oldForm === 0 && lost === 0 && padded === 0 ) {
		console.log(
			`All blocks valid and current across ${ files.length } file(s).`
		);
	}

	if ( problems ) {
		console.log(
			`\n${ problems } invalid block(s). Invalid markup renders correctly on the front end and fails only when an editor opens it, so this must be clean before the pattern is used.`
		);
	}

	if ( oldForm ) {
		console.log(
			`\n${ oldForm } block(s) in old form. These match a *deprecated* version of the block, so an editor opens them without complaint and migrates them — but the file on disk is not what the block writes today. The usual cause is a block-supports class that never made it into the markup, which means the style silently does not apply on the front end. Add what is named above.`
		);
	}

	if ( lost ) {
		console.log(
			`\n${ lost } attribute(s) dropped. WordPress read the markup as an older version of the block and migrated it, and the migration threw these away — so the setting you wrote is simply not there any more. Write the markup the attribute implies (the class it needs is in the table in references/block-markup.md) and it will survive.`
		);
	}

	if ( padded ) {
		console.log(
			`\n${ padded } group(s) pad their own sides. Content gets its width from the layout's two sizes — Regular (no "align": the content size) and Wide ("align":"wide") — and its distance from the window's edges from the theme's root padding, so a pattern that pads its sides doubles that gutter on one theme and fights the next. Drop the left and right padding and make the group, or what is inside it, Wide or Regular. Keep full width for a band whose background has to reach the edges, constrained inside and unpadded at the sides.`
		);
	}

	if ( hooked ) {
		console.log(
			`\n${ hooked } block(s) carry a class that isn't a block style variation's. Nothing in WordPress styles a class like that, so it's either doing nothing or hanging a look on a stylesheet that nobody editing the page can see and that stays behind wherever the pattern goes. If it names a look, make it a block style variation and apply is-style-{slug}; if it's a size, a color or spacing, put it in the block's settings. "Where a look goes" in references/design-system.md has the order. This doesn't fail the run.`
		);
	}

	process.exit(
		problems || lost || padded || ( oldForm && ! allowOldForm ) ? 1 : 0
	);
}

main().catch( ( err ) => {
	console.error( err.message );
	process.exit( 2 );
} );
