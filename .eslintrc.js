/**
 * ESLint config — standalone (no @wordpress/scripts dependency).
 *
 * `import/core-modules` whitelists the exact-match `@newspack-nodes/*` aliases
 * (build alias + jest moduleNameMapper resolve them at runtime); the subpath
 * `@newspack-nodes/shared/*` is whitelisted via the no-unresolved `ignore`.
 */
module.exports = {
	root: true,
	extends: [
		'plugin:@wordpress/eslint-plugin/recommended',
		'plugin:@wordpress/eslint-plugin/i18n',
	],
	rules: {
		'@wordpress/i18n-text-domain': [
			'error',
			{ allowedTextDomain: [ 'newspack-intelligence' ] },
		],
		// The Message TYPE field is a bitmask; bitwise ops are idiomatic here.
		'no-bitwise': 'off',
		// warn/error are real logging; still flag stray console.log/debug/info.
		'no-console': [ 'error', { allow: [ 'warn', 'error' ] } ],
		// `_`-prefixed args are intentionally unused (override parity).
		'no-unused-vars': [
			'error',
			{ ignoreRestSiblings: true, argsIgnorePattern: '^_' },
		],
		'react/forbid-component-props': [
			'error',
			{
				forbid: [
					{
						propName: 'isSmall',
						message: 'Deprecated in WP 6.2 — use size="small".',
					},
				],
			},
		],
		// `@newspack-nodes/shared/*` resolves at runtime (esbuild + jest).
		'import/no-unresolved': [
			'error',
			{ ignore: [ '^@newspack-nodes/shared/' ] },
		],
	},
	overrides: [
		{
			files: [ '**/@(test|__tests__)/**/*.js', '**/?(*.)test.js' ],
			extends: [ 'plugin:@wordpress/eslint-plugin/test-unit' ],
		},
		{
			// Build/CLI scripts run under Node; console logging is fine.
			files: [ 'scripts/**/*.mjs' ],
			env: { node: true },
			rules: {
				'no-console': 'off',
				'jsdoc/require-param': 'off',
			},
		},
	],
	settings: {
		'import/core-modules': [
			'@newspack-nodes/runtime',
			'@newspack-nodes/debug-overlay',
		],
	},
};
