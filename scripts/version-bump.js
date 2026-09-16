#!/usr/bin/env node

/* eslint-disable no-console -- CLI tool; console output is its interface. */

const fs = require( 'fs' );
const path = require( 'path' );
const packageJsonPath = path.join( __dirname, '..', 'package.json' );
const packageJson = JSON.parse( fs.readFileSync( packageJsonPath, 'utf8' ) );
const currentVersion = packageJson.version;
const versionParts = currentVersion.split( '.' );
const major = parseInt( versionParts[ 0 ] );
const minor = parseInt( versionParts[ 1 ] );
const patch = parseInt( versionParts[ 2 ] );

/**
 * Replaces one match, and fails loudly when there is not exactly one.
 *
 * A version this script silently declines to write is worse than a crash: it leaves
 * the release looking bumped while one of the places that matters still reads the old
 * number, which is how the constant fell a version behind.
 *
 * @param {string}   contents The file's contents.
 * @param {RegExp}   pattern  What to replace.
 * @param {Function} replacer Called with the match and its capture groups.
 * @param {string}   what     What is being replaced, for the error.
 * @return {string} The new contents.
 */
function replaceOnce( contents, pattern, replacer, what ) {
	if ( ! pattern.test( contents ) ) {
		console.error( `Could not find ${ what }` );
		process.exit( 1 );
	}

	return contents.replace( pattern, replacer );
}

// `npm run version-bump` bumps the patch number; `npm run version-bump -- 2.2.0`
// sets the version outright, for a minor or major release.
const requested = process.argv[ 2 ];
if ( requested !== undefined && ! /^\d+\.\d+\.\d+$/.test( requested ) ) {
	console.error(
		`Not a version: ${ requested } (expected major.minor.patch)`
	);
	process.exit( 1 );
}
const newVersion = requested || `${ major }.${ minor }.${ patch + 1 }`;

console.log( `Bumping version from ${ currentVersion } to ${ newVersion }` );
packageJson.version = newVersion;
fs.writeFileSync(
	packageJsonPath,
	JSON.stringify( packageJson, null, '\t' ) + '\n'
);
console.log( '✓ Updated package.json' );
// Both headers are written in aligned columns, so the padding in front of the old
// value is captured and put back rather than replaced with a single space.
const readmePath = path.join( __dirname, '..', 'readme.txt' );
let readmeContent = fs.readFileSync( readmePath, 'utf8' );
readmeContent = replaceOnce(
	readmeContent,
	/^(Stable tag:[ \t]*)\S.*$/m,
	( _match, label ) => `${ label }${ newVersion }`,
	'Stable tag: in readme.txt'
);
fs.writeFileSync( readmePath, readmeContent );
console.log( '✓ Updated readme.txt' );

// Two places in the plugin file: the header WordPress reads, and the constant the
// plugin reads. They drifted apart once, when only the first was bumped: the tile
// cache key and the per-version upgrade step are both keyed on the constant, so a
// release shipped without either taking effect.
const pluginPath = path.join( __dirname, '..', 'pattern-builder.php' );
let pluginContent = fs.readFileSync( pluginPath, 'utf8' );
pluginContent = replaceOnce(
	pluginContent,
	/^( \* Version:[ \t]*)\S.*$/m,
	( _match, label ) => `${ label }${ newVersion }`,
	'Version: in pattern-builder.php'
);
pluginContent = replaceOnce(
	pluginContent,
	/(define\(\s*'PATTERN_BUILDER_VERSION',\s*')[^']*(')/,
	( _match, before, after ) => `${ before }${ newVersion }${ after }`,
	'PATTERN_BUILDER_VERSION in pattern-builder.php'
);
fs.writeFileSync( pluginPath, pluginContent );
console.log(
	'✓ Updated pattern-builder.php (header and PATTERN_BUILDER_VERSION)'
);

console.log( `\nVersion bump complete! New version: ${ newVersion }` );
