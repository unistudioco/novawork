/**
 * Where add-ons plug into the app (1.3.0).
 *
 * Once the app has started, it fires "nw:ready" on window. Its detail (also
 * kept at window.novawork.app for a script that runs later) holds the live
 * store, the API helpers, a few helpers for building markup, and three places
 * to add to: a header button, a row in the team menu and a notification
 * kind. Nothing is added unless an add-on asks, so the app looks exactly the
 * same without one.
 */
import { safeEffect } from './effect.js';
import { ic, icf } from './icons.js';
import { esc } from './format.js';
import { __, sprintf } from './i18n.js';
import { get, post, patch, del, currentNonce } from './api.js';
import { toast } from './ui.js';

const kinds = new Map();

/** An icon from NovaWork's set by name, or an add-on's own SVG markup. */
const iconOf = ( def, size ) => ( def.svg ? String( def.svg ) : ic( def.icon, size ) );

/** A safe id fragment from an add-on's id. */
const idOf = ( id ) => String( id || '' ).replace( /[^a-z0-9_-]/gi, '' );

/**
 * A notification kind an add-on writes (NovaWork\Api::notify()): its icon,
 * colour and wording in the bell and in desktop alerts.
 *
 * @param {string} kind Kind, as passed to notify().
 * @param {Object} def  { icon, color, text( note, { b, esc, who } ) → HTML,
 *                      open( note ) }. text() must escape what it prints
 *                      (b() and esc() do). open() is optional: without it a
 *                      click goes to the note's task, decision or project.
 */
export function addNoteKind( kind, def ) {
	if ( kind && def && typeof def.text === 'function' ) {
		kinds.set( String( kind ), def );
	}
}

/** The add-on definition of a notification kind, or null. */
export const noteKind = ( kind ) => kinds.get( kind ) || null;

/**
 * A button in the header, before the chat button.
 *
 * @param {Object}   def           Button.
 * @param {string}   def.id        Unique id (letters, digits, - and _).
 * @param {string}   def.label     Accessible name and tooltip.
 * @param {string}   [def.icon]    Icon from NovaWork's set, or:
 * @param {string}   [def.svg]     The add-on's own SVG markup.
 * @param {Function} def.onClick   Called with the click event.
 * @param {Function} [def.badge]   Returns true to show the unread dot; it may
 *                                 read the store and redraws when that changes.
 * @return {HTMLButtonElement|null} The button.
 */
export function addHeaderButton( def ) {
	const anchor = document.getElementById( 'nw-chat-btn' );
	const id = idOf( def && def.id );
	if ( ! anchor || ! id || document.getElementById( 'nw-x-' + id ) ) {
		return null;
	}
	const btn = document.createElement( 'button' );
	btn.type = 'button';
	btn.id = 'nw-x-' + id;
	btn.className = 'nw-iconbtn nw-box';
	btn.setAttribute( 'aria-label', String( def.label || '' ) );
	btn.addEventListener( 'click', ( e ) => def.onClick && def.onClick( e ) );
	anchor.before( btn );
	safeEffect( () => {
		btn.innerHTML = iconOf( def, 17 ) + ( def.badge && def.badge() ? '<i class="nw-dot-alert"></i>' : '' );
	} );
	return btn;
}

/**
 * A row in the team menu (the avatars' popover), above "Take the tour".
 *
 * @param {Object}   def         Row.
 * @param {string}   def.id      Unique id (letters, digits, - and _).
 * @param {string}   def.label   Text.
 * @param {string}   [def.icon]  Icon from NovaWork's set, or:
 * @param {string}   [def.svg]   The add-on's own SVG markup.
 * @param {Function} def.onClick Called with the click event; the menu closes first.
 * @return {HTMLButtonElement|null} The row.
 */
export function addMenuItem( def ) {
	const menu = document.querySelector( '.nw-pmenu' );
	const id = idOf( def && def.id );
	if ( ! menu || ! id || document.getElementById( 'nw-xm-' + id ) ) {
		return null;
	}
	const row = document.createElement( 'button' );
	row.type = 'button';
	row.id = 'nw-xm-' + id;
	row.className = 'nw-pmenu-item';
	row.innerHTML = `<span>${ iconOf( def, 18 ) }</span><span>${ esc( def.label || '' ) }</span>`;
	row.addEventListener( 'click', ( e ) => {
		window.dispatchEvent( new CustomEvent( 'nw:team', { detail: { open: false } } ) );
		if ( def.onClick ) {
			def.onClick( e );
		}
	} );
	menu.prepend( row );
	return row;
}

/**
 * Tell add-ons the app is ready.
 *
 * @param {Object} store The live (Alpine) store.
 */
export function announceReady( store ) {
	const app = Object.freeze( {
		store,
		api: Object.freeze( { get, post, patch, del, nonce: currentNonce } ),
		ic,
		icf,
		esc,
		toast,
		__,
		sprintf,
		addHeaderButton,
		addMenuItem,
		addNoteKind,
	} );
	window.novawork = window.novawork || {};
	window.novawork.app = app;
	window.dispatchEvent( new CustomEvent( 'nw:ready', { detail: app } ) );
}
