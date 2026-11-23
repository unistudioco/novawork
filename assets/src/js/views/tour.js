/**
 * The welcome tour (owner request, 2026-11-23).
 *
 * A short walk through the workspace that goes to each page and spotlights
 * the part it talks about, the way a notification goes to an item. It starts
 * by itself the first time a member opens the workspace (unless an
 * administrator turned it off: setting show_tour), and anyone can take it
 * again from their menu ("Take the tour"). Finishing or skipping it is
 * remembered per member on the server (POST /tour/seen), so a second device
 * does not show it again.
 *
 * Keyboard: → or Enter for next, ← for back, Esc to leave. While the tour is
 * open the app's own shortcuts (N, T, /) wait.
 */
import { ic } from '../lib/icons.js';
import { __, sprintf } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { post } from '../lib/api.js';
import { isPhone } from '../lib/ui.js';
import { store } from './model.js';
import { closeDialog, isOpen } from './dialog.js';

const boot = () => window.novawork || {};
const reduceMotion = () => window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

let tour = null;

/** The steps, for this screen size and this member. */
function steps() {
	const phone = isPhone();
	const admin = !! store().canManage;
	const name = ( boot().alerts || {} ).name || 'NovaWork';
	return [
		{
			icon: 'compass',
			title: sprintf( /* translators: %s: the workspace's name. */ __( 'Welcome to %s', 'novawork' ), name ),
			text: __( 'A quick look around: where your day lives, how work moves, and where the team talks. It takes a minute, and you can leave any time with Esc.', 'novawork' ),
			view: 'focus',
		},
		{
			view: 'focus',
			target: [ '.nw-slots' ],
			title: __( 'Your three', 'novawork' ),
			text: __( 'Keep a short list in Now. Everything else waits in Next or Later, so the day stays clear. Tick a task when it is done.', 'novawork' ),
		},
		{
			view: 'focus',
			target: phone ? [ '#nw-ftimer' ] : [ '#nw-tracker' ],
			title: __( 'Track time with one click', 'novawork' ),
			text: __( 'Press play on any task. Step away, close the tab or fold your laptop, and the timer stops where you left; the time away is never counted.', 'novawork' ),
		},
		{
			view: 'board',
			target: [ '.nw-board' ],
			title: __( 'The board', 'novawork' ),
			text: phone
				? __( 'Now, Next, Later and Done. Open a task for its notes, subtasks and comments.', 'novawork' )
				: __( 'Now, Next, Later and Done. Drag cards to reorder them or move them across, and open a task for its notes, subtasks and comments. Press N for a new task.', 'novawork' ),
		},
		{
			view: 'roadmap',
			target: [ '#nw-main > :nth-child(2)' ],
			title: __( 'Roadmap', 'novawork' ),
			text: __( 'Projects on a timeline, by months or quarters, with their goals, target dates and deadlines.', 'novawork' ),
		},
		{
			view: 'decisions',
			target: [ '#nw-main > :nth-child(2)' ],
			title: __( 'Decisions', 'novawork' ),
			text: __( 'Write down the questions that block work. Ask the team to vote; the person named records the decision.', 'novawork' ),
		},
		{
			view: 'time',
			target: [ '#nw-main > :nth-child(2)', '#nw-main > :nth-child(3)' ],
			title: __( 'Time', 'novawork' ),
			text: __( 'Where the hours went: today, this week, this month or this year, for you and for the team.', 'novawork' ),
		},
		{
			target: phone ? [ '#nw-fab' ] : [ '#nw-chat-btn' ],
			title: phone ? __( 'Create and chat', 'novawork' ) : __( 'Team chat', 'novawork' ),
			text: phone
				? __( 'Create tasks, projects and decisions from here, and open the team chat. In chat, type @ to mention someone and / to link a task.', 'novawork' )
				: __( 'Talk with the team beside your work. Type @ to mention someone and / to link a task, decision or project.', 'novawork' ),
		},
		{
			target: [ '#nw-bell-btn' ],
			title: __( 'Notifications', 'novawork' ),
			text: __( 'What is about you, and what the team did. You can turn on desktop alerts here for this device.', 'novawork' ),
		},
		{
			target: phone ? [ '#nw-qs-btn' ] : [ '#nw-qs' ],
			title: __( 'Search', 'novawork' ),
			text: phone
				? __( 'Find any task, project, decision or message.', 'novawork' )
				: __( 'Find any task, project, decision or message. Press / to search from anywhere.', 'novawork' ),
		},
		{
			target: [ '#nw-crew-btn' ],
			title: admin ? __( 'You, your team and the settings', 'novawork' ) : __( 'You and your team', 'novawork' ),
			text: admin
				? __( 'Who is here, the theme, this tour again, and the workspace settings: name, address, branding, invites and more.', 'novawork' )
				: __( 'Who is here, the theme, and this tour again whenever you want it.', 'novawork' ),
		},
	];
}

/** The first matching element that is on screen. */
function find( selectors ) {
	for ( const sel of selectors || [] ) {
		const el = document.querySelector( sel );
		if ( el && el.getClientRects().length && getComputedStyle( el ).visibility !== 'hidden' ) {
			return el;
		}
	}
	return null;
}

/** Go to a page and wait until the step's target is drawn there. */
async function goTo( step ) {
	if ( step.view && store().view !== step.view ) {
		window.location.hash = '#' + step.view;
	}
	for ( let n = 0; n < 40; n++ ) {
		if ( store().view === ( step.view || store().view ) && ( ! step.target || find( step.target ) ) ) {
			return find( step.target );
		}
		await wait( 50 );
	}
	return find( step.target );
}

function cardHTML( step, i, total ) {
	const last = i === total - 1;
	const dots = Array.from( { length: total }, ( _, k ) => `<i${ k === i ? ' class="nw-on"' : '' }></i>` ).join( '' );
	return (
		( step.icon ? `<span class="nw-nic nw-tour-ic" aria-hidden="true">${ ic( step.icon, 22 ) }</span>` : '' ) +
		`<span class="nw-tour-step">${ esc( sprintf( /* translators: 1: step, 2: number of steps. */ __( '%1$d of %2$d', 'novawork' ), i + 1, total ) ) }</span>` +
		`<h3 id="nw-tour-title">${ esc( step.title ) }</h3><p id="nw-tour-text">${ esc( step.text ) }</p>` +
		`<div class="nw-tour-acts"><span class="nw-tour-dots" aria-hidden="true">${ dots }</span><span class="nw-sp"></span>` +
		( last ? '' : `<button class="nw-btn nw-sm nw-ghost" type="button" data-tour="skip">${ esc( __( 'Skip', 'novawork' ) ) }</button>` ) +
		( i > 0 ? `<button class="nw-btn nw-sm" type="button" data-tour="back">${ esc( __( 'Back', 'novawork' ) ) }</button>` : '' ) +
		`<button class="nw-btn nw-sm nw-primary" type="button" data-tour="next">${ esc( last ? __( 'Start working', 'novawork' ) : __( 'Next', 'novawork' ) ) }</button></div>`
	);
}

/**
 * The part of the screen the page content can use: below the sticky header
 * and, on phones, above the bottom bar. A target inside one of them uses the
 * whole screen.
 *
 * @param {Element|null} el Target.
 */
function room( el ) {
	const vh = window.innerHeight;
	const hdr = document.querySelector( '.nw-hdr' );
	const bar = document.querySelector( '#nw-bn' );
	const fabs = document.querySelector( '#nw-fabs' );
	const chrome = ( box ) => box && el && box.contains( el );
	const top = hdr && ! chrome( hdr ) ? Math.max( 8, hdr.getBoundingClientRect().bottom + 6 ) : 8;
	const barTop = bar && bar.getClientRects().length && ! chrome( bar ) && ! chrome( fabs ) ? bar.getBoundingClientRect().top - 6 : vh - 8;
	return { top, bottom: Math.min( vh - 8, barTop ) };
}

/** Put the spotlight on the target (or nowhere) and the card beside it. */
function place() {
	if ( ! tour ) {
		return;
	}
	const { spot, card, el } = tour;
	const vw = window.innerWidth;
	const vh = window.innerHeight;
	const pad = 8;
	let r = null;
	if ( el && el.isConnected ) {
		const b = el.getBoundingClientRect();
		// Only what is on screen: a tall page section is cut to the free area.
		const free = room( el );
		const top = Math.max( b.top - pad, free.top );
		const bottom = Math.min( b.bottom + pad, free.bottom );
		const left = Math.max( b.left - pad, 8 );
		const right = Math.min( b.right + pad, vw - 8 );
		if ( bottom > top && right > left ) {
			r = { top, left, width: right - left, height: bottom - top };
		}
	}
	if ( r ) {
		Object.assign( spot.style, { top: r.top + 'px', left: r.left + 'px', width: r.width + 'px', height: r.height + 'px' } );
		spot.classList.remove( 'nw-tour-none' );
	} else {
		Object.assign( spot.style, { top: vh / 2 + 'px', left: vw / 2 + 'px', width: '0px', height: '0px' } );
		spot.classList.add( 'nw-tour-none' );
	}

	const cw = card.offsetWidth;
	const ch = card.offsetHeight;
	const gap = 14;
	let top;
	let left;
	if ( ! r ) {
		top = ( vh - ch ) / 2;
		left = ( vw - cw ) / 2;
	} else if ( isPhone() ) {
		// Phones: the card sits at the top or the bottom, away from the spotlight.
		left = ( vw - cw ) / 2;
		top = r.top + r.height / 2 > vh / 2 ? 12 : vh - ch - 12;
	} else {
		left = Math.min( Math.max( r.left + r.width / 2 - cw / 2, 12 ), vw - cw - 12 );
		if ( r.top + r.height + gap + ch <= vh - 12 ) {
			top = r.top + r.height + gap;
		} else if ( r.top - gap - ch >= 12 ) {
			top = r.top - gap - ch;
		} else {
			// No room above or below (a big section): inside it, at the bottom.
			top = Math.max( 12, Math.min( r.top + r.height - ch - 16, vh - ch - 12 ) );
		}
	}
	card.style.top = Math.round( top ) + 'px';
	card.style.left = Math.round( left ) + 'px';
}

async function show( i ) {
	if ( ! tour ) {
		return;
	}
	const list = tour.steps;
	tour.i = i;
	const step = list[ i ];
	const token = ++tour.token;
	tour.card.classList.add( 'nw-tour-swap' );
	const el = await goTo( step );
	if ( ! tour || token !== tour.token ) {
		return;
	}
	if ( el ) {
		// Bring it into the free area: a tall section starts just under the
		// header, a small one sits in the middle. Header items never scroll.
		const b = el.getBoundingClientRect();
		const free = room( el );
		const inChrome = free.top === 8 && document.querySelector( '.nw-hdr' )?.contains( el );
		if ( ! inChrome && ( b.top < free.top || b.bottom > free.bottom ) ) {
			const height = free.bottom - free.top;
			const offset = b.height > height * 0.7 ? 12 : ( height - b.height ) / 2;
			window.scrollTo( { top: Math.max( 0, window.scrollY + b.top - free.top - offset ), behavior: reduceMotion() ? 'auto' : 'smooth' } );
			await wait( reduceMotion() ? 60 : 420 );
		}
	} else if ( step.view ) {
		window.scrollTo( 0, 0 );
	}
	if ( ! tour || token !== tour.token ) {
		return;
	}
	tour.el = el;
	tour.card.innerHTML = cardHTML( step, i, list.length );
	place();
	tour.card.classList.remove( 'nw-tour-swap' );
	tour.card.querySelector( '[data-tour="next"]' ).focus( { preventScroll: true } );
}

/**
 * Leave the tour and remember that this member has seen it.
 */
function finish() {
	if ( ! tour ) {
		return;
	}
	const t = tour;
	tour = null;
	window.removeEventListener( 'keydown', t.onKey, true );
	window.removeEventListener( 'resize', t.onMove );
	window.removeEventListener( 'scroll', t.onMove, true );
	t.root.classList.add( 'nw-tour-out' );
	setTimeout( () => t.root.remove(), reduceMotion() ? 0 : 200 );
	if ( t.from && store().view !== t.from ) {
		window.location.hash = '#' + t.from;
	}
	if ( t.focus && t.focus.isConnected ) {
		t.focus.focus( { preventScroll: true } );
	}
	post( 'tour/seen', {} ).catch( () => {} );
}

/** Start the tour (from the menu, or by itself on a first visit). */
export function startTour() {
	if ( tour ) {
		return;
	}
	// Nothing else may be open on top: dialogs, the chat on phones, panels.
	if ( isOpen() ) {
		closeDialog();
	}
	window.dispatchEvent( new CustomEvent( 'nw:notes', { detail: { open: false } } ) );
	if ( isPhone() ) {
		window.dispatchEvent( new CustomEvent( 'nw:chat', { detail: { open: false } } ) );
	}

	const root = document.createElement( 'div' );
	root.className = 'nw-tour';
	root.innerHTML = `<div class="nw-tour-spot nw-tour-none"></div><div class="nw-tour-card nw-tour-swap" role="dialog" aria-modal="true" aria-labelledby="nw-tour-title" aria-describedby="nw-tour-text"></div>`;
	( document.querySelector( '.nw-app' ) || document.body ).appendChild( root );

	tour = {
		root,
		spot: root.firstChild,
		card: root.lastChild,
		el: null,
		i: 0,
		token: 0,
		steps: steps(),
		from: store().view,
		focus: document.activeElement,
	};
	let frame = 0;
	tour.onMove = () => {
		cancelAnimationFrame( frame );
		frame = requestAnimationFrame( place );
	};
	tour.onKey = ( e ) => {
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			e.stopPropagation();
			finish();
		} else if ( e.key === 'ArrowRight' ) {
			e.preventDefault();
			e.stopPropagation();
			next();
		} else if ( e.key === 'ArrowLeft' ) {
			e.preventDefault();
			e.stopPropagation();
			if ( tour && tour.i > 0 ) {
				show( tour.i - 1 );
			}
		} else if ( e.key === 'Tab' ) {
			// Keep the keyboard inside the card.
			const items = [ ...tour.card.querySelectorAll( 'button' ) ];
			const at = items.indexOf( document.activeElement );
			e.preventDefault();
			items[ ( at + ( e.shiftKey ? -1 : 1 ) + items.length ) % items.length ]?.focus();
		} else if ( e.key !== 'Enter' && e.key !== ' ' ) {
			// The app's own shortcuts wait while the tour is open.
			e.stopPropagation();
		}
	};
	window.addEventListener( 'keydown', tour.onKey, true );
	window.addEventListener( 'resize', tour.onMove );
	window.addEventListener( 'scroll', tour.onMove, true );
	root.addEventListener( 'click', ( e ) => {
		const b = e.target.closest( '[data-tour]' );
		if ( ! b ) {
			return;
		}
		if ( b.dataset.tour === 'next' ) {
			next();
		} else if ( b.dataset.tour === 'back' ) {
			show( tour.i - 1 );
		} else {
			finish();
		}
	} );
	show( 0 );
}

function next() {
	if ( ! tour ) {
		return;
	}
	if ( tour.i >= tour.steps.length - 1 ) {
		finish();
	} else {
		show( tour.i + 1 );
	}
}

export const tourOpen = () => !! tour;

/** Listen for "Take the tour", and start by itself on a first visit. */
export function mountTour() {
	window.addEventListener( 'nw:tour', () => startTour() );
	if ( ( boot().tour || {} ).auto ) {
		// After the first paint settles, and only if nothing else is open.
		setTimeout( () => {
			if ( ! isOpen() && ! document.querySelector( '#nw-idle-dlg[open]' ) ) {
				startTour();
			}
		}, 900 );
	}
}
