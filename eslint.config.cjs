const wpPlugin = require('@wordpress/eslint-plugin');

module.exports = [
	{
		ignores: ['build/**', 'node_modules/**', 'vendor/**'],
	},
	...wpPlugin.configs.recommended,
	{
		rules: {
			'@wordpress/no-global-active-element': 'warn',
			'@wordpress/no-unsafe-wp-apis': 'warn',
		},
	},
];
