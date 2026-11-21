import { defineConfig } from 'vite';

// The app's source lives in assets/src and builds to assets/dist, with a
// manifest that PHP reads to enqueue hashed files. The dev server runs on
// :5175 so it can sit beside the studio's other projects (:5173, :5174).
export default defineConfig( {
	root: 'assets/src',
	base: './',
	publicDir: false,
	build: {
		outDir: '../dist',
		emptyOutDir: true,
		manifest: 'manifest.json',
		sourcemap: false,
		rolldownOptions: {
			input: {
				app: 'assets/src/js/app.js',
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
