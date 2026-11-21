/**
 * The one modal dialog every editor uses (task, project, decision), ported
 * from the prototype: a <dialog> on desktop, a bottom sheet with a grab
 * handle on phones (swipe down or tap outside to close), background scroll
 * locked while open, Delete armed by a first click and confirmed by a second.
 */
import { ic } from '../lib/icons.js';
import { __ } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { animatesSheets, lockBgScroll, unlockBgScroll, sheetOut, swipeToClose, placeInd } from '../lib/ui.js';
import { enhance, closeSel, openSelectBox } from './select.js';

let dlg = null;
let form = null;
let nativeClose = null;
let onSave = null;
const handlers = { del: null, comment: null };

/** Set by the editors: what Delete and the comment box do. */
export function onDelete( fn ) {
	handlers.del = fn;
}
export function onComment( fn ) {
	handlers.comment = fn;
}
export const dialogForm = () => form;
export const isOpen = () => !! ( dlg && dlg.open );

export function mountDialog() {
	dlg = document.getElementById( 'nw-dlg' );
	form = document.getElementById( 'nw-dlg-form' );
	nativeClose = dlg.close.bind( dlg );
	dlg.close = () => {
		if ( dlg.open && ! dlg.classList.contains( 'nw-sheet-out' ) ) {
			sheetOut( dlg, nativeClose );
		}
	};
	dlg.addEventListener( 'close', unlockBgScroll );
	swipeToClose( dlg, ( instant ) => {
		if ( instant ) {
			closeSel( openSelectBox() );
			nativeClose();
		} else {
			dlg.close();
		}
	} );
	dlg.addEventListener( 'cancel', ( e ) => {
		const box = openSelectBox();
		if ( box && dlg.contains( box ) ) {
			e.preventDefault();
			closeSel( box, true );
			return;
		}
		if ( animatesSheets() && ! e.defaultPrevented ) {
			e.preventDefault();
			dlg.close();
		}
	} );
	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		if ( e.submitter && e.submitter.value === 'cancel' ) {
			dlg.close();
			return;
		}
		if ( onSave && ( await onSave( new FormData( form ) ) ) !== false ) {
			dlg.close();
		}
	} );
	dlg.addEventListener( 'click', ( e ) => {
		if ( e.target === dlg ) {
			closeSel( openSelectBox() );
			dlg.close();
		}
	} );
	form.addEventListener( 'change', ( e ) => {
		const tabs = e.target.closest( '.nw-tabs' );
		if ( ! tabs || e.target.type !== 'radio' ) {
			return;
		}
		tabs.querySelectorAll( '.nw-tab' ).forEach( ( l ) => l.classList.toggle( 'nw-on', l.contains( e.target ) ? true : !! l.querySelector( 'input:checked' ) ) );
		placeInd( tabs );
	} );
	form.addEventListener( 'click', ( e ) => {
		const c = e.target.closest( '[data-cmt]' );
		if ( c ) {
			e.preventDefault();
			if ( handlers.comment ) {
				handlers.comment();
			}
			return;
		}
		const b = e.target.closest( '[data-del]' );
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		if ( ! b.classList.contains( 'nw-armed' ) ) {
			b.classList.add( 'nw-armed' );
			b.lastChild.textContent = __( 'Click again to delete', 'novawork' );
			setTimeout( () => {
				if ( b.isConnected ) {
					b.classList.remove( 'nw-armed' );
					b.lastChild.textContent = __( 'Delete', 'novawork' );
				}
			}, 3000 );
			return;
		}
		if ( handlers.del ) {
			Promise.resolve( handlers.del() ).then( ( ok ) => {
				if ( ok ) {
					dlg.close();
				}
			} );
		}
	} );
	form.addEventListener( 'keydown', ( e ) => {
		if ( e.target.id === 'nw-cmt-in' && e.key === 'Enter' ) {
			e.preventDefault();
			if ( handlers.comment ) {
				handlers.comment();
			}
		}
	} );
}

/**
 * @param {string}   title Dialog title (plain text).
 * @param {string}   body  Form HTML.
 * @param {Function} save  Receives FormData; return false to keep it open.
 */
export function openDialog( title, body, save ) {
	handlers.del = null;
	handlers.comment = null;
	form.innerHTML =
		`<div class="nw-sgrab" data-grab aria-hidden="true"></div><div class="nw-dlg-head"><h2 id="nw-dlg-title">${ esc( title ) }</h2><button class="nw-iconbtn" type="submit" value="cancel" formnovalidate aria-label="${ esc( __( 'Close', 'novawork' ) ) }">${ ic( 'x', 18 ) }</button></div>` + body;
	onSave = save;
	enhance( form );
	if ( ! dlg.open ) {
		lockBgScroll();
		dlg.showModal();
	}
	form.querySelectorAll( '.nw-tabs' ).forEach( ( n ) => placeInd( n, true ) );
	const first = form.querySelector( '[autofocus]' );
	if ( first ) {
		first.focus();
	}
}

export const closeDialog = () => dlg && dlg.close();

/** Cancel / Save (and Delete for an existing item). */
export const actions = ( canDelete ) =>
	`<div class="nw-dlg-actions">${ canDelete ? `<button class="nw-btn nw-danger" type="button" data-del="1">${ ic( 'trash', 15 ) }<span>${ esc( __( 'Delete', 'novawork' ) ) }</span></button>` : '' }<span class="nw-sp"></span><button class="nw-btn nw-ghost" type="submit" value="cancel" formnovalidate>${ esc( __( 'Cancel', 'novawork' ) ) }</button><button class="nw-btn nw-primary" type="submit" value="save">${ esc( __( 'Save', 'novawork' ) ) }</button></div>`;
