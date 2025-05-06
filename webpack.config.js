const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

defaultConfig[ 0 ] = {
	...defaultConfig[ 0 ],
	...{
		entry: {
			'pattern-manager-editor-tools': './src/pattern-manager-editor-tools.js',
		},
	},
};

module.exports = defaultConfig;
