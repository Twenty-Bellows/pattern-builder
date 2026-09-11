#!/usr/bin/env node
/**
 * Diff two pages' generated global-styles CSS, rule by rule.
 *
 * WordPress writes the whole resolved design system into one inline
 * stylesheet, so comparing that is comparing the design systems themselves:
 * a difference here is a difference in what was installed, not in how a
 * pattern used it. No browser and nothing to install.
 *
 *   node compare-design-system.mjs <source-url> <mine-url>
 */

const ID = 'global-styles-inline-css';

/** The generated global-styles stylesheet, out of a page's HTML. */
async function stylesheet( url ) {
	const res = await fetch( url, { redirect: 'follow' } );
	if ( ! res.ok ) {
		throw new Error( `${ url } answered ${ res.status }` );
	}
	const html = await res.text();
	const block = new RegExp( `<style[^>]*id=['"]${ ID }['"][^>]*>([\\s\\S]*?)</style>`, 'g' );
	const found = [ ...html.matchAll( block ) ].map( ( m ) => m[ 1 ] ).join( '\n' );

	if ( ! found ) {
		throw new Error(
			`${ url } has no #${ ID }. Either it is not a block theme, or the theme dequeued it.`
		);
	}
	return found;
}

/**
 * Compare values as CSS means them, not as they were typed.
 *
 * `#17120E` and `#17120e` are the same colour, and a difference in case or in
 * spacing inside a function is not a finding — reporting it buries the ones
 * that are.
 */
function normalize( value ) {
	return value
		.replace( /#[0-9a-f]{3,8}\b/gi, ( hex ) => hex.toLowerCase() )
		.replace( /\s*,\s*/g, ',' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/** Selector -> { property: value }, flattened across repeated selectors. */
function rules( css ) {
	const out = new Map();
	for ( const m of css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ) {
		const selector = m[ 1 ].replace( /\s+/g, ' ' ).trim();
		const declarations = out.get( selector ) || new Map();
		for ( const declaration of m[ 2 ].split( ';' ) ) {
			const at = declaration.indexOf( ':' );
			if ( at < 1 ) {
				continue;
			}
			declarations.set( declaration.slice( 0, at ).trim(), normalize( declaration.slice( at + 1 ) ) );
		}
		if ( declarations.size ) {
			out.set( selector, declarations );
		}
	}
	return out;
}

const [ sourceUrl, mineUrl ] = process.argv.slice( 2 );

if ( ! sourceUrl || ! mineUrl ) {
	console.error( 'usage: compare-design-system.mjs <source-url> <mine-url>' );
	process.exit( 2 );
}

const [ source, mine ] = await Promise.all( [
	stylesheet( sourceUrl ).then( rules ),
	stylesheet( mineUrl ).then( rules ),
] );

console.log( `source: ${ source.size } selectors   mine: ${ mine.size } selectors\n` );

let differing = 0;

for ( const [ selector, want ] of source ) {
	const got = mine.get( selector );
	if ( ! got ) {
		continue; // Reported below, as a selector only one side has.
	}

	/*
	 * A property whose value differs is nearly always a mistake in the
	 * reproduction. A property one side does not have at all is usually a
	 * deliberate difference between the themes — a blank theme strips core's
	 * default palette — so it is counted rather than listed.
	 */
	const changed = [ ...want ].filter(
		( [ property, value ] ) => got.has( property ) && got.get( property ) !== value
	);
	const absent = [ ...want.keys() ].filter( ( property ) => ! got.has( property ) );
	const added = [ ...got.keys() ].filter( ( property ) => ! want.has( property ) );

	if ( ! changed.length && ! absent.length && ! added.length ) {
		continue;
	}

	if ( changed.length ) {
		differing++;
	}

	console.log( selector );
	for ( const [ property, value ] of changed ) {
		console.log( `   ${ property }: ${ value }   ->   ${ got.get( property ) }` );
	}
	if ( absent.length ) {
		console.log( `   (${ absent.length } propert${ absent.length === 1 ? 'y' : 'ies' } the source has and mine does not: ${ absent.slice( 0, 4 ).join( ', ' ) }${ absent.length > 4 ? ', …' : '' })` );
	}
	if ( added.length ) {
		console.log( `   (${ added.length } only in mine: ${ added.slice( 0, 4 ).join( ', ' ) }${ added.length > 4 ? ', …' : '' })` );
	}
	console.log();
}

const onlySource = [ ...source.keys() ].filter( ( s ) => ! mine.has( s ) );
const onlyMine = [ ...mine.keys() ].filter( ( s ) => ! source.has( s ) );

console.log( `${ differing } shared selectors have a property whose value differs — those are the findings.` );

/*
 * A selector on one side only is usually a preset the other does not define —
 * often core's default palette, which a deliberately blank theme strips. Worth
 * showing, but it is noise far more often than the shared-selector diff above.
 */
for ( const [ label, list ] of [ [ 'only on the source', onlySource ], [ 'only in mine', onlyMine ] ] ) {
	if ( ! list.length ) {
		continue;
	}
	console.log( `\n${ list.length } selectors ${ label }:` );
	for ( const selector of list.slice( 0, 25 ) ) {
		console.log( '   ' + selector.slice( 0, 140 ) );
	}
	if ( list.length > 25 ) {
		console.log( `   … and ${ list.length - 25 } more` );
	}
}

process.exit( differing ? 1 : 0 );
