/**
 * ESLint configuration.
 *
 * Everything comes from @wordpress/scripts; this file exists to carve out the
 * vendored runtime.
 *
 * `src/runtime/` is copied from synced-patterns-for-themes and has to stay
 * logic-identical to it, so that deactivating this plugin hands rendering back
 * to the companion with no change in behaviour. Two rules that arrived with
 * ESLint 9 disagree with how that upstream is written — it documents React
 * return types as `{JSX.Element}` and names the error it does not use in a
 * couple of catch blocks. Neither is a defect worth introducing drift over, so
 * they are turned off there and nowhere else. Anything that is genuinely ours
 * is held to the full set.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		files: [ 'src/runtime/**/*.js' ],
		rules: {
			'jsdoc/no-undefined-types': 'off',
			/*
			 * ESLint 9 changed the default of `caughtErrors` from `none` to
			 * `all`, which is what flags the unused bindings upstream has.
			 * WordPress's own options are kept alongside it, so the rule stays
			 * as strict as everywhere else in every other respect.
			 */
			'no-unused-vars': [
				'error',
				{ ignoreRestSiblings: true, caughtErrors: 'none' },
			],
		},
	},
];
