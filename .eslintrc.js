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
