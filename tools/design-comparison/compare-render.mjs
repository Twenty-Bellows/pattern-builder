#!/usr/bin/env node
/**
 * Compare two rendered pages by computed style rather than by eye.
 *
 * Both pages are loaded at the same width and every element carrying visible
 * text is recorded with its computed type, colour and box. The two are matched
 * on the text itself, which is identical by construction when you are
 * reproducing a design — so nothing has to guess which element corresponds to
 * which.
 *
 *   npm i --no-save playwright
 *   node compare-render.mjs <source-url> <mine-url> [width]
 */

import { chromium } from 'playwright';

const FIELDS = [
	'fontSize',
	'fontFamily',
	'fontWeight',
	'lineHeight',
	'letterSpacing',
	'textTransform',
	'textDecoration',
	'color',
	'background',
];

/** Everything that carries text, with what the browser computed for it. */
async function collect( page, url ) {
	await page.goto( url, { waitUntil: 'load', timeout: 60000 } );
	await page.waitForTimeout( 1200 );

	return page.evaluate( () => {
		const TEXTUAL = new Set( [
			'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'P', 'LI', 'A',
			'TD', 'TH', 'SPAN', 'STRONG', 'EM', 'FIGCAPTION', 'BUTTON',
		] );

		/* The nearest ancestor that actually paints, so a colour can be judged
		 * against the ground it sits on rather than against `transparent`. */
		const painted = ( el ) => {
			for ( let n = el; n && n !== document.documentElement; n = n.parentElement ) {
				const bg = getComputedStyle( n ).backgroundColor;
				if ( bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent' ) {
					return bg;
				}
			}
			return getComputedStyle( document.body ).backgroundColor;
		};

		const out = [];

		for ( const el of document.querySelectorAll( '*' ) ) {
			if ( ! TEXTUAL.has( el.tagName ) ) {
				continue;
			}

			// Only the text this element owns, not its descendants' — otherwise
			// every ancestor matches the same string.
			const own = Array.from( el.childNodes )
				.filter( ( n ) => n.nodeType === Node.TEXT_NODE )
				.map( ( n ) => n.textContent )
				.join( ' ' )
				.replace( /\s+/g, ' ' )
				.trim();

			if ( own.length < 3 ) {
				continue;
			}

			const cs = getComputedStyle( el );
			const box = el.getBoundingClientRect();

			out.push( {
				text: own.slice( 0, 90 ),
				tag: el.tagName,
				fontSize: cs.fontSize,
				fontFamily: cs.fontFamily.split( ',' )[ 0 ].replace( /["']/g, '' ),
				fontWeight: cs.fontWeight,
				lineHeight: cs.lineHeight,
				letterSpacing: cs.letterSpacing,
				textTransform: cs.textTransform,
				textDecoration: cs.textDecorationLine,
				color: cs.color,
				background: painted( el ),
				top: Math.round( box.top + window.scrollY ),
				width: Math.round( box.width ),
			} );
		}

		return { height: document.documentElement.scrollHeight, elements: out };
	} );
}

const key = ( e ) => e.text.toLowerCase().replace( /[^a-z0-9 ]/g, '' ).slice( 0, 60 );

const index = ( list ) => {
	const m = new Map();
	for ( const e of list ) {
		const k = key( e );
		m.set( k, [ ...( m.get( k ) || [] ), e ] );
	}
	return m;
};

const [ sourceUrl, mineUrl, widthArg ] = process.argv.slice( 2 );

if ( ! sourceUrl || ! mineUrl ) {
	console.error( 'usage: compare-render.mjs <source-url> <mine-url> [width]' );
	process.exit( 2 );
}

const width = Number( widthArg || 1400 );
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width, height: 1000 } } );

const source = await collect( page, sourceUrl );
const mine = await collect( page, mineUrl );
await browser.close();

const sourceIndex = index( source.elements );
const mineIndex = index( mine.elements );

const differences = [];
const drift = [];
const missing = [];
let matched = 0;

for ( const [ k, wanted ] of sourceIndex ) {
	const got = mineIndex.get( k );
	if ( ! got ) {
		missing.push( wanted[ 0 ].text );
		continue;
	}
	matched++;

	// Every occurrence, not just the first: the same words appear on bands with
	// different grounds, and taking one hides the others.
	for ( let i = 0; i < Math.min( wanted.length, got.length ); i++ ) {
		const a = wanted[ i ];
		const b = got[ i ];
		const label = a.text + ( wanted.length > 1 ? ` [${ i + 1 }/${ wanted.length }]` : '' );

		const wrong = FIELDS.filter( ( f ) => a[ f ] !== b[ f ] );
		if ( wrong.length ) {
			differences.push( { label, tag: a.tag, wrong: wrong.map( ( f ) => `${ f }: ${ a[ f ] }  ->  ${ b[ f ] }` ) } );
		}
		if ( Math.abs( a.top - b.top ) > 4 || Math.abs( a.width - b.width ) > 4 ) {
			drift.push( { label, tag: a.tag, srcTop: a.top, mineTop: b.top, dy: b.top - a.top, srcW: a.width, mineW: b.width } );
		}
	}
}

console.log( `page height   source ${ source.height }px   mine ${ mine.height }px` );
console.log( `matched ${ matched } of ${ sourceIndex.size } source strings; ${ missing.length } not found in mine` );
console.log( `${ differences.length } elements differ on at least one computed property\n` );

for ( const d of differences.slice( 0, 40 ) ) {
	console.log( `  ${ d.tag }  "${ d.label.slice( 0, 60 ) }"` );
	for ( const line of d.wrong ) {
		console.log( `      ${ line }` );
	}
}

if ( missing.length ) {
	console.log( '\nNot found in mine:' );
	for ( const t of missing.slice( 0, 20 ) ) {
		console.log( '  - ' + t.slice( 0, 70 ) );
	}
}

/* Only where the running offset changes — that is where a band grew or shrank,
 * rather than everything downstream of it repeating the same number. */
if ( drift.length ) {
	console.log( `\n${ drift.length } elements sit at a different position or width; where it changes:` );
	drift.sort( ( a, b ) => a.srcTop - b.srcTop );
	let last = 0;
	for ( const d of drift ) {
		if ( Math.abs( d.dy - last ) > 3 || Math.abs( d.srcW - d.mineW ) > 4 ) {
			console.log(
				`  y ${ String( d.srcTop ).padStart( 5 ) } -> ${ String( d.mineTop ).padStart( 5 ) }` +
					`  (dy ${ d.dy > 0 ? '+' : '' }${ d.dy })  w ${ d.srcW } -> ${ d.mineW }   ${ d.tag } "${ d.label.slice( 0, 45 ) }"`
			);
			last = d.dy;
		}
	}
}

process.exit( differences.length || missing.length ? 1 : 0 );
