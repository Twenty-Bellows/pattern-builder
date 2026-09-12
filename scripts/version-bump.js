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

// `npm run version-bump` bumps the patch number; `npm run version-bump -- 2.2.0`
// sets the version outright, for a minor or major release.
const requested = process.argv[ 2 ];
if ( requested !== undefined && ! /^\d+\.\d+\.\d+$/.test( requested ) ) {
	console.error( `Not a version: ${ requested } (expected major.minor.patch)` );
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
const readmePath = path.join( __dirname, '..', 'readme.txt' );
let readmeContent = fs.readFileSync( readmePath, 'utf8' );
readmeContent = readmeContent.replace(
	/^Stable tag: .+$/m,
	`Stable tag: ${ newVersion }`
);
fs.writeFileSync( readmePath, readmeContent );
console.log( '✓ Updated readme.txt' );
const pluginPath = path.join( __dirname, '..', 'pattern-builder.php' );
let pluginContent = fs.readFileSync( pluginPath, 'utf8' );
pluginContent = pluginContent.replace(
	/^\s*\*\s*Version:\s*.+$/m,
	` * Version: ${ newVersion }`
);
fs.writeFileSync( pluginPath, pluginContent );
console.log( '✓ Updated pattern-builder.php' );

console.log( `\nVersion bump complete! New version: ${ newVersion }` );
