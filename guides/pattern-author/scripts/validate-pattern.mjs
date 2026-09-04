#!/usr/bin/env node
/**
 * Validate block markup with the editor's own validator.
 *
 * WordPress decides a block is valid by re-running its `save()` against the
 * stored attributes and diffing the result against the markup on disk. `save()`
 * is JavaScript, so no amount of PHP, and no look at the rendered front end,
 * can answer the question — the front end happily prints invalid markup, and
 * the failure only appears when an editor opens it. This runs the real thing
 * (`@wordpress/blocks` with the core block library registered) in Node, so a
 * pattern can be checked before it is written anywhere.
 *
 * Two questions get asked, because core answers two:
 *
 *   parse()         Will an editor open this without complaining? Tolerant by
 *                   design — it tries each block's *deprecated* save versions
 *                   (core/paragraph has six) and silently migrates when one
 *                   matches.
 *   validateBlock() Is this what the block writes *today*? Strict.
 *
 * The gap between them is where a pattern quietly rots. Markup that matches a
 * deprecation opens without a murmur, but the file on disk still lacks what
 * the current block would write — most often a block-supports class — and the
 * front end renders the file, not the editor's idea of it. So
 * `{"backgroundColor":"primary"}` with no `has-primary-background-color`
 * passes parse(), renders with no background, and reads as a design mistake
 * rather than a bug. Hence both, reported separately.
 *
 * Usage:
 *   node validate-pattern.mjs <file.php|file.html> [more files...]
 *   cat markup | node validate-pattern.mjs -
 *
 * Exit code is 0 only when every block is valid and registered.
 *
 * Requires `@wordpress/blocks`, `@wordpress/block-library` and `jsdom` to be
 * resolvable — from the project's own node_modules, or installed on the fly:
 *   npm i --no-save @wordpress/blocks @wordpress/block-library jsdom
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import {
	addPatternContentAttribute,
	findWordPress,
	loadWordPressBlocks,
	loadWordPressBlocksFromUrls,
} from './wp-core.mjs';

/*
 * The WordPress packages' ESM builds import JSON without an import attribute,
 * which Node's strict ESM loader rejects outright. Their CommonJS builds are
 * equivalent and load cleanly, so everything goes through require().
 */
const require = createRequire( import.meta.url );

/**
 * `@wordpress/block-library` reaches for browser globals as its modules load,
 * so a DOM has to exist before anything from the WordPress packages is
 * imported. None of it runs during parsing; it just has to be present.
 */
function installDom() {
	const { JSDOM, VirtualConsole } = require( 'jsdom' );

	// jsdom complains about CSS it cannot parse in the block library's inline
	// styles. Irrelevant to validation, and it drowns the report.
	const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
		url: 'http://localhost',
		pretendToBeVisual: true,
		virtualConsole: new VirtualConsole(),
	} );

	globalThis.window = dom.window;
	globalThis.document = dom.window.document;
	globalThis.self = dom.window;

	// Node exposes a getter-only `navigator`; plain assignment throws.
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
 * Theme patterns are PHP: a docblock header and sometimes inline
 * `<?php echo esc_url( … ); ?>` inside an attribute. Substituting a plausible
 * literal for each inline expression leaves markup that parses the way it
 * would at runtime.
 * @param {string} source
 */
function stripPhp( source ) {
	return (
		source
			// A byte order mark, as an escape rather than the character itself.
			.replace( /^\uFEFF/, '' )
			// The header docblock and any opening PHP section.
			.replace( /^\s*<\?php[\s\S]*?\?>\s*/, '' )
			// Inline expressions inside attributes become a stand-in URL/string.
			.replace(
				/<\?php\s*echo\s+esc_url\([\s\S]*?\)\s*;?\s*\?>/g,
				'https://example.com/'
			)
			.replace( /<\?php[\s\S]*?\?>/g, 'placeholder' )
	);
}

/**
 * Run a function with the console muted.
 *
 * `parse()` logs the whole expected-vs-actual diff for every invalid block.
 * That detail is the useful part, but it is available per block through
 * `validationIssues`, and letting it stream to stdout buries the report.
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
 * Every block in the tree, inner blocks included.
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
 * Core moves things as well as dropping them. Block library 10.5 turned text
 * alignment from a paragraph's `align` and a heading's `textAlign` into a
 * typography support, and its migration relocates the value to
 * `style.typography.textAlign` rather than discarding it. The setting is
 * intact, so that is not a loss and must not be reported as one.
 *
 * Only values distinctive enough not to collide are followed: a bare `2` or
 * `true` would match something unrelated and turn this into noise.
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
 * When markup matches a deprecated save, core does not just accept it — it
 * runs that version's `migrate()`, which reads the *markup* as authoritative
 * and can drop an attribute the author wrote. A heading with
 * `{"level":2,"fontSize":"xx-large"}` whose tag carries no `has-…-font-size`
 * class comes back with no `fontSize` at all: the size is gone, the block is
 * entirely self-consistent afterwards, and nothing anywhere reports it.
 *
 * So this compares what is written in the block delimiters against what came
 * out of the full parser. Where a migration reshaped the tree the two stop
 * corresponding, and it stops rather than guess — silence beats a false alarm.
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
				/*
				 * `content` on core/pattern is the synced-pattern runtime's, supplied
				 * by Pattern Builder rather than core, and the loaders declare it the
				 * way the runtime does so it survives parsing here. Should a loader
				 * ever not (a `core/pattern` registered before the filter), its loss
				 * is a fact about this environment rather than about the file, and
				 * not this file's to report.
				 */
				if ( got[ i ].name === 'core/pattern' && key === 'content' ) {
					return false;
				}
				if ( got[ i ].attributes?.[ key ] !== undefined ) {
					return false;
				}
				// Relocated by a migration rather than thrown away.
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
 * Nearly every strict failure is a block-supports class that never made it
 * into the markup, so naming the class beats printing core's tokenizer diff.
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

function readInput( file ) {
	if ( file === '-' ) {
		return fs.readFileSync( 0, 'utf8' );
	}
	return fs.readFileSync( file, 'utf8' );
}

/**
 * Where the block code comes from.
 *
 * A WordPress install is preferred over anything on npm, and not only to
 * spare somebody a few hundred megabytes of install. Whether markup is what a
 * block writes *today* is a question only a specific block library can
 * answer — block library 10.5 moved text alignment into a typography support
 * and so disagrees with 9.22 about the same file — so the honest thing to
 * check against is the WordPress the pattern is destined for, not whatever
 * npm last resolved.
 *
 * @return {Object} parse, validateBlock, getSaveContent, parseRaw, describe.
 */
async function loadCore() {
	/*
	 * A list of script URLs from `pattern-builder/get-editor-scripts`. This is
	 * the route for an agent that reached the site over HTTP and has no copy
	 * of WordPress on disk: the scripts are served to anyone, but the order
	 * they load in has to come from the site, because core's manifest is a
	 * PHP file and a request for it executes rather than serves.
	 */
	const listed = flagValue( '--scripts' );
	if ( listed ) {
		const raw = fs.readFileSync( listed, 'utf8' );
		let urls;
		let version = '';
		try {
			// The ability's response, saved verbatim, is the easiest thing to
			// hand back to us.
			const json = JSON.parse( raw );
			const body = json.scripts ? json : json.output || json.data || {};
			urls = body.scripts;
			version = body.wordpress || '';
		} catch {
			// Or a plain list, one URL per line.
			urls = raw
				.split( '\n' )
				.map( ( line ) => line.trim() )
				.filter( Boolean );
		}

		if ( ! Array.isArray( urls ) || ! urls.length ) {
			console.error( `No script URLs in ${ listed }.` );
			process.exit( 2 );
		}

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
				`served from ${
					new URL( urls[ urls.length - 1 ] ).origin
				} — ` +
				`${ core.getBlockTypes().length } block types.`,
		};
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
	}

	// Nothing to point at, so fall back to whatever the project has.
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
				"It still needs jsdom, to play the browser WordPress's editor code expects:\n" +
				'  npm i --no-save jsdom\n\n' +
				'With no WordPress anywhere, the packages themselves work:\n' +
				'  npm i --no-save @wordpress/blocks @wordpress/block-library jsdom\n\n' +
				`( ${ err.message.split( '\n' )[ 0 ] } )`
		);
		process.exit( 2 );
	}

	// The `content` attribute Pattern Builder adds to core/pattern, declared
	// before the core blocks register so a reference keeps its slot values.
	require( '@wordpress/hooks' ).addFilter(
		'blocks.registerBlockType',
		'pattern-builder/pattern-content-attribute',
		addPatternContentAttribute
	);

	const { registerCoreBlocks } = require( '@wordpress/block-library' );
	quietly( () => registerCoreBlocks() );

	/*
	 * Block-supports classes are not written by a block's own save(); filters
	 * in the editor package add them, and without those registered the strict
	 * check silently loses most of its value. The block library pulls the
	 * package in already, but say so out loud and check rather than trust.
	 */
	try {
		quietly( () => require( '@wordpress/block-editor' ) );
	} catch {
		// The check below reports the consequence.
	}
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
	// These flags take a value, and the value is not a file. Guard the index,
	// because with a flag absent `indexOf` is -1 and `at + 1` would be 0,
	// swallowing the first file.
	const valueAt = [ '--wp', '--scripts' ]
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
			} // Freeform whitespace between blocks.

			/*
			 * A migration can invent inner blocks (a deprecated list grows
			 * list-item children). Nothing of theirs is on disk, so there is
			 * nothing here to hold them to.
			 */
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

			/*
			 * A bound block takes its content from the binding source at
			 * render, not from the file, so holding the file to a save
			 * computed from the file's own attributes says nothing. Core
			 * reserves markup for the bound value too, which a bare Node
			 * context has no way to fill.
			 */
			if ( block.attributes?.metadata?.bindings ) {
				continue;
			}

			/*
			 * parse() accepted it, which only means some version of this block
			 * once saved markup like this. Ask whether the current one would.
			 */
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

	if ( problems === 0 && oldForm === 0 && lost === 0 ) {
		console.log(
			`All blocks valid and current across ${ files.length } file(s).`
		);
		process.exit( 0 );
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

	process.exit( problems || lost || ( oldForm && ! allowOldForm ) ? 1 : 0 );
}

main().catch( ( err ) => {
	console.error( err.message );
	process.exit( 2 );
} );
