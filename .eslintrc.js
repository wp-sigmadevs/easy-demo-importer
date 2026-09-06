/**
 * Eslint config file
 * as configured in package.json under eslintConfig.extends
 *
 * @see BabelJS: https://babeljs.io/
 * @see Webpack babel-loader: https://webpack.js.org/loaders/babel-loader/
 * @see @wordpress/eslint-plugin : https://www.npmjs.com/package/@wordpress/eslint-plugin
 * @since 1.0.0
 */
module.exports = {
	parser: '@babel/eslint-parser',
	env: {
		es6: true,
		browser: true,
		node: true,
		jquery: true,
		amd: true,
	},
	extends: [
		'eslint:recommended',
		'plugin:@wordpress/eslint-plugin/recommended',
	],
	rules: {
		// console.error/warn are this plugin's diagnostic channel: the React error
		// boundary, the clipboard fallbacks and the store's fetch failures all need
		// to reach a developer's console. console.log stays banned.
		'no-console': ['error', { allow: ['error', 'warn'] }],
		// antd's Switch renders <button role="switch">, which is a labelable
		// element, so wrapping it in a <label> both names it and keeps the whole
		// row clickable. The rule cannot see through the component boundary on its
		// own, so it is told which components count as controls - otherwise the
		// only way to satisfy it is to drop the <label>, which silently removes
		// click-to-toggle.
		'jsx-a11y/label-has-associated-control': [
			'error',
			{
				controlComponents: ['Switch'],
			},
		],
		'prettier/prettier': [
			'error',
			{
				endOfLine: 'auto',
			},
		],
	},
	globals: {
		wp: true,
		jQuery: true,
	},
	ignorePatterns: [
		'assets/**/*.js',
		'dist/**/*.js',
		'tests/**/*.js',
		'temp.js',
		'webpack.mix.js',
		'/vendor/**/**/*.js',
		'/node_modules/**/**/*.js',
	],
};
