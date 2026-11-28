/**
 * "Away until…" (1.3.0): a member's holiday or days off, set from the team
 * menu. Members set their own; administrators anyone's (owner, 2026-11-28).
 * It ends by itself after the day it names.
 */
import { __, sprintf } from '../lib/i18n.js';
import { esc } from '../lib/format.js';
import { api } from '../lib/api.js';
import { toast } from '../lib/ui.js';
import { store, me, person, awayOf, awayText, iso, todayISO, parse } from './model.js';
import { openDialog } from './dialog.js';

const NOTE_MAX = 80;

/** A day from today, as Y-m-d. */
function dayFrom( days ) {
	const d = parse( todayISO() );
	d.setDate( d.getDate() + days );
	return iso( d );
}

/** The last working day of this week (Friday; Saturday or later: next Friday). */
function endOfWeek() {
	const d = parse( todayISO() );
	const add = ( 5 - d.getDay() + 7 ) % 7;
	d.setDate( d.getDate() + add );
	return iso( d );
}

/** Put the member the server answered with into the store (no wait for the next poll). */
function keep( member ) {
	const S = store();
	if ( member && member.id ) {
		S.members = S.members.map( ( m ) => ( m.id === member.id ? { ...m, ...member } : m ) );
	}
}

function openAway( userId ) {
	const id = Number( userId ) || me();
	const p = person( id );
	if ( ! p ) {
		return;
	}
	const mine = id === me();
	const now = awayOf( id );
	const picks = [
		[ dayFrom( 1 ), __( 'Tomorrow', 'novawork' ) ],
		[ endOfWeek(), __( 'End of the week', 'novawork' ) ],
		[ dayFrom( 7 ), __( 'Next week', 'novawork' ) ],
	];
	const title = mine ? __( 'Away until…', 'novawork' ) : sprintf( /* translators: %s: a person's name. */ __( 'Away status for %s', 'novawork' ), p.name );
	const body =
		`<p class="nw-away-lead">${ esc( mine ? __( 'Holidays or days off: your avatar and Team today show it until the day you pick, then it ends by itself.', 'novawork' ) : __( 'Their avatar and Team today show it until the day you pick, then it ends by itself.', 'novawork' ) ) }</p>` +
		`<div class="nw-field"><label for="nw-away-until">${ esc( __( 'Away until (the last day off)', 'novawork' ) ) }</label>` +
		`<div class="nw-away-picks">${ picks.map( ( [ value, label ] ) => `<button class="nw-btn nw-sm" type="button" data-away-pick="${ esc( value ) }">${ esc( label ) }</button>` ).join( '' ) }</div>` +
		`<input class="nw-input" type="date" id="nw-away-until" name="until" required min="${ esc( todayISO() ) }" max="${ esc( dayFrom( 366 ) ) }" value="${ esc( now ? now.until : '' ) }"></div>` +
		`<div class="nw-field"><label for="nw-away-note">${ esc( __( 'Note (optional)', 'novawork' ) ) }</label>` +
		`<input class="nw-input" type="text" id="nw-away-note" name="note" maxlength="${ NOTE_MAX }" value="${ esc( now ? now.note : '' ) }" placeholder="${ esc( __( 'Holiday, a day off…', 'novawork' ) ) }"></div>` +
		`<input type="hidden" name="back" value="">` +
		`<div class="nw-dlg-actions">${ now ? `<button class="nw-btn nw-ghost" type="submit" data-away-back formnovalidate>${ esc( mine ? __( 'I’m back', 'novawork' ) : __( 'End it', 'novawork' ) ) }</button>` : '' }<span class="nw-sp"></span>` +
		`<button class="nw-btn nw-ghost" type="submit" value="cancel" formnovalidate>${ esc( __( 'Cancel', 'novawork' ) ) }</button>` +
		`<button class="nw-btn nw-primary" type="submit">${ esc( __( 'Save', 'novawork' ) ) }</button></div>`;

	openDialog( title, body, async ( fd ) => {
		const back = fd.get( 'back' ) === '1';
		try {
			const member = back
				? await api( 'DELETE', '/away', { userId: id } )
				: await api( 'PUT', '/away', { userId: id, until: String( fd.get( 'until' ) || '' ), note: String( fd.get( 'note' ) || '' ).trim() } );
			keep( member );
			if ( back ) {
				toast( mine ? __( 'Welcome back.', 'novawork' ) : sprintf( /* translators: %s: a person's name. */ __( '%s is back.', 'novawork' ), p.name ), 'check' );
			} else {
				toast( mine ? sprintf( /* translators: %s: "Away until Fri 5 Dec". */ __( 'You’re set: %s.', 'novawork' ), awayText( id, false ) ) : p.name + ': ' + awayText( id, false ), 'moon' );
			}
			return true;
		} catch ( e ) {
			toast( ( e && e.message ) || __( 'Could not save. Check your connection and try again.', 'novawork' ), 'warning-circle' );
			return false;
		}
	} );

	const form = document.querySelector( '#nw-dlg form' ) || document.getElementById( 'nw-away-until' ).form;
	form.querySelectorAll( '[data-away-pick]' ).forEach( ( btn ) =>
		btn.addEventListener( 'click', () => {
			form.querySelector( '#nw-away-until' ).value = btn.dataset.awayPick;
		} )
	);
	const backBtn = form.querySelector( '[data-away-back]' );
	if ( backBtn ) {
		backBtn.addEventListener( 'click', () => {
			form.querySelector( 'input[name="back"]' ).value = '1';
		} );
	}
}

export function mountAway() {
	window.addEventListener( 'nw:away', ( e ) => openAway( e.detail && e.detail.userId ) );
}
