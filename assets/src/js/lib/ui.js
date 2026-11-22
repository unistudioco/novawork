/**
 * Shell mechanics ported from the prototype: the sliding tab indicator, the
 * reference-counted background scroll lock, sheets that slide down before
 * closing, swipe-to-close, and toasts.
 */
import { ic } from './icons.js';

export const PHONE = '(max-width: 719px)';
export const isPhone = () => window.matchMedia( PHONE ).matches;
export const animatesSheets = () => window.matchMedia( '(max-width: 719px) and (prefers-reduced-motion: no-preference)' ).matches;

/**
 * Move a tab bar's indicator under its active tab. `instant` skips the
 * transition (first paint, resize).
 *
 * @param {HTMLElement} nav     The .nw-tabs element.
 * @param {boolean}     instant Jump without animating.
 */
export function placeInd( nav, instant ) {
	if ( ! nav ) {
		return;
	}
	const ind = nav.querySelector( '.nw-ind' );
	const on = nav.querySelector( '.nw-tab.nw-on' );
	if ( ! ind || ! on || ! nav.offsetParent ) {
		return;
	}
	if ( instant ) {
		nav.classList.remove( 'nw-ready' );
	}
	const isBn = nav.classList.contains( 'nw-bn' );
	const w = isBn ? 56 : on.offsetWidth;
	const x = isBn ? on.offsetLeft + ( on.offsetWidth - 56 ) / 2 : on.offsetLeft;
	ind.style.width = w + 'px';
	ind.style.transform = `translateX(${ x }px)`;
	if ( instant ) {
		requestAnimationFrame( () => requestAnimationFrame( () => nav.classList.add( 'nw-ready' ) ) );
	}
}

/*
 * Background scroll lock for every modal-like overlay. Reference-counted so
 * overlapping sheets don't unlock early. Locking <html> and fixing <body> at
 * -scrollY together is what stops the few pixels of wheel drift; the exact
 * position comes back on unlock.
 */
let lockCount = 0;
let lockedAt = 0;

export function lockBgScroll() {
	if ( lockCount++ === 0 ) {
		lockedAt = window.scrollY;
		document.body.style.top = -lockedAt + 'px';
		document.documentElement.classList.add( 'nw-scroll-locked' );
	}
}

export function unlockBgScroll() {
	if ( lockCount === 0 ) {
		return;
	}
	if ( --lockCount === 0 ) {
		document.documentElement.classList.remove( 'nw-scroll-locked' );
		document.body.style.top = '';
		window.scrollTo( 0, lockedAt );
	}
}

/**
 * On phones, slide a sheet down (and fade its scrim) before it closes.
 *
 * @param {HTMLElement}      el    The sheet.
 * @param {Function}         done  Runs when it is gone.
 * @param {HTMLElement|null} scrim Its scrim, if any.
 */
export function sheetOut( el, done, scrim ) {
	if ( ! el || ! animatesSheets() ) {
		done();
		return;
	}
	el.classList.add( 'nw-sheet-out' );
	if ( scrim && scrim.offsetParent ) {
		scrim.classList.add( 'nw-scrim-out' );
	}
	setTimeout( () => {
		el.classList.remove( 'nw-sheet-out' );
		if ( scrim ) {
			scrim.classList.remove( 'nw-scrim-out' );
		}
		done();
	}, 230 );
}

/**
 * Drag a sheet down by its grab handle to close it. A quick flick closes it
 * too; a short drag springs back.
 *
 * @param {HTMLElement} sheet The sheet.
 * @param {Function}    close Called with `true` (already off-screen).
 */
export function swipeToClose( sheet, close ) {
	let st = null;
	let eatClick = false;
	// Releasing a drag also fires a click on the sheet; it must not count as a backdrop click.
	sheet.addEventListener(
		'click',
		( e ) => {
			if ( eatClick ) {
				eatClick = false;
				e.stopImmediatePropagation();
				e.preventDefault();
			}
		},
		true
	);
	sheet.addEventListener( 'pointerdown', ( e ) => {
		if ( ! window.matchMedia( '(max-width: 719px), (pointer: coarse)' ).matches || ! e.target.closest( '[data-grab]' ) ) {
			return;
		}
		st = { y0: e.clientY, t0: performance.now(), dy: 0, id: e.pointerId };
		sheet.setPointerCapture( e.pointerId );
		sheet.style.transition = 'none';
		sheet.classList.add( 'nw-dragging-sheet' );
		e.preventDefault();
	} );
	sheet.addEventListener( 'pointermove', ( e ) => {
		if ( ! st || e.pointerId !== st.id ) {
			return;
		}
		st.dy = Math.max( 0, e.clientY - st.y0 );
		sheet.style.transform = `translateY(${ st.dy }px)`;
	} );
	const end = ( e ) => {
		if ( ! st || e.pointerId !== st.id ) {
			return;
		}
		const dy = st.dy;
		const fast = dy / Math.max( 1, performance.now() - st.t0 ) > 0.6;
		st = null;
		sheet.classList.remove( 'nw-dragging-sheet' );
		eatClick = true;
		setTimeout( () => {
			eatClick = false;
		}, 0 );
		if ( dy > 90 || ( fast && dy > 24 ) ) {
			sheet.style.transition = 'transform .2s cubic-bezier(.4, 0, 1, 1)';
			sheet.style.transform = 'translateY(105%)';
			setTimeout( () => {
				close( true );
				sheet.style.transition = '';
				sheet.style.transform = '';
			}, 200 );
		} else {
			sheet.style.transition = 'transform .28s cubic-bezier(.2, .9, .25, 1)';
			sheet.style.transform = '';
			setTimeout( () => {
				sheet.style.transition = '';
			}, 300 );
		}
	};
	sheet.addEventListener( 'pointerup', end );
	sheet.addEventListener( 'pointercancel', end );
}

const MAX_TOASTS = 4;
const reduceMotion = () => window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
let stack = null;
const live = [];

/** Slide the other toasts from where they were to where they are now (FLIP). */
function glide( before ) {
	if ( reduceMotion() ) {
		return;
	}
	live.forEach( ( t ) => {
		const was = before.get( t.el );
		if ( was === undefined || ! t.el.isConnected ) {
			return;
		}
		const dy = was - t.el.getBoundingClientRect().top;
		if ( ! dy ) {
			return;
		}
		t.el.style.transition = 'none';
		t.el.style.transform = `translateY(${ dy }px)`;
		requestAnimationFrame( () => {
			t.el.style.transition = 'transform .28s cubic-bezier(.22, .9, .24, 1)';
			t.el.style.transform = '';
		} );
	} );
}
const positions = () => new Map( live.map( ( t ) => [ t.el, t.el.getBoundingClientRect().top ] ) );

/**
 * A message at the bottom of the screen. Toasts stack (newest at the
 * bottom), rise in, and fade out; the others glide to their new places.
 *
 * A toast with an action (owner decision: e.g. "Deleted. Undo") stays up
 * longer, and holds while it is hovered or has focus.
 *
 * @param {string} message Text (set as text, never HTML).
 * @param {string} icon    Icon name.
 * @param {Object} action  Optional { label, run, settle }: run on click;
 *                         settle when the toast goes away without a click.
 */
export function toast( message, icon, action ) {
	if ( ! stack ) {
		stack = document.createElement( 'div' );
		stack.className = 'nw-toasts';
	}
	// A modal dialog makes the rest of the page inert, so a toast's Undo
	// would be out of reach under it: while one is open, the toasts live
	// inside it, and go back to the page when it closes (so "Saved" stays).
	const host = document.querySelector( 'dialog[open]' ) || document.body;
	if ( stack.parentElement !== host ) {
		host.appendChild( stack );
		if ( host !== document.body ) {
			host.addEventListener( 'close', () => stack.parentElement === host && document.body.appendChild( stack ), { once: true } );
		}
	}
	const el = document.createElement( 'div' );
	el.className = 'nw-toast';
	el.setAttribute( 'role', 'status' );
	el.innerHTML = ic( icon || 'check' ) + '<span></span>';
	el.children[ 1 ].textContent = message;

	let timer = null;
	let gone = false;
	const item = { el };
	const dismiss = ( clicked ) => {
		if ( gone ) {
			return;
		}
		gone = true;
		clearTimeout( timer );
		if ( action && ! clicked && action.settle ) {
			action.settle();
		}
		const finish = () => {
			const before = positions();
			el.remove();
			live.splice( live.indexOf( item ), 1 );
			glide( before );
		};
		if ( reduceMotion() ) {
			finish();
			return;
		}
		el.classList.add( 'nw-toast-out' );
		setTimeout( finish, 200 );
	};
	item.dismiss = dismiss;
	const wait = ( ms ) => {
		clearTimeout( timer );
		timer = setTimeout( () => dismiss( false ), ms );
	};

	if ( action ) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'nw-toast-act';
		button.textContent = action.label;
		button.addEventListener( 'click', () => {
			dismiss( true );
			action.run();
		} );
		el.appendChild( button );
		el.addEventListener( 'mouseenter', () => clearTimeout( timer ) );
		el.addEventListener( 'focusin', () => clearTimeout( timer ) );
		el.addEventListener( 'mouseleave', () => wait( 2500 ) );
		el.addEventListener( 'focusout', () => wait( 2500 ) );
	}

	const before = positions();
	stack.appendChild( el );
	live.push( item );
	glide( before );
	// Too many at once: the oldest makes way (and settles its action).
	live.filter( ( t ) => ! t.el.classList.contains( 'nw-toast-out' ) ).slice( 0, -MAX_TOASTS ).forEach( ( t ) => t.dismiss( false ) );
	wait( action ? 6000 : 2600 );
}
