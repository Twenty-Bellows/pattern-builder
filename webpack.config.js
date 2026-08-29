const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );

defaultConfig[ 0 ] = {
	...defaultConfig[ 0 ],
	plugins: [ ...defaultConfig[ 0 ].plugins, new CleanWebpackPlugin() ],
	...{
		entry: {
			// Pattern management tools loaded on every block-editor screen.
			PatternBuilder_EditorTools: './src/PatternBuilder_EditorTools.js',
			// The core/pattern content runtime, enqueued only when the
			// companion Synced Patterns for Themes plugin is not providing it.
			PatternBuilder_Runtime: './src/PatternBuilder_Runtime.js',
			// The full-screen pattern editor on Appearance → Pattern Builder.
			PatternBuilder_Admin: './src/PatternBuilder_Admin.js',
		},
	},
};

module.exports = defaultConfig;
