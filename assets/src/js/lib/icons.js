/**
 * Phosphor icons (MIT), regular and fill, bundled at build time.
 *
 * Same markup as the prototype's ic()/icf()/swap(): an inline <svg class="nw-ic">
 * whose size comes from --nw-is. Fill is used for the "on" state; never duotone.
 */
const strip = ( modules ) => {
	const out = {};
	for ( const [ path, svg ] of Object.entries( modules ) ) {
		const name = path.split( '/' ).pop().replace( /\.svg$/, '' );
		out[ name ] = String( svg ).trim().replace( /^<svg[^>]*>|<\/svg>$/g, '' );
	}
	return out;
};

const REGULAR = strip( import.meta.glob( '../../icons/regular/*.svg', { query: '?raw', import: 'default', eager: true } ) );
const FILL = strip( import.meta.glob( '../../icons/fill/*.svg', { query: '?raw', import: 'default', eager: true } ) );

const svg = ( body, size, cls ) =>
	`<svg class="nw-ic${ cls ? ' ' + cls : '' }"${ size ? ` style="--nw-is:${ size }px"` : '' } viewBox="0 0 256 256" fill="currentColor" aria-hidden="true">${ body || '' }</svg>`;

/** Regular weight. */
export const ic = ( name, size, cls ) => svg( REGULAR[ name ], size, cls );

/** Fill weight (falls back to regular). */
export const icf = ( name, size, cls ) => svg( FILL[ name ] || REGULAR[ name ], size, cls );

/** Both weights stacked, for tabs that cross-fade to fill when on. */
export const swapInner = ( name, size ) => ic( name, size, 'nw-r' ) + icf( name, size, 'nw-f' );
