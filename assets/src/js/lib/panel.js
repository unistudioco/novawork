/**
 * A panel an add-on opens in the app (1.3.0).
 *
 * - The default style is the app's dialog on desktop, a bottom sheet with a
 *   grab handle on phones (swipe down, Escape or a tap outside closes it),
 *   with the page behind it locked, like the app's own dialogs. One shows at
 *   a time.
 * - style "docked" is a window like the team chat: docked on the right or
 *   left on wide screens (the page makes room), on the other side when the
 *   team chat is docked there, floating in a corner below 1100px, and a
 *   bottom sheet on phones. Its content is drawn once and kept, so a
 *   conversation keeps its place; onOpen runs at each open. One docked
 *   panel shows at a time.
 */
import { ic } from './icons.js';
import { __ } from './i18n.js';
import { esc } from './format.js';
import { animatesSheets, isPhone, lockBgScroll, unlockBgScroll, sheetOut, swipeToClose, placeInd } from './ui.js';

const panels = new Map();
let dlg = null;
let nativeClose = null;

const idOf = ( id ) => String( id || '' ).replace( /[^a-z0-9_-]/gi, '' );

function mount() {
	if ( dlg ) {
		return dlg;
	}
	dlg = document.createElement( 'dialog' );
	dlg.id = 'nw-xpanel';
	dlg.className = 'nw-xpanel';
	dlg.setAttribute( 'aria-labelledby', 'nw-xpanel-title' );
	dlg.innerHTML =
		`<div class="nw-dlg"><div class="nw-sgrab" data-grab aria-hidden="true"></div>` +
		`<div class="nw-dlg-head"><h2 id="nw-xpanel-title"></h2><button class="nw-iconbtn" type="button" data-close aria-label="${ esc( __( 'Close', 'novawork' ) ) }">${ ic( 'x', 18 ) }</button></div>` +
		`<div class="nw-xpanel-body"></div></div>`;
	// Next to the app's own dialog, so the app's styles and theme apply.
	const anchor = document.getElementById( 'nw-dlg' );
	( anchor ? anchor.parentElement : document.body ).appendChild( dlg );

	nativeClose = dlg.close.bind( dlg );
	dlg.close = () => {
		if ( dlg.open && ! dlg.classList.contains( 'nw-sheet-out' ) ) {
			sheetOut( dlg, nativeClose );
		}
	};
	dlg.addEventListener( 'close', () => {
		unlockBgScroll();
		dlg.dataset.panel = '';
	} );
	swipeToClose( dlg, ( instant ) => ( instant ? nativeClose() : dlg.close() ) );
	dlg.addEventListener( 'cancel', ( e ) => {
		if ( animatesSheets() ) {
			e.preventDefault();
			dlg.close();
		}
	} );
	// A backdrop click must start on the backdrop too (text selected inside
	// and released outside lands on the dialog itself).
	let downOnBackdrop = false;
	dlg.addEventListener( 'pointerdown', ( e ) => {
		downOnBackdrop = e.target === dlg;
	} );
	dlg.addEventListener( 'click', ( e ) => {
		const fromBackdrop = downOnBackdrop;
		downOnBackdrop = false;
		if ( ( e.target === dlg && fromBackdrop ) || e.target.closest( '[data-close]' ) ) {
			dlg.close();
		}
	} );
	return dlg;
}

/**
 * Register a panel.
 *
 * @param {Object}   def           Panel.
 * @param {string}   def.id        Unique id (letters, digits, - and _).
 * @param {string}   def.title     Its title (plain text).
 * @param {Function} def.render    ( body ) → draws the panel into the element;
 *                                 it runs each time a dialog panel opens, and
 *                                 once for a docked one.
 * @param {string}   [def.style]   "dialog" (default) or "docked" (1.3.0).
 * @param {Function} [def.onOpen]  ( body ) → each time it opens (docked).
 * @param {Function} [def.onClose] Each time it closes (docked).
 */
export function addPanel( def ) {
	const id = idOf( def && def.id );
	if ( id && typeof def.render === 'function' && ! panels.has( id ) ) {
		panels.set( id, def );
	}
}

/**
 * Open a registered panel.
 *
 * @param {string} id The panel's id.
 * @return {boolean} Whether it opened.
 */
export function openPanel( id ) {
	const def = panels.get( idOf( id ) );
	if ( ! def ) {
		return false;
	}
	if ( 'docked' === def.style ) {
		return openDock( idOf( id ), def );
	}
	const el = mount();
	el.dataset.panel = idOf( id );
	el.querySelector( '#nw-xpanel-title' ).textContent = String( def.title || '' );
	const body = el.querySelector( '.nw-xpanel-body' );
	body.textContent = '';
	def.render( body );
	if ( ! el.open ) {
		lockBgScroll();
		el.showModal();
	}
	return true;
}

/**
 * Close a panel (docked or dialog).
 *
 * @param {string} id The panel's id.
 */
export function closePanel( id ) {
	const key = idOf( id );
	if ( docks.has( key ) ) {
		closeDock( key );
	} else if ( dlg && dlg.open && dlg.dataset.panel === key ) {
		dlg.close();
	}
}

/**
 * Whether a panel is open.
 *
 * @param {string} id The panel's id.
 * @return {boolean} Open or not.
 */
export function isPanelOpen( id ) {
	const key = idOf( id );
	const d = docks.get( key );
	if ( d ) {
		return ! d.el.hidden;
	}
	return !! ( dlg && dlg.open && dlg.dataset.panel === key );
}

/* ---------- docked panels (1.3.0) ---------- */

/** Below this width a docked panel floats instead (the page keeps its width). */
const DOCK_MIN = 1100;
const docks = new Map();
let chatState = { open: false, side: 'right' };

const sidePref = ( id, value ) => {
	try {
		if ( value === undefined ) {
			return window.localStorage.getItem( 'nw.dock.' + id ) === 'left' ? 'left' : 'right';
		}
		window.localStorage.setItem( 'nw.dock.' + id, value );
	} catch ( e ) {}
	return 'right';
};

window.addEventListener( 'nw:chat-state', ( e ) => {
	chatState = { open: !! ( e.detail && e.detail.open ), side: ( e.detail && e.detail.side ) || 'right' };
	docks.forEach( ( d, id ) => placeDock( id ) );
} );
window.addEventListener( 'resize', () => docks.forEach( ( d, id ) => placeDock( id ) ) );

function mountDock( id, def ) {
	const el = document.createElement( 'aside' );
	el.className = 'nw-drawer nw-xdock';
	el.id = 'nw-xdock-' + id;
	el.hidden = true;
	el.setAttribute( 'aria-label', String( def.title || '' ) );
	el.innerHTML =
		`<div class="nw-grab" data-grab aria-hidden="true"></div>` +
		`<header><h2>${ esc( String( def.title || '' ) ) }</h2>` +
		`<button class="nw-iconbtn" type="button" data-dock></button>` +
		`<button class="nw-iconbtn" type="button" data-close aria-label="${ esc( __( 'Close', 'novawork' ) ) }">${ ic( 'x', 16 ) }</button></header>` +
		`<div class="nw-xdock-body"></div>`;
	const scrim = document.createElement( 'div' );
	scrim.className = 'nw-sheet-scrim nw-xdock-scrim';
	scrim.hidden = true;
	const anchor = document.getElementById( 'nw-chat' );
	const parent = anchor ? anchor.parentElement : document.body;
	parent.appendChild( scrim );
	parent.appendChild( el );
	el.querySelector( '[data-close]' ).addEventListener( 'click', () => closeDock( id ) );
	el.querySelector( '[data-dock]' ).addEventListener( 'click', () => {
		sidePref( id, el.classList.contains( 'nw-dock-left' ) ? 'right' : 'left' );
		placeDock( id );
	} );
	scrim.addEventListener( 'click', () => closeDock( id ) );
	el.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && ! e.defaultPrevented ) {
			e.preventDefault();
			closeDock( id );
		}
	} );
	swipeToClose( el, () => closeDock( id, true ) );
	const d = { el, scrim, def, rendered: false, locked: false, back: null };
	docks.set( id, d );
	return d;
}

function placeDock( id ) {
	const d = docks.get( id );
	if ( ! d ) {
		return;
	}
	const { el, scrim } = d;
	el.classList.remove( 'nw-dock-left', 'nw-dock-right', 'nw-float', 'nw-xfloat-left', 'nw-xfloat-right' );
	document.body.classList.remove( 'nw-xdocked-left', 'nw-xdocked-right' );
	const phone = isPhone();
	const lock = ! el.hidden && phone;
	scrim.hidden = ! lock;
	if ( lock && ! d.locked ) {
		d.locked = true;
		lockBgScroll();
	} else if ( ! lock && d.locked ) {
		d.locked = false;
		unlockBgScroll();
	}
	const dock = el.querySelector( '[data-dock]' );
	if ( el.hidden ) {
		return;
	}
	if ( phone ) {
		el.classList.add( 'nw-dock-right' );
		dock.hidden = true;
		return;
	}
	const chatDocked = chatState.open && ( chatState.side === 'left' || chatState.side === 'right' );
	if ( window.innerWidth < DOCK_MIN ) {
		// A floating window, in the corner away from a docked chat.
		el.classList.add( 'nw-float', chatDocked && chatState.side === 'right' ? 'nw-xfloat-left' : 'nw-xfloat-right' );
		dock.hidden = true;
		return;
	}
	let side = sidePref( id );
	if ( chatDocked && chatState.side === side ) {
		side = side === 'right' ? 'left' : 'right';
	}
	el.classList.add( 'nw-dock-' + side );
	document.body.classList.add( 'nw-xdocked-' + side );
	dock.hidden = false;
	dock.innerHTML = ic( side === 'left' ? 'arrow-right' : 'arrow-left', 16 );
	dock.setAttribute( 'aria-label', side === 'left' ? __( 'Dock on the right', 'novawork' ) : __( 'Dock on the left', 'novawork' ) );
	setTimeout( () => document.querySelectorAll( '.nw-tabs' ).forEach( ( n ) => placeInd( n, true ) ), 320 );
}

function openDock( id, def ) {
	docks.forEach( ( other, key ) => key !== id && ! other.el.hidden && closeDock( key ) );
	const d = docks.get( id ) || mountDock( id, def );
	const body = d.el.querySelector( '.nw-xdock-body' );
	if ( ! d.rendered ) {
		d.rendered = true;
		def.render( body );
	}
	if ( d.el.hidden ) {
		d.back = document.activeElement;
		d.el.hidden = false;
		placeDock( id );
		if ( typeof def.onOpen === 'function' ) {
			def.onOpen( body );
		}
		const focus = body.querySelector( '[autofocus], textarea, input' );
		if ( focus && ! isPhone() ) {
			focus.focus();
		}
	}
	return true;
}

function closeDock( id, instant ) {
	const d = docks.get( id );
	if ( ! d || d.el.hidden ) {
		return;
	}
	const finish = () => {
		d.el.style.transform = '';
		d.el.style.transition = '';
		d.el.hidden = true;
		placeDock( id );
		if ( typeof d.def.onClose === 'function' ) {
			d.def.onClose();
		}
		if ( d.back && typeof d.back.focus === 'function' && document.contains( d.back ) ) {
			d.back.focus();
		}
	};
	if ( instant || ! isPhone() ) {
		finish();
	} else {
		sheetOut( d.el, finish, d.scrim );
	}
}

