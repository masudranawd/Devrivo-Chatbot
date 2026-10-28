/**
 * Extends the @wordpress/scripts webpack config with our three entry points.
 *
 * `--webpack-src-dir=assets-src` (see package.json) keeps the default config
 * from scanning the PHP `src/` folder for block files.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'assets-src/admin/index.js' ),
		'widget-loader': path.resolve( __dirname, 'assets-src/widget/loader.js' ),
		widget: path.resolve( __dirname, 'assets-src/widget/widget.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
