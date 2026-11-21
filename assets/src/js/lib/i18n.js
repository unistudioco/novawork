/**
 * WordPress's i18n functions (the wp-i18n script, with NovaWork's
 * translations loaded by wp_set_script_translations()).
 *
 * Always pass the 'novawork' text domain as a literal so `wp i18n make-pot`
 * can find the strings.
 */
const i18n = ( window.wp && window.wp.i18n ) || {
	__: ( text ) => text,
	_x: ( text ) => text,
	_n: ( one, many, n ) => ( n === 1 ? one : many ),
	sprintf: ( format, ...args ) => {
		let i = 0;
		return String( format ).replace( /%(?:(\d+)\$)?[sd]/g, ( m, pos ) => String( args[ pos ? pos - 1 : i++ ] ) );
	},
};

export const { __, _x, _n, sprintf } = i18n;
