const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );

defaultConfig[ 0 ] = {
	...defaultConfig[ 0 ],
	plugins: [ ...defaultConfig[ 0 ].plugins, new CleanWebpackPlugin() ],
	...{
		entry: {
			PatternBuilder_EditorTools: './src/PatternBuilder_EditorTools.js',
			PatternBuilder_Admin: './src/PatternBuilder_Admin.js',
		},
	},
};

module.exports = defaultConfig;
