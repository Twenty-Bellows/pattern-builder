#!/usr/bin/env node
/**
 * Check that the documentation still describes the code.
 *
 * Every backticked token in CLAUDE.md, readme.md and docs/ that looks like a
 * file path or a code identifier is checked against the source: a path has to
 * exist, and a symbol has to appear somewhere in the tree. That catches the
 * way these documents actually rot — a class that was renamed, a method that
 * was never built, a file that moved — which reading them does not.
 *
 * It cannot check prose. A sentence can be wrong with every identifier in it
 * spelled correctly, so a green run means the names are real, not that the
 * document is true.
 *
 * Usage: npm run check:docs
 *        npm run check:docs -- --list      print every token it checked
 *
 * A line carrying `check-docs:ignore` is skipped. Names this repository only
 * talks about — WordPress core, another repository, an illustrative path —
 * belong in ALLOW below.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();
const DOCS = [ 'CLAUDE.md', 'readme.md' ];
const SOURCE_EXT = [ '.php', '.js', '.mjs', '.jsx', '.json', '.scss', '.css', '.txt' ];

/** Named here but defined elsewhere: core, another repository, an example. */
const ALLOW = new Set( [
	// WordPress core, its packages, and the browser.
	'edit-form-blocks.php', 'wp.editPost.initializeEditor', 'script-loader-packages.php',
	'ReactJSXRuntime', 'addSaveProps', 'globalThis.React', 'history.replaceState',
	'WP_Font_Face_Resolver::get_fonts_from_theme_json()', 'wp_print_font_faces()',
	'WP_Theme_JSON_Resolver::get_style_variations()',
	'WP_Theme_JSON::process_blocks_custom_css()', 'WP_Image_Editor::get_output_format()',
	'registerBlockBindingsSource()', 'onNavigateToEntityRecord', 'MainDashboardButton',
	// The host environment and other repositories.
	'sqlite-database-integration', 'db.copy', 'wp-tests-config.php', 'wp-content/db.php',
	'DB_DIR', 'DB_FILE', 'includes/patterns/class-safe-css.php', 'docs/decisions.md',
	// Illustrative, and one service nobody should be pointing a pattern at.
	'patterns/studio-a/heroes/hero.php', 'my-theme/hero', 'studio-a/heroes/hero',
	'placehold.co',
	// A theme's own directory, a block's own manifest, and the theme.json
	// property that happens to be spelled like a stylesheet.
	'patterns/', 'block.json', 'styles.css',
] );

const TOKEN = /`([^`\n]{3,90})`/g;
const LOOKS_LIKE_CODE = /^[A-Za-z_][\w:/.\\-]*(\(\))?$/;
const FILE_EXT = /\.(php|js|mjs|cjs|jsx|json|md|txt|scss|css|xml|ya?ml|dist)$/;

function tracked() {
	return execFileSync( 'git', [ 'ls-files' ], { cwd: ROOT, encoding: 'utf8' } )
		.split( '\n' )
		.filter( Boolean );
}

// Everything tracked answers "does this path exist"; the searchable subset
// answers "is this symbol real". A doc may legitimately point at `vendor/`.
const everything = tracked();
const files = everything.filter( ( f ) => ! /(^|\/)(vendor|node_modules|build|svn)\//.test( f ) );
const paths = new Set( everything );
const docs = [ ...DOCS, ...files.filter( ( f ) => f.startsWith( 'docs/' ) && f.endsWith( '.md' ) ) ]
	.filter( ( f ) => fs.existsSync( path.join( ROOT, f ) ) );

const haystack = files
	.filter( ( f ) => SOURCE_EXT.includes( path.extname( f ) ) && ! f.startsWith( 'docs/' ) )
	.map( ( f ) => {
		try {
			return fs.readFileSync( path.join( ROOT, f ), 'utf8' );
		} catch {
			return '';
		}
	} )
	.join( '\n' );

const listing = process.argv.includes( '--list' );
const problems = [];
let checked = 0;

for ( const doc of docs ) {
	const lines = fs.readFileSync( path.join( ROOT, doc ), 'utf8' ).split( '\n' );
	lines.forEach( ( line, i ) => {
		if ( line.includes( 'check-docs:ignore' ) ) {
			return;
		}
		for ( const match of line.matchAll( TOKEN ) ) {
			const token = match[ 1 ].trim();
			if ( ALLOW.has( token ) || token.includes( '://' ) || ! LOOKS_LIKE_CODE.test( token ) ) {
				continue;
			}

			checked++;

			// A directory: something tracked has to live under it.
			if ( token.endsWith( '/' ) ) {
				if ( listing ) {
					console.log( `  dir    ${ token }` );
				}
				// Plugin-relative too: `build/` is real, at wp-content/plugins/x/build/.
				if ( ! everything.some( ( f ) => f.startsWith( token ) || f.includes( '/' + token ) ) ) {
					problems.push( [ doc, i + 1, token, 'no such directory' ] );
				}
				continue;
			}

			// A file, by full path or by name.
			if ( FILE_EXT.test( token ) ) {
				if ( listing ) {
					console.log( `  file   ${ token }` );
				}
				const base = '/' + token;
				if ( ! paths.has( token ) && ! everything.some( ( f ) => f === token || f.endsWith( base ) ) ) {
					problems.push( [ doc, i + 1, token, 'no such file' ] );
				}
				continue;
			}

			// A dotted key (theme.json, a settings path, a JS global): the
			// whole string never appears in the source, but its leaf does.
			const probe = token.includes( '.' ) && ! token.includes( '/' )
				? token.split( '.' ).pop()
				: token.replace( /\(\)$/, '' ).split( '::' ).pop();

			if ( listing ) {
				console.log( `  symbol ${ token }` );
			}
			if ( ! haystack.includes( probe ) ) {
				problems.push( [ doc, i + 1, token, 'not in the source' ] );
			}
		}
	} );
}

for ( const [ doc, line, token, why ] of problems ) {
	console.error( `${ doc }:${ line }  \`${ token }\` — ${ why }` );
}

console.log(
	`\n${ checked } identifiers checked across ${ docs.length } documents, ${ problems.length } unresolved.`
);
if ( problems.length ) {
	console.error(
		'\nEither the documentation is stale, or the name belongs in ALLOW in scripts/check-docs.mjs.'
	);
	process.exit( 1 );
}
