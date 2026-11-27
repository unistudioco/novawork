/**
 * A Vite plugin that writes assets/dist/i18n.json: the source files inside
 * each bundle, so PHP can load their translations (src/App/Translations.php).
 *
 * It ships with the plugin because vite.config.js imports it. Paths are
 * relative to the folder Vite runs in, and only sources under assets/src/
 * are listed, so an add-on built the same way can use it too (1.3.0).
 */
import { relative, sep } from 'node:path';

/**
 * WordPress looks up a script's translations by the path of the source file
 * each string came from (that is how language packs name their JSON files).
 * Bundles have hashed names and hold many sources, so PHP reads this map to
 * find them (src/App/Translations.php).
 */
export function i18nMap() {
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
