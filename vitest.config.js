import { defineConfig } from 'vitest/config';

/**
 * Vitest config for the React/JS unit tests.
 *
 * Kept separate from the Laravel Mix (webpack) build — Vitest only runs the
 * `tests/js` suite and never touches the production bundle.
 */
export default defineConfig({
	test: {
		environment: 'jsdom',
		include: ['tests/js/**/*.test.js'],
		setupFiles: ['tests/js/setup.js'],
		clearMocks: true,
	},
});
