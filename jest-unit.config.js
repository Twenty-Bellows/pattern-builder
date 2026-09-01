/**
 * Jest configuration for the unit suite.
 *
 * This exists for one reason, and it is a packaging problem rather than
 * anything about these tests. Much of the WordPress dependency tree now ships
 * ES modules — `uuid` inside `@wordpress/components`, `@wordpress/ui` and
 * `@wordpress/theme` as `.mjs`, `marked`, and more behind those — and Jest
 * cannot `require()` an ES module before Node 24.9. Two defaults conspire to
 * make that fatal: Jest transforms nothing inside `node_modules`, and the
 * transform it does apply matches `.js`/`.ts` but not `.mjs`. Either one
 * leaves a module as ESM and the suite fails to *load*, rather than failing a
 * test.
 *
 * So Babel is pointed at `.mjs` as well, and nothing is exempt. Naming the
 * offending packages instead was tried and is a losing game: they are nested
 * (`@wordpress/components/node_modules/uuid`), they pull each other in, and
 * the list changes with every dependency bump. Compiling everything costs a
 * few seconds on a cold cache and is stable.
 *
 * Everything else comes from @wordpress/scripts, so this stays in step with
 * the toolchain rather than replacing it.
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
