#!/usr/bin/env node
/**
 * Does this markup actually lay out?
 */
import fs from 'node:fs';
import path from 'node:path';
import { loadWordPressBlocks, findWordPress } from './wp-core.mjs';

const TRACK_LAYOUTS = new Set( [ 'flex', 'grid' ] );

/**
 * Every pattern file under a directory, mapped by its Slug: header.
 *
 * @param {string} root Directory to walk.
 */
function slugMap( root ) {
	const map = new Map();
	const walk = ( dir ) => {
		for ( const e of fs.readdirSync( dir, { withFileTypes: true } ) ) {
			const f = path.join( dir, e.name );
			if ( e.isDirectory() ) {
				walk( f );
				continue;
			}
			if ( ! e.name.endsWith( '.php' ) ) {
				continue;
			}
			const src = fs.readFileSync( f, 'utf8' );
			const m = src.match( /^\s*\*\s*Slug:\s*(\S+)/m );
			if ( m ) {
				map.set( m[ 1 ], f );
			}
		}
	};
	if ( fs.existsSync( root ) ) {
		walk( root );
	}
	return map;
}

/**
 * Find the theme's patterns/ directory from any file inside it.
 *
 * @param {string} start A file or directory inside the theme.
 */
function patternsRoot( start ) {
	if ( fs.statSync( start ).isDirectory() ) {
		return start;
	}
	let dir = path.dirname( start );
	for ( let i = 0; i < 8; i++ ) {
		if ( path.basename( dir ) === 'patterns' ) {
			return dir;
		}
		if ( fs.existsSync( path.join( dir, 'patterns' ) ) ) {
			return path.join( dir, 'patterns' );
		}
		const up = path.dirname( dir );
		if ( up === dir ) {
			break;
		}
		dir = up;
	}
	return null;
}

const stripPhp = ( src ) =>
	src
		.replace( /^<\?php[\s\S]*?\?>\s*/, '' )
		.replace( /<\?php.*?\?>/g, 'PHP_EXPR' );

/**
 * The layout a block imposes on its children, and the one it obeys itself.
 *
 * @param {Object} block Parsed block.
 */
const layoutOf = ( block ) => block?.attributes?.layout?.type ?? null;

/**
 * Can this child size itself down inside a flex/grid track?
 *
 * @param {Object}   child       Parsed child block.
 * @param {Function} resolveRoot Resolves a pattern reference to its root block.
 */
function sizing( child, resolveRoot ) {
	const a = child.attributes ?? {};
	if (
		a.width ||
		a.style?.layout?.selfStretch ||
		a.style?.layout?.columnSpan
	) {
		return { sized: true };
	}
	let inspect = child;
	let via = null;
	if ( child.name === 'core/pattern' && a.slug ) {
		const root = resolveRoot( a.slug );
		if ( ! root ) {
			return { sized: false, unknown: a.slug };
		}
		inspect = root;
		via = a.slug;
	}
	const lay = layoutOf( inspect );
	if ( lay === 'constrained' ) {
		return { sized: false, constrained: true, via };
	}
	return { sized: false, via, name: inspect.name };
}

function check( file, parse, resolveRoot ) {
	const blocks = parse( stripPhp( fs.readFileSync( file, 'utf8' ) ) );
	const problems = [];

	const visit = ( block ) => {
		const kids = ( block.innerBlocks || [] ).filter( ( b ) => b.name );
		const lay = layoutOf( block );

		if ( TRACK_LAYOUTS.has( lay ) && kids.length ) {
			const attrs = block.attributes?.layout ?? {};
			const gridSized =
				lay === 'grid' &&
				( attrs.columnCount || attrs.minimumColumnWidth );
			if ( ! gridSized ) {
				const reports = kids.map( ( k ) => sizing( k, resolveRoot ) );
				const fills = reports.filter( ( r ) => r.constrained );
				const unsized = reports.filter( ( r ) => ! r.sized );

				if ( fills.length ) {
					problems.push(
						`${ lay } container: ${ fills.length } of ${ kids.length } children are ` +
							`constrained groups, which fill the whole track — this renders as a ` +
							`vertical stack, not a row.` +
							( fills[ 0 ].via
								? ` (first: ${ fills[ 0 ].via })`
								: '' )
					);
				} else if ( lay === 'flex' && unsized.length >= 3 ) {
					problems.push(
						`flex container: ${ unsized.length } children and not one carries a ` +
							`width — flex sizes them by content, so this will not hold a grid. ` +
							`Use core/columns for a fixed count or layout grid to wrap.`
					);
				}
				const unknown = [
					...new Set(
						reports
							.filter( ( x ) => x.unknown )
							.map( ( x ) => x.unknown )
					),
				];
				for ( const slug of unknown ) {
					problems.push(
						`reference ${ slug } could not be resolved, so its sizing is unknown.`
					);
				}
			}
		}
		kids.forEach( visit );
	};
	blocks.filter( ( b ) => b.name ).forEach( visit );
	return problems;
}

const target = process.argv[ 2 ];
if ( ! target ) {
	console.error( 'usage: check-composition.mjs <file|dir>' );
	process.exit( 2 );
}

const root = patternsRoot( target );
const map = root ? slugMap( root ) : new Map();
const core = loadWordPressBlocks(
	process.env.WP_PATH || findWordPress( target )
);
const parse = core.window.wp.blocks.parse;

const rootCache = new Map();
const resolveRoot = ( slug ) => {
	if ( rootCache.has( slug ) ) {
		return rootCache.get( slug );
	}
	const f = map.get( slug );
	let r = null;
	if ( f ) {
		const b = parse( stripPhp( fs.readFileSync( f, 'utf8' ) ) ).filter(
			( x ) => x.name
		);
		r = b.length === 1 ? b[ 0 ] : null;
	}
	rootCache.set( slug, r );
	return r;
};

const files = fs.statSync( target ).isDirectory()
	? [ ...slugMap( target ).values() ]
	: [ target ];

let bad = 0;
for ( const f of files.sort() ) {
	const problems = check( f, parse, resolveRoot );
	if ( problems.length ) {
		bad++;
		console.log( `\n${ path.relative( process.cwd(), f ) }` );
		problems.forEach( ( p ) => console.log( `  ${ p }` ) );
	}
}
console.log(
	bad
		? `\n${ bad } of ${ files.length } patterns will not lay out as written.`
		: `\n${ files.length } patterns: composition OK.`
);
process.exit( bad ? 1 : 0 );
