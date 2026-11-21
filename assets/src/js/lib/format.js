/**
 * Text helpers, ported from the prototype.
 */
import { __, sprintf } from './i18n.js';

export const MIN = 60000;
export const HOUR = 3600000;
export const DAY = 86400000;

/** Escape text for the few places that build HTML strings (icons + text). */
export function esc( s ) {
	return String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] );
}

/** "45m", "3h", "16h 5m"; short drops the minutes. */
export function dur( ms, short ) {
	const m = Math.round( ms / MIN );
	if ( m < 60 ) {
		return sprintf( /* translators: %d: minutes. */ __( '%dm', 'novawork' ), m );
	}
	const h = Math.floor( m / 60 );
	const r = m % 60;
	if ( short || ! r ) {
		return sprintf( /* translators: %d: hours. */ __( '%dh', 'novawork' ), h );
	}
	return sprintf( /* translators: 1: hours, 2: minutes. */ __( '%1$dh %2$dm', 'novawork' ), h, r );
}

/** A running stopwatch: "0:03", "12:40", "1:05:09". */
export function clock( ms ) {
	const s = Math.max( 0, Math.floor( ms / 1000 ) );
	const h = Math.floor( s / 3600 );
	const m = Math.floor( ( s % 3600 ) / 60 );
	const x = s % 60;
	return ( h ? h + ':' + String( m ).padStart( 2, '0' ) : m ) + ':' + String( x ).padStart( 2, '0' );
}

/** Up to two initials. */
export const initials = ( name ) =>
	String( name || '?' ).trim().split( /\s+/ ).slice( 0, 2 ).map( ( w ) => w[ 0 ] || '' ).join( '' ).toUpperCase() || '?';

/** Local midnight of the day containing a time. */
export function dayStart( at ) {
	const d = new Date( at );
	d.setHours( 0, 0, 0, 0 );
	return d.getTime();
}

/** "Saturday 21 November" in the site's language. */
export function longDate( at, locale ) {
	return new Date( at ).toLocaleDateString( locale || undefined, { weekday: 'long', day: 'numeric', month: 'long' } );
}

/** First three words and an ellipsis; the full title goes in a tooltip. */
export function shortTitle( title ) {
	const words = String( title || '' ).split( /\s+/ );
	return words.slice( 0, 3 ).join( ' ' ) + ( words.length > 3 ? '…' : '' );
}

/** "Just now", "5m ago", "3h ago", "2d ago", or a date. */
export function ago( at, now, locale ) {
	const m = Math.round( ( now - at ) / MIN );
	if ( m < 1 ) {
		return __( 'Just now', 'novawork' );
	}
	if ( m < 60 ) {
		return sprintf( /* translators: %d: minutes. */ __( '%dm ago', 'novawork' ), m );
	}
	const h = Math.round( m / 60 );
	if ( h < 24 ) {
		return sprintf( /* translators: %d: hours. */ __( '%dh ago', 'novawork' ), h );
	}
	const d = Math.round( h / 24 );
	if ( d < 7 ) {
		return sprintf( /* translators: %d: days. */ __( '%dd ago', 'novawork' ), d );
	}
	return new Date( at ).toLocaleDateString( locale || undefined, { day: 'numeric', month: 'short' } );
}
