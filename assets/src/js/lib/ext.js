/**
 * Where add-ons plug into the app (1.3.0).
 *
 * Once the app has started, it fires "nw:ready" on window. Its detail (also
 * kept at window.novawork.app for a script that runs later) holds the live
 * store, the API helpers, a few helpers for building markup, and the places
 * to add to: a header button, a row in the team menu, a notification kind, a
 * button on messages and comments, a status line under a thread, and a panel.
 * Nothing is added unless an add-on asks, so the app looks exactly the same
 * without one.
 */
import { safeEffect } from './effect.js';
import { ic, icf } from './icons.js';
import { esc } from './format.js';
import { __, sprintf } from './i18n.js';
import { get, post, patch, del, currentNonce } from './api.js';
import { toast } from './ui.js';
import { addPanel, openPanel } from './panel.js';

const kinds = new Map();
const actions = new Map();
const statuses = new Map();
const statusListeners = new Set();

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
 * A button on chat messages and comments, after Reply / Edit / Delete (1.3.0).
 *
 * @param {Object}   def             Button.
 * @param {string}   def.id          Unique id (letters, digits, - and _).
 * @param {string}   def.label       Accessible name and tooltip.
 * @param {string}   [def.icon]      Icon from NovaWork's set (fill weight when pressed), or:
 * @param {string}   [def.svg]       The add-on's own SVG markup, and
 * @param {string}   [def.svgPressed] its markup when pressed.
 * @param {Function} [def.when]      ( item, kind ) → whether this message or comment
 *                                   gets the button; kind is "message" or "comment".
 * @param {Function} [def.pressed]   ( item, kind ) → whether it shows as pressed
 *                                   (a toggle, with aria-pressed).
 * @param {Function} def.onClick     ( item, kind ); the list redraws once it
 *                                   returns (or its promise settles).
 */
export function addMessageAction( def ) {
	const id = idOf( def && def.id );
	if ( id && typeof def.onClick === 'function' && ! actions.has( id ) ) {
		actions.set( id, def );
	}
}

/** The buttons add-ons put on one message or comment. */
export function messageActions( item, kind ) {
	return [ ...actions.entries() ]
		.filter( ( [ , def ] ) => ! def.when || def.when( item, kind ) )
		.map( ( [ id, def ] ) => {
			const pressed = def.pressed ? !! def.pressed( item, kind ) : null;
			let icon;
			if ( def.svg ) {
				icon = String( pressed && def.svgPressed ? def.svgPressed : def.svg );
			} else {
				icon = pressed ? icf( def.icon, 15 ) : ic( def.icon, 15 );
			}
			return { id, label: String( def.label || '' ), icon, pressed };
		} );
}

/** Run an add-on's button for a message or comment. */
export const runMessageAction = ( id, item, kind ) => {
	const def = actions.get( id );
	return def ? Promise.resolve( def.onClick( item, kind ) ) : Promise.resolve();
};

/** The key of a thread: the team chat, or one object's comments. */
const whereKey = ( where ) => ( where === 'chat' ? 'chat' : where && where.objectType && where.objectId ? `${ where.objectType }:${ Number( where.objectId ) }` : '' );

/**
 * A quiet status line above a thread's text field, such as "Helper is
 * thinking…" (1.3.0). Text is set as text, never HTML; null clears it.
 *
 * @param {string|Object} where "chat", or { objectType, objectId } for comments.
 * @param {string|null}   text  The line, or null.
 */
export function setThreadStatus( where, text ) {
	const key = whereKey( where );
	if ( ! key ) {
		return;
	}
	const now = text ? String( text ) : '';
	if ( ( statuses.get( key ) || '' ) === now ) {
		return;
	}
	if ( now ) {
		statuses.set( key, now );
	} else {
		statuses.delete( key );
	}
	statusListeners.forEach( ( fn ) => fn( key ) );
}

/** The status line of a thread ("chat" or "task:12"), or ''. */
export const threadStatus = ( key ) => statuses.get( key ) || '';

/** Draw a thread's status line into its element (hidden when there is none). */
function paintStatus( el, key ) {
	const text = threadStatus( key );
	el.hidden = ! text;
	el.innerHTML = text ? `<span class="nw-tdots" aria-hidden="true"><i></i><i></i><i></i></span><span>${ esc( text ) }</span>` : '';
}

/**
 * Keep a status line element up to date (the chat and comment views). It
 * stops once the element leaves the page.
 *
 * @param {HTMLElement} el  The element.
 * @param {string}      key "chat", or "task:12" for an object's comments.
 */
export function watchThreadStatus( el, key ) {
	paintStatus( el, key );
	const fn = ( changed ) => {
		if ( ! el.isConnected ) {
			statusListeners.delete( fn );
		} else if ( changed === key ) {
			paintStatus( el, key );
		}
	};
	statusListeners.add( fn );
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
		addMessageAction,
		setThreadStatus,
		addPanel,
		openPanel,
	} );
	window.novawork = window.novawork || {};
	window.novawork.app = app;
	window.dispatchEvent( new CustomEvent( 'nw:ready', { detail: app } ) );
}
