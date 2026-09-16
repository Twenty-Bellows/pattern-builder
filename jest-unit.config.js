/**
 * Jest configuration for the unit suite.
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

const babelTransform =
	defaultConfig.transform?.[ '\\.[jt]sx?$' ] ??
	require.resolve( 'babel-jest' );

module.exports = {
	...defaultConfig,
	transform: { '\\.m?[jt]sx?$': babelTransform },
	transformIgnorePatterns: [],
};
