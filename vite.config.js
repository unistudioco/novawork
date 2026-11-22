import { defineConfig } from 'vite';
import { relative, sep } from 'node:path';

/**
 * Write assets/dist/i18n.json: the source files inside each bundle.
 *
 * WordPress looks up a script's translations by the path of the source file
 * each string came from (that is how language packs name their JSON files).
 * Bundles have hashed names and hold many sources, so PHP reads this map to
 * find them (src/App/Translations.php).
 */
function i18nMap() {
	return {
		name: 'novawork-i18n-map',
		generateBundle( _options, bundle ) {
			const chunks = Object.values( bundle ).filter( ( c ) => c.type === 'chunk' );
			const byFile = new Map( chunks.map( ( c ) => [ c.fileName, c ] ) );
			const map = {};
			for ( const entry of chunks.filter( ( c ) => c.isEntry ) ) {
				const files = new Set();
				const seen = new Set();
				const walk = ( chunk ) => {
					if ( ! chunk || seen.has( chunk.fileName ) ) {
						return;
					}
					seen.add( chunk.fileName );
					for ( const id of chunk.moduleIds ) {
						const path = relative( process.cwd(), id ).split( sep ).join( '/' );
						if ( path.startsWith( 'assets/src/' ) && path.endsWith( '.js' ) ) {
							files.add( path );
						}
					}
					chunk.imports.forEach( ( file ) => walk( byFile.get( file ) ) );
				};
				walk( entry );
				map[ entry.name ] = [ ...files ].sort();
			}
			this.emitFile( { type: 'asset', fileName: 'i18n.json', source: JSON.stringify( map, null, '\t' ) + '\n' } );
		},
	};
}

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
