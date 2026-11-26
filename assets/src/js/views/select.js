/**
 * Custom dropdowns over a hidden native <select data-nw-sel>, ported from the
 * prototype's enhance(). The app never shows a native select.
 *
 * Options can carry decoration: data-person (avatar), data-agent (an
 * agent's avatar, 1.3.0), data-color (dot),
 * data-everyone (grey circle), data-icon (icon), data-prio (priority bars),
 * data-you (the "You" badge). On the select: data-block (full width),
 * data-right (menu opens to the left).
 */
import { ic } from '../lib/icons.js';
import { __ } from '../lib/i18n.js';
import { avatar, agentAvatar, col, priSvg } from './model.js';

let openBox = null;

function decor( o ) {
	if ( o.dataset.person !== undefined ) {
		return avatar( o.dataset.pid || o.value );
	}
	if ( o.dataset.agent ) {
		return agentAvatar( o.dataset.agent );
	}
	if ( o.dataset.color ) {
		return `<span class="nw-dot" style="--nw-c:${ col( o.dataset.color ) }"></span>`;
	}
	if ( o.dataset.everyone !== undefined ) {
		return `<span class="nw-avatar nw-ghost">${ ic( 'users-three', 13 ) }</span>`;
	}
	if ( o.dataset.icon ) {
		return ic( o.dataset.icon, 16 );
	}
	if ( o.dataset.prio !== undefined ) {
		return priSvg( o.value );
	}
	return '';
}

const youBadge = () => `<span class="nw-you">${ __( 'You', 'novawork' ) }</span>`;

/** Turn every native select[data-nw-sel] under root into a custom dropdown (or refresh it). */
export function enhance( root ) {
	root.querySelectorAll( 'select[data-nw-sel]' ).forEach( ( sel ) => {
		let box = sel.nextElementSibling && sel.nextElementSibling.classList.contains( 'nw-sel' ) ? sel.nextElementSibling : null;
		if ( ! box ) {
			sel.classList.add( 'nw-vh' );
			sel.tabIndex = -1;
			sel.setAttribute( 'aria-hidden', 'true' );
			box = document.createElement( 'div' );
			box.className = 'nw-sel' + ( sel.dataset.block ? ' nw-block' : '' ) + ( sel.dataset.right ? ' nw-right' : '' );
			const id = 'nw-sb-' + Math.random().toString( 36 ).slice( 2, 8 );
			box.innerHTML = `<button type="button" class="nw-sel-btn" aria-haspopup="listbox" aria-expanded="false" id="${ id }"></button>`;
			const lab = sel.id && root.querySelector( `label[for="${ sel.id }"]` );
			if ( lab ) {
				lab.setAttribute( 'for', id );
			}
			box.firstChild.setAttribute( 'aria-label', sel.getAttribute( 'aria-label' ) || ( lab ? lab.textContent : '' ) );
			sel.after( box );
			box.firstChild.addEventListener( 'click', () => ( box.classList.contains( 'nw-open' ) ? closeSel( box ) : openSel( sel, box ) ) );
			box.firstChild.addEventListener( 'keydown', ( e ) => {
				if ( ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) && ! box.classList.contains( 'nw-open' ) ) {
					e.preventDefault();
					openSel( sel, box );
				}
			} );
		}
		const o = sel.options[ sel.selectedIndex ];
		box.firstChild.innerHTML = ( o ? decor( o ) : '' ) + '<span class="nw-lbl"></span>' + ic( 'caret-down', 13, 'nw-caret' );
		box.firstChild.querySelector( '.nw-lbl' ).textContent = o ? o.textContent : '';
		if ( o && o.dataset.you !== undefined ) {
			box.firstChild.querySelector( '.nw-lbl' ).insertAdjacentHTML( 'afterend', youBadge() );
		}
	} );
}

export function closeSel( box, focus ) {
	if ( ! box ) {
		return;
	}
	box.classList.remove( 'nw-open', 'nw-up' );
	const menu = box.querySelector( '.nw-sel-menu' );
	if ( menu ) {
		menu.remove();
	}
	box.firstChild.setAttribute( 'aria-expanded', 'false' );
	if ( focus ) {
		box.firstChild.focus();
	}
	if ( openBox === box ) {
		openBox = null;
	}
}

export const openSelectBox = () => openBox;

function openSel( sel, box ) {
	if ( openBox ) {
		closeSel( openBox );
	}
	const ul = document.createElement( 'ul' );
	ul.className = 'nw-sel-menu';
	ul.setAttribute( 'role', 'listbox' );
	ul.tabIndex = -1;
	[ ...sel.options ].forEach( ( o, i ) => {
		const li = document.createElement( 'li' );
		li.setAttribute( 'role', 'option' );
		li.dataset.i = i;
		li.setAttribute( 'aria-selected', String( o.selected ) );
		li.innerHTML = decor( o ) + '<span></span>' + ic( 'check', 14, 'nw-tick' );
		li.children[ li.children.length - 2 ].textContent = o.textContent;
		if ( o.dataset.you !== undefined ) {
			li.children[ li.children.length - 2 ].insertAdjacentHTML( 'afterend', youBadge() );
		}
		ul.appendChild( li );
	} );
	box.appendChild( ul );
	// Long lists (owner decision): show seven and a half rows, so a cut-off
	// row always says "there is more"; the rest scrolls.
	if ( ul.children.length > 8 ) {
		const row = ul.children[ 7 ];
		ul.style.maxHeight = Math.round( row.offsetTop + row.offsetHeight / 2 ) + 'px';
	}
	box.classList.add( 'nw-open' );
	box.firstChild.setAttribute( 'aria-expanded', 'true' );
	openBox = box;
	// Open down, or up when there is more room above. Inside a dialog the
	// room is the dialog's (it scrolls on short screens), not the window's;
	// if neither side fits the list, it gets shorter rather than cut off.
	const r = box.getBoundingClientRect();
	const frame = box.closest( 'dialog' );
	const fr = frame ? frame.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
	const below = fr.bottom - r.bottom - 12;
	const above = r.top - fr.top - 12;
	const need = Math.min( ul.offsetHeight, 320 );
	const up = below < need && above > below;
	if ( up ) {
		box.classList.add( 'nw-up' );
	}
	const room = Math.floor( up ? above : below );
	if ( frame && room < ul.offsetHeight ) {
		ul.style.maxHeight = Math.max( 120, room ) + 'px';
	}
	let act = Math.max( sel.selectedIndex, 0 );
	const mark = () => {
		[ ...ul.children ].forEach( ( li, i ) => li.classList.toggle( 'nw-active', i === act ) );
		const a = ul.children[ act ];
		if ( a ) {
			a.scrollIntoView( { block: 'nearest' } );
		}
	};
	const pick = ( i ) => {
		sel.selectedIndex = i;
		sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		enhance( box.parentElement );
		closeSel( box, true );
	};
	ul.addEventListener( 'mousemove', ( e ) => {
		const li = e.target.closest( 'li' );
		if ( li ) {
			act = Number( li.dataset.i );
			mark();
		}
	} );
	ul.addEventListener( 'click', ( e ) => {
		const li = e.target.closest( 'li' );
		if ( li ) {
			pick( Number( li.dataset.i ) );
		}
	} );
	ul.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			act = Math.min( act + 1, sel.options.length - 1 );
			mark();
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			act = Math.max( act - 1, 0 );
			mark();
		} else if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			pick( act );
		} else if ( e.key === 'Escape' ) {
			e.preventDefault();
			e.stopPropagation();
			closeSel( box, true );
		} else if ( e.key === 'Tab' ) {
			closeSel( box );
		}
	} );
	mark();
	ul.focus();
}

// Clicking anywhere else closes an open dropdown.
document.addEventListener( 'pointerdown', ( e ) => {
	if ( openBox && ! openBox.contains( e.target ) ) {
		closeSel( openBox );
	}
} );
