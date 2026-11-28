/**
 * Launchers an add-on adds to the app (1.3.0): a round button in a corner
 * the app manages, so add-ons never collide with the timer, a docked window
 * or the phone's bottom bar.
 *
 * - Desktop: bottom right, moving left of a window docked on the right;
 *   hidden while a dialog or sheet holds the page.
 * - Phones: no floating button; the launcher is a button in the header.
 */
import { ic } from './icons.js';

const idOf = ( id ) => String( id || '' ).replace( /[^a-z0-9_-]/gi, '' );
const iconOf = ( def, size ) => ( def.svg ? String( def.svg ) : ic( def.icon || 'circle', size ) );

function corner() {
	let el = document.getElementById( 'nw-launchers' );
	if ( ! el ) {
		el = document.createElement( 'div' );
		el.id = 'nw-launchers';
		el.className = 'nw-launchers';
		const fabs = document.getElementById( 'nw-fabs' );
		( fabs ? fabs.parentElement : document.body ).appendChild( el );
	}
	return el;
}

function badgeHTML( value ) {
	if ( ! value ) {
		return '';
	}
	const n = typeof value === 'number' ? Math.min( 99, Math.max( 0, Math.floor( value ) ) ) : 0;
	return n > 0 ? `<i class="nw-launch-badge" aria-hidden="true">${ n }</i>` : '<i class="nw-dot-alert" aria-hidden="true"></i>';
}

/**
 * Add a launcher.
 *
 * @param {Object}   def         Launcher.
 * @param {string}   def.id      Unique id (letters, digits, - and _).
 * @param {string}   def.label   Its name (the tooltip and screen readers).
 * @param {string}   [def.icon]  Icon from NovaWork's set, or:
 * @param {string}   [def.svg]   The add-on's own SVG markup (an avatar).
 * @param {string}   [def.key]   A keyboard shortcut to show in its tooltip.
 * @param {Function} def.onClick Called with the click event.
 * @return {Object|null} { setBadge( number|boolean ), setHidden( boolean ), remove() }, or null.
 */
export function addLauncher( def ) {
	const id = idOf( def && def.id );
	if ( ! id || typeof def.onClick !== 'function' || document.getElementById( 'nw-launch-' + id ) ) {
		return null;
	}
	const label = String( def.label || '' );
	const make = ( cls, size, elId ) => {
		const btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.id = elId;
		btn.className = cls;
		btn.setAttribute( 'aria-label', label );
		if ( def.key ) {
			btn.dataset.tipKey = String( def.key ).toUpperCase();
		}
		btn.innerHTML = `<span class="nw-launch-ic">${ iconOf( def, size ) }</span>`;
		btn.addEventListener( 'click', ( e ) => def.onClick( e ) );
		return btn;
	};
	const float = make( 'nw-launch', 22, 'nw-launch-' + id );
	corner().appendChild( float );
	const head = make( 'nw-iconbtn nw-box nw-launch-head', 17, 'nw-launch-h-' + id );
	const anchor = document.getElementById( 'nw-chat-btn' );
	if ( anchor ) {
		anchor.before( head );
	}
	const both = [ float, head ];
	return {
		setBadge( value ) {
			both.forEach( ( btn ) => {
				const old = btn.querySelector( '.nw-launch-badge, .nw-dot-alert' );
				if ( old ) {
					old.remove();
				}
				btn.insertAdjacentHTML( 'beforeend', badgeHTML( value ) );
			} );
		},
		setHidden( hidden ) {
			both.forEach( ( btn ) => {
				btn.hidden = !! hidden;
			} );
		},
		remove() {
			both.forEach( ( btn ) => btn.remove() );
		},
	};
}
