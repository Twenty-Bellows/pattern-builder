const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require('clean-webpack-plugin');

defaultConfig[ 0 ] = {
	...defaultConfig[ 0 ],
	plugins: [
		...defaultConfig[0].plugins,
		new CleanWebpackPlugin(),
	],
	...{
		entry: {
			'PatternManager_EditorTools': './src/PatternManager_EditorTools.js',
			'PatternManager_Admin': './src/PatternManager_Admin.js',
		},
	},
};

module.exports = defaultConfig;
