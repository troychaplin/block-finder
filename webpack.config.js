const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
	...defaultConfig,
	entry: {
		'block-finder': [path.resolve(__dirname, 'src/script.ts')],
	},
	output: {
		path: path.resolve(__dirname, 'build'),
		filename: '[name].js',
	},
};
