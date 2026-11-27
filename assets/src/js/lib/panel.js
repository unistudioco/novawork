/**
 * A panel an add-on opens in the app (1.3.0): the app's dialog on desktop, a
 * bottom sheet with a grab handle on phones (swipe down, Escape or a tap
 * outside closes it), with the page behind it locked, like the app's own
 * dialogs. One panel shows at a time.
 */
import { ic } from './icons.js';
import { __ } from './i18n.js';
import { esc } from './format.js';
import { animatesSheets, lockBgScroll, unlockBgScroll, sheetOut, swipeToClose } from './ui.js';

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
 * @param {Object}   def        Panel.
 * @param {string}   def.id     Unique id (letters, digits, - and _).
 * @param {string}   def.title  Its title (plain text).
 * @param {Function} def.render ( body ) → draws the panel into the element; it
 *                              runs each time the panel opens.
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
