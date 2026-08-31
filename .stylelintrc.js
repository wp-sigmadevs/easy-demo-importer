/**
 * Stylelint config file
 * as configured in package.json under stylelint.extends
 *
 * @docs Stylelint https://stylelint.io/user-guide/
 * @docs StylelintWebpackPlugin: https://webpack.js.org/plugins/stylelint-webpack-plugin/
 * @docs stylelint-scss : https://github.com/kristerkari/stylelint-scss
 * @since 1.0.0
 */

module.exports = {
	// Without this the SCSS is parsed as plain CSS and every `//` comment,
	// nested block and interpolation is reported as a syntax error.
	customSyntax: 'postcss-scss',
	plugins: ['stylelint-scss'],
	rules: {
		'no-empty-source': null,
		// The SCSS dialect of at-rules: @use, @mixin, @include and friends are
		// unknown to the built-in rule, so it defers to the stylelint-scss one.
		'at-rule-no-unknown': null,
		'scss/at-rule-no-unknown': true,
	},
};

// `max-line-length` and `indentation` used to live here. Both were removed in
// stylelint 16 — line length and indentation are Prettier's job now, and leaving
// them configured made every run fail with "Unknown rule" before it linted
// anything.
