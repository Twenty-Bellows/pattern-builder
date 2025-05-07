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
			'pattern-manager-editor-tools': './src/pattern-manager-editor-tools.js',
			'pattern-manager-admin': './src/pattern-manager-admin.js',
		},
	},
};

module.exports = defaultConfig;
