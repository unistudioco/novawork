import { defineConfig } from 'vite';
import { i18nMap } from './vite-i18n-map.js';

// The app's source lives in assets/src and builds to assets/dist, with a
// manifest that PHP reads to enqueue hashed files. The dev server runs on
// :5175 so it can sit beside the studio's other projects (:5173, :5174).
export default defineConfig( {
	root: 'assets/src',
	base: './',
	publicDir: false,
	plugins: [ i18nMap() ],
	build: {
		outDir: '../dist',
		emptyOutDir: true,
		manifest: 'manifest.json',
		sourcemap: false,
		rolldownOptions: {
			input: {
				app: 'assets/src/js/app.js',
				settings: 'assets/src/js/settings.js',
				'admin-kit': 'assets/src/js/admin-kit.js',
			},
		},
	},
	server: {
		port: 5175,
		strictPort: true,
		cors: true,
		origin: 'http://localhost:5175',
	},
} );
