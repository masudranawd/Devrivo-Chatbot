/**
 * Vitest config for the widget and admin unit tests (run with npm run test:js).
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.{js,jsx}' ],
	},
} );
