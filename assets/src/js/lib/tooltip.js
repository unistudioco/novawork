/**
 * One tooltip for the whole app (owner request, 1.1.0): smooth and quick,
 * in NovaWork's look, instead of the browser's plain `title` popups.
 *
 * What gets one:
 * - anything with a `title` (the text moves to the tooltip while hovered, so
 *   the browser's own popup never shows, and comes back after) or a
 *   `data-tip`;
 * - icon-only buttons and links, from their aria-label (the chat and bell
 *   buttons, the timer, a card's play button). A badge hidden from screen
 *   readers (the bell's unread count) doesn't count as words.
 * `data-tip-key` adds a keyboard shortcut chip (the timer's T).
 *
 * Shown on mouse hover after a short delay (none while moving between
 * neighbours) and on keyboard focus; never on touch. Esc, a click, scrolling
 * or leaving hides it. It lives in the top layer (popover), so it shows above
 * open dialogs too.
 */

const DELAY = 380;
const WARM_MS = 500;

let tip = null;
let label = null;
let keyChip = null;
let current = null;
let timer = 0;
let lastHide = 0;

const reduceMotion = () => window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

/** The element that has a tooltip, from an event target. */
function owner( node ) {
	const el = node && node.closest ? node.closest( '[title], [data-tip], button[aria-label], a[aria-label], [role="button"][aria-label]' ) : null;
	if ( ! el || el.closest( '.nw-tip, .nw-tour, [data-no-tip]' ) ) {
		return null;
	}
	if ( el.hasAttribute( 'title' ) || el.hasAttribute( 'data-tip' ) ) {
		return el;
	}
	// aria-label only: tooltips for icon-only controls, not for ones that show their words.
	return words( el ) === '' ? el : null;
}

/**
 * The words a control shows, leaving out what is hidden from screen readers
 * (a badge such as the bell's unread count: its aria-label already says it).
 */
function words( el ) {
	let text = '';
	for ( const child of el.childNodes ) {
		if ( child.nodeType === Node.TEXT_NODE ) {
			text += child.nodeValue;
		} else if ( child.nodeType === Node.ELEMENT_NODE && child.getAttribute( 'aria-hidden' ) !== 'true' ) {
			text += words( child );
		}
	}
	return text.trim();
}

function textOf( el ) {
	if ( el.hasAttribute( 'title' ) ) {
		// Keep the words in the tooltip only, while it shows.
		el.dataset.nwTitle = el.getAttribute( 'title' );
		el.removeAttribute( 'title' );
	}
	return ( el.dataset.tip || el.dataset.nwTitle || el.getAttribute( 'aria-label' ) || '' ).trim();
}

function restoreTitle( el ) {
	if ( el && el.dataset.nwTitle !== undefined ) {
		// Alpine may have set a new title meanwhile; that one wins.
		if ( ! el.hasAttribute( 'title' ) ) {
			el.setAttribute( 'title', el.dataset.nwTitle );
		}
		delete el.dataset.nwTitle;
	}
}

function ensure() {
	if ( tip ) {
		return;
	}
	tip = document.createElement( 'div' );
	tip.className = 'nw-tip';
	tip.setAttribute( 'role', 'tooltip' );
	tip.id = 'nw-tip';
	if ( 'popover' in HTMLElement.prototype ) {
		tip.setAttribute( 'popover', 'manual' );
	}
	label = document.createElement( 'span' );
	keyChip = document.createElement( 'kbd' );
	tip.append( label, keyChip );
	document.body.appendChild( tip );
}

function place( el ) {
	const r = el.getBoundingClientRect();
	const w = tip.offsetWidth;
	const h = tip.offsetHeight;
	const gap = 8;
	const vw = document.documentElement.clientWidth;
	let top = r.top - h - gap;
	let below = false;
	if ( top < 6 ) {
		top = r.bottom + gap;
		below = true;
	}
	const centre = r.left + r.width / 2;
	const left = Math.min( Math.max( centre - w / 2, 8 ), vw - w - 8 );
	tip.style.top = Math.round( top ) + 'px';
	tip.style.left = Math.round( left ) + 'px';
	// The arrow points at the element, even when the bubble had to move over.
	tip.style.setProperty( '--nw-tip-x', Math.round( centre - left ) + 'px' );
	tip.classList.toggle( 'nw-tip-below', below );
}

function show( el ) {
	const text = textOf( el );
	if ( ! text || ! el.isConnected ) {
		restoreTitle( el );
		return;
	}
	ensure();
	current = el;
	label.textContent = text;
	keyChip.textContent = el.dataset.tipKey || '';
	keyChip.hidden = ! el.dataset.tipKey;
	el.setAttribute( 'aria-describedby', tip.id );
	tip.classList.remove( 'nw-tip-on' );
	if ( tip.showPopover && ! tip.matches( ':popover-open' ) ) {
		tip.showPopover();
	}
	tip.style.display = 'block';
	place( el );
	// Next frame: animate in from the placed position.
	requestAnimationFrame( () => tip && current === el && tip.classList.add( 'nw-tip-on' ) );
}

export function hideTip() {
	clearTimeout( timer );
	if ( ! current ) {
		return;
	}
	const el = current;
	current = null;
	lastHide = Date.now();
	restoreTitle( el );
	if ( el.getAttribute( 'aria-describedby' ) === 'nw-tip' ) {
		el.removeAttribute( 'aria-describedby' );
	}
	if ( tip ) {
		tip.classList.remove( 'nw-tip-on' );
		const t = tip;
		setTimeout( () => {
			if ( ! current && t ) {
				t.style.display = 'none';
				if ( t.hidePopover && t.matches( ':popover-open' ) ) {
					t.hidePopover();
				}
			}
		}, reduceMotion() ? 0 : 120 );
	}
}

function schedule( el, now ) {
	clearTimeout( timer );
	if ( current && current !== el ) {
		hideTip();
	}
	// Moving straight from one tooltip to the next: no wait.
	const warm = now || Date.now() - lastHide < WARM_MS;
	timer = setTimeout( () => show( el ), warm ? 0 : DELAY );
}

export function mountTooltips() {
	document.addEventListener(
		'pointerover',
		( e ) => {
			if ( e.pointerType === 'touch' ) {
				return;
			}
			const el = owner( e.target );
			if ( el && el !== current ) {
				schedule( el );
			} else if ( ! el && current ) {
				hideTip();
			}
		},
		{ passive: true }
	);
	document.addEventListener(
		'pointerout',
		( e ) => {
			const el = owner( e.target );
			if ( el && ( ! e.relatedTarget || ! el.contains( e.relatedTarget ) ) ) {
				if ( el === current ) {
					hideTip();
				} else {
					clearTimeout( timer );
				}
			}
		},
		{ passive: true }
	);
	document.addEventListener( 'focusin', ( e ) => {
		const el = owner( e.target );
		if ( el && el === e.target && el.matches( ':focus-visible' ) ) {
			schedule( el, true );
		}
	} );
	document.addEventListener( 'focusout', () => hideTip() );
	document.addEventListener( 'pointerdown', () => hideTip(), true );
	window.addEventListener( 'scroll', () => hideTip(), { passive: true, capture: true } );
	window.addEventListener( 'resize', () => hideTip() );
	document.addEventListener(
		'keydown',
		( e ) => {
			if ( e.key === 'Escape' && current ) {
				hideTip();
			}
		},
		true
	);
}
