#!/usr/bin/env node
/**
 * Validate block markup with the editor's own validator.
 *
 * WordPress decides a block is valid by re-running its `save()` against the
 * stored attributes and diffing the result against the markup on disk. `save()`
 * is JavaScript, so no amount of PHP, and no look at the rendered front end,
 * can answer the question — the front end happily prints invalid markup, and
 * the failure only appears when an editor opens it. This runs the real thing
 * (`@wordpress/blocks` `parse()` with the core block library registered) in
 * Node, so a pattern can be checked before it is written anywhere.
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
		'HTMLElement', 'HTMLDocument', 'Element', 'Node', 'NodeList', 'DOMParser',
		'Event', 'CustomEvent', 'MouseEvent', 'KeyboardEvent', 'FocusEvent',
		'getComputedStyle', 'DocumentFragment', 'Text', 'Range', 'XMLSerializer',
		'File', 'FileList', 'Blob', 'FormData', 'URL', 'URLSearchParams',
		'localStorage', 'sessionStorage', 'location', 'history', 'screen',
	] ) {
		if ( dom.window[ key ] !== undefined && globalThis[ key ] === undefined ) {
			globalThis[ key ] = dom.window[ key ];
		}
	}

	class NoopObserver {
		observe() {}
		unobserve() {}
		disconnect() {}
		takeRecords() { return []; }
	}
	for ( const key of [ 'MutationObserver', 'IntersectionObserver', 'ResizeObserver' ] ) {
		if ( globalThis[ key ] === undefined ) globalThis[ key ] = NoopObserver;
		if ( dom.window[ key ] === undefined ) dom.window[ key ] = globalThis[ key ];
	}

	globalThis.requestAnimationFrame = dom.window.requestAnimationFrame || ( ( cb ) => setTimeout( () => cb( Date.now() ), 0 ) );
	globalThis.cancelAnimationFrame = dom.window.cancelAnimationFrame || ( ( id ) => clearTimeout( id ) );
	globalThis.requestIdleCallback = ( cb ) => setTimeout( () => cb( { didTimeout: false, timeRemaining: () => 50 } ), 0 );
	globalThis.cancelIdleCallback = ( id ) => clearTimeout( id );
	globalThis.matchMedia = dom.window.matchMedia || ( () => ( {
		matches: false, media: '', onchange: null,
		addListener() {}, removeListener() {},
		addEventListener() {}, removeEventListener() {},
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
 */
function stripPhp( source ) {
	return source
		.replace( /^﻿/, '' )
		// The header docblock and any opening PHP section.
		.replace( /^\s*<\?php[\s\S]*?\?>\s*/, '' )
		// Inline expressions inside attributes become a stand-in URL/string.
		.replace( /<\?php\s*echo\s+esc_url\([\s\S]*?\)\s*;?\s*\?>/g, 'https://example.com/' )
		.replace( /<\?php[\s\S]*?\?>/g, 'placeholder' );
}

/**
 * Run a function with the console muted.
 *
 * `parse()` logs the whole expected-vs-actual diff for every invalid block.
 * That detail is the useful part, but it is available per block through
 * `validationIssues`, and letting it stream to stdout buries the report.
 */
function quietly( fn ) {
	const saved = { log: console.log, info: console.info, warn: console.warn, error: console.error };
	const noop = () => {};
	Object.assign( console, { log: noop, info: noop, warn: noop, error: noop } );
	try {
		return fn();
	} finally {
		Object.assign( console, saved );
	}
}

/** Every block in the tree, inner blocks included. */
function flatten( blocks, acc = [] ) {
	for ( const block of blocks ) {
		acc.push( block );
		if ( block.innerBlocks?.length ) flatten( block.innerBlocks, acc );
	}
	return acc;
}

function readInput( file ) {
	if ( file === '-' ) return fs.readFileSync( 0, 'utf8' );
	return fs.readFileSync( file, 'utf8' );
}

function main() {
	const files = process.argv.slice( 2 ).filter( ( a ) => ! a.startsWith( '--' ) );
	if ( ! files.length ) {
		console.error( 'Usage: validate-pattern.mjs <file...>   (or "-" for stdin)' );
		process.exit( 2 );
	}

	installDom();
	const { parse } = require( '@wordpress/blocks' );
	const { registerCoreBlocks } = require( '@wordpress/block-library' );
	quietly( () => registerCoreBlocks() );

	let problems = 0;

	for ( const file of files ) {
		const label = file === '-' ? '<stdin>' : path.relative( process.cwd(), file );
		let blocks;
		try {
			blocks = quietly( () => parse( stripPhp( readInput( file ) ) ) );
		} catch ( err ) {
			console.log( `${ label }: could not parse — ${ err.message }` );
			problems++;
			continue;
		}

		for ( const block of flatten( blocks ) ) {
			if ( block.name === 'core/missing' ) {
				const original = ( block.attributes?.originalName ) || 'unknown';
				console.log( `${ label }: UNREGISTERED BLOCK "${ original }" — not available on a site with only core blocks.` );
				problems++;
				continue;
			}
			if ( block.name === null ) continue; // Freeform whitespace between blocks.
			if ( ! block.isValid ) {
				const detail = block.validationIssues?.[ 0 ]?.log?.join?.( ' ' ) || '';
				console.log( `${ label }: INVALID ${ block.name }${ detail ? ' — ' + detail : '' }` );
				problems++;
			}
		}
	}

	if ( problems === 0 ) {
		console.log( `All blocks valid across ${ files.length } file(s).` );
		process.exit( 0 );
	}

	console.log( `\n${ problems } problem(s). Invalid markup renders correctly on the front end and fails only when an editor opens it, so this must be clean before the pattern is used.` );
	process.exit( 1 );
}

main();
