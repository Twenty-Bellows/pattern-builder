const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

defaultConfig[ 0 ] = {
	...defaultConfig[ 0 ],
	...{
		entry: {
			'pattern-manager': './src/pattern-manager.js',
		},
	},
};

module.exports = defaultConfig;
