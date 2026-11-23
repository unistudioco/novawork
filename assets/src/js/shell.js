/**
 * The app shell: header (quick search, tracker, chat, bell, team), view tabs,
 * the phone bottom bar and floating actions, the team popover (with the
 * appearance switch) and the create sheet. Behaviour follows the prototype.
 *
 * Views, dialogs, chat and notifications arrive in Phase 4; the shell
 * announces those intents as window events (nw:create, nw:open, nw:chat,
 * nw:notifications) for them to pick up.
 */
import { ic, icf, swapInner } from './lib/icons.js';
import { __, _n, sprintf } from './lib/i18n.js';
import { clock, dur, esc, initials, dayStart, longDate, shortTitle, ago } from './lib/format.js';
import { serverNow } from './store.js';
import { orderTeam } from './lib/team.js';
import { get, del } from './lib/api.js';
import { plainText } from './views/rich.js';
import { startTracking, stopTracking, defaultTaskId } from './views/actions.js';
import { isPhone, PHONE, placeInd, lockBgScroll, unlockBgScroll, sheetOut, swipeToClose, toast } from './lib/ui.js';

const COLORS = [ 'blue', 'indigo', 'grape', 'violet', 'pink', 'rose', 'salmon', 'red', 'peach', 'amber', 'gold', 'sand', 'lime', 'mint', 'emerald', 'aqua', 'teal', 'sky', 'slate' ];
const VIEW_KEYS = [ 'focus', 'board', 'roadmap', 'time', 'decisions' ];

function store( key, value ) {
	try {
		if ( value === undefined ) {
			return window.localStorage.getItem( key );
		}
		window.localStorage.setItem( key, value );
	} catch ( e ) {}
	return null;
}

const emit = ( name, detail ) => window.dispatchEvent( new CustomEvent( name, { detail } ) );

export default () => ( {
	ic,
	icf,
	swapInner,
	clock,
	dur,
	shortTitle,

	views: [
		{ key: 'focus', label: __( 'Focus', 'novawork' ), icon: 'target' },
		{ key: 'board', label: __( 'Board', 'novawork' ), icon: 'kanban' },
		{ key: 'roadmap', label: __( 'Roadmap', 'novawork' ), icon: 'map-trifold' },
		{ key: 'time', label: __( 'Time', 'novawork' ), icon: 'chart-bar' },
		{ key: 'decisions', label: __( 'Decisions', 'novawork' ), icon: 'scales' },
	],
	themes: [
		{ key: 'light', icon: 'sun', label: __( 'Light', 'novawork' ) },
		{ key: 'dark', icon: 'moon', label: __( 'Dark', 'novawork' ) },
		{ key: 'system', icon: 'monitor', label: __( 'Auto', 'novawork' ) },
	],
	t: {
		startTracking: __( 'Start tracking', 'novawork' ),
		pauseTracking: __( 'Pause tracking', 'novawork' ),
		timerRunningOpen: __( 'Timer running. Open Time', 'novawork' ),
		trackedTodayOpen: __( 'Tracked today. Open Time', 'novawork' ),
		online: __( 'Online', 'novawork' ),
		away: __( 'Away', 'novawork' ),
	},

	stuck: false,
	phone: false,
	compact: false,
	chatOpen: false,
	notesOpen: false,
	// Signing out comes back to the workspace's sign-in page.
	logoutUrl: ( window.novawork || {} ).logoutUrl || '',
	notesClosing: false,
	theme: store( 'nw.theme' ) || 'system',
	pop: { open: false },
	fab: { open: false },
	qs: { q: '', open: false, closing: false, showRes: false, html: '', act: 0, locked: false },
	qsItems: [],
	// Older chat messages matching the search, from the server: the app
	// holds only the newest ones, and search covers the whole history.
	qsOlder: { q: '', rows: [] },
	qsTimer: null,
	qsLastQ: '',

	init() {
		const media = window.matchMedia( PHONE );
		this.phone = media.matches;
		media.addEventListener( 'change', () => {
			this.phone = media.matches;
		} );

		// Not enough room for the search field beside the header controls
		// (tablets, small laptops, a docked chat): show the search icon instead,
		// which opens the full-screen search as on phones. 1060px is the width
		// the field needs when focused (440px) next to the controls.
		const inner = this.$root.querySelector( '.nw-hdr-in' );
		new ResizeObserver( () => {
			this.compact = ! this.phone && inner.clientWidth - 40 < 1060;
		} ).observe( inner );

		this.route();
		window.addEventListener( 'hashchange', () => {
			this.route();
			window.scrollTo( { top: 0 } );
		} );

		const onScroll = () => {
			// While a panel locks the page (lib/ui.js lockBgScroll), the body is
			// pinned and scrollY reads 0: keep the header as it was, or it would
			// drop its glass background under every open panel.
			if ( document.documentElement.classList.contains( 'nw-scroll-locked' ) ) {
				return;
			}
			this.stuck = window.scrollY > 4;
		};
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll();

		setInterval( () => {
			this.$store.nw.now = serverNow();
		}, 1000 );

		swipeToClose( this.$refs.pop, () => this.closePop( true ) );
		swipeToClose( this.$refs.fabSheet, () => this.closeFab( true ) );
		swipeToClose( this.$refs.notes, () => this.closeNotes( true ) );
		// A notification that opens something closes the panel.
		window.addEventListener( 'nw:notes', ( e ) => {
			if ( e.detail && e.detail.open === false ) {
				this.closeNotes( true );
			}
		} );

		document.addEventListener( 'pointerdown', ( e ) => this.onPointerDown( e ) );
		document.addEventListener( 'keydown', ( e ) => this.onKey( e ) );
		window.addEventListener( 'resize', () => document.querySelectorAll( '.nw-tabs' ).forEach( ( n ) => placeInd( n, true ) ) );
		// The chat window reports whether it is open (for the header button).
		window.addEventListener( 'nw:chat-state', ( e ) => {
			this.chatOpen = !! ( e.detail && e.detail.open );
		} );

		this.$watch( '$store.nw.view', () => this.$nextTick( () => placeInd( this.$refs.tabs ) ) );
		this.$watch( 'stuck', () => this.$nextTick( () => placeInd( this.$refs.tabs, true ) ) );
		this.$nextTick( () => placeInd( this.$refs.tabs, true ) );
	},

	/* ---------- routing ---------- */
	route() {
		const hash = ( window.location.hash || '' ).slice( 1 );
		this.$store.nw.view = VIEW_KEYS.includes( hash ) ? hash : 'focus';
	},
	go( view ) {
		window.location.hash = '#' + view;
	},

	/* ---------- people and time ---------- */
	member( id ) {
		return this.$store.nw.members.find( ( m ) => m.id === id ) || null;
	},
	meMember() {
		return this.member( this.$store.nw.me ) || { id: this.$store.nw.me, name: '', color: 'slate', avatar: '' };
	},
	/**
	 * Everyone but you, in the admin's chosen order (the same as Team today).
	 * Reads the clock without subscribing to it, so the header strip and the
	 * popover list are not rebuilt every second (that reloaded every avatar).
	 */
	others() {
		const nw = this.$store.nw;
		const today = dayStart( serverNow() );
		return orderTeam(
			nw.members.filter( ( m ) => m.id !== nw.me ),
			{ order: nw.settings.teamOrder || 'dynamic', status: ( id ) => this.status( id ), today: ( id ) => this.trackedAt( id, today, serverNow() ), me: nw.me }
		);
	},
	crew() {
		return [ this.meMember() ].concat( this.others() );
	},
	cpair( color ) {
		const k = COLORS.includes( color ) ? color : 'slate';
		return `--nw-c:var(--nw-c-${ k });--nw-ci:var(--nw-ci-${ k })`;
	},
	/** One member's avatar with a presence dot (the prototype's presAvatar). */
	presAvatar( m ) {
		return `<span class="nw-pres nw-is-${ this.status( m.id ) }"><span class="nw-avatar nw-lg" title="${ esc( m.name ) }" style="${ this.cpair( m.color ) }">${ this.avatarInner( m ) }</span><i class="nw-st-dot"></i></span>`;
	},
	/**
	 * The header's members strip (owner decision): you, then up to two others
	 * who are here (in the Team today order), then "+N" for the rest who
	 * are here. Away members are not shown. In the markup you come first; CSS
	 * lays it out right to left, so you sit next to the chevron and the left
	 * avatar is on top. Phones show only the first child: you.
	 *
	 * Built as HTML like the prototype: the CSS relies on :first-child and
	 * sibling rules that an Alpine <template> inside the strip would break.
	 */
	crewHtml() {
		const here = this.others().filter( ( m ) => this.status( m.id ) !== 'away' );
		const shown = here.slice( 0, 2 );
		const extra = here.length - shown.length;
		let html = this.presAvatar( this.meMember() ) + shown.map( ( m ) => this.presAvatar( m ) ).join( '' );
		if ( extra > 0 ) {
			const label = esc( sprintf( /* translators: %d: number of other members online. */ _n( '%d more here', '%d more here', extra, 'novawork' ), extra ) );
			html += `<span class="nw-pres nw-more"><span class="nw-avatar nw-lg nw-ghost" title="${ label }" aria-label="${ label }">+${ extra }</span></span>`;
		}
		return html;
	},
	avatarInner( m ) {
		if ( ! m ) {
			return '';
		}
		const photo = m.avatar ? `<img src="${ esc( m.avatar ) }" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">` : '';
		return esc( initials( m.name ) ) + photo;
	},
	runOf( uid ) {
		return Object.values( this.$store.nw.timeEntries ).find( ( e ) => e.running && e.by === uid ) || null;
	},
	myRun() {
		return this.runOf( this.$store.nw.me );
	},
	taskOf( entry ) {
		return entry ? this.$store.nw.tasks[ entry.taskId ] || null : null;
	},
	status( uid ) {
		if ( this.runOf( uid ) ) {
			return 'tracking';
		}
		const m = this.member( uid );
		return uid === this.$store.nw.me || ( m && m.online ) ? 'online' : 'away';
	},
	dayStart() {
		return dayStart( this.$store.nw.now );
	},
	/** Milliseconds a member tracked from a time until now (a running timer counts). */
	tracked( uid, from ) {
		return this.trackedAt( uid, from, this.$store.nw.now );
	},
	trackedAt( uid, from, now ) {
		return Object.values( this.$store.nw.timeEntries )
			.filter( ( e ) => e.by === uid )
			.reduce( ( sum, e ) => {
				const end = e.end || now;
				return end > from && e.start < now ? sum + Math.min( end, now ) - Math.max( e.start, from ) : sum;
			}, 0 );
	},
	onlineToday() {
		return sprintf( /* translators: %s: time tracked today, e.g. "2h 5m". */ __( 'Online · %s today', 'novawork' ), dur( this.tracked( this.$store.nw.me, this.dayStart() ) ) );
	},
	longDate() {
		return longDate( this.$store.nw.now, this.$store.nw.locale );
	},
	/** Sample items left (owner decision: admins see a note until they're gone). */
	samples() {
		const s = this.$store.nw;
		return [ s.projects, s.tasks, s.decisions ].reduce( ( n, rows ) => n + Object.values( rows ).filter( ( r ) => r.sample ).length, 0 );
	},
	samplesText() {
		return sprintf(
			/* translators: %d: number of sample items. */
			_n( '%d sample item shows where to start.', '%d sample items show where to start.', this.samples(), 'novawork' ),
			this.samples()
		);
	},
	samplesBusy: false,
	async removeSamples() {
		if ( this.samplesBusy ) {
			return;
		}
		this.samplesBusy = true;
		try {
			await del( 'samples' );
			const s = this.$store.nw;
			[ 'projects', 'tasks', 'decisions' ].forEach( ( key ) => Object.values( s[ key ] ).filter( ( r ) => r.sample ).forEach( ( r ) => s.remove( key, r.id ) ) );
			toast( __( 'Sample items removed.', 'novawork' ), 'check' );
		} catch ( e ) {
			toast( ( e && e.message ) || __( 'Could not remove them. Try again.', 'novawork' ), 'x' );
		} finally {
			this.samplesBusy = false;
		}
	},
	openDecisions() {
		return Object.values( this.$store.nw.decisions ).filter( ( d ) => d.status !== 'decided' ).length;
	},
	unreadNotes() {
		return Object.values( this.$store.nw.notifications ).filter( ( n ) => ! n.read ).length;
	},
	bellHtml() {
		const n = this.unreadNotes();
		return ( this.notesOpen ? icf( 'bell', 17 ) : ic( 'bell', 17 ) ) + ( n ? `<span class="nw-nbadge">${ n > 9 ? '9+' : n }</span>` : '' );
	},
	bellLabel() {
		const n = this.unreadNotes();
		return n ? sprintf( /* translators: %d: number of unread notifications. */ __( '%d unread notifications', 'novawork' ), n ) : __( 'Notifications', 'novawork' );
	},

	/* ---------- tracking ---------- */
	trackTitle() {
		const run = this.myRun();
		const task = this.taskOf( run );
		return ( run ? sprintf( /* translators: %s: task title. */ __( 'Pause: %s', 'novawork' ), task ? task.title : '' ) : this.t.startTracking ) + '  (T)';
	},
	toggleTracking() {
		if ( this.myRun() ) {
			stopTracking();
		} else {
			startTracking( defaultTaskId() );
		}
	},

	/* ---------- overlays ---------- */
	scrim2Shown() {
		return this.phone && ( this.pop.open || this.fab.open || this.notesOpen );
	},
	togglePop() {
		if ( this.pop.open ) {
			this.closePop();
			return;
		}
		this.closeFab( true );
		this.closeNotes( true );
		lockBgScroll();
		// On phones the popover is a bottom sheet: it must leave the sticky
		// header (its own stacking context) or the scrim would cover it.
		( this.phone ? document.body : this.$refs.right ).appendChild( this.$refs.pop );
		this.pop.open = true;
		this.$nextTick( () => placeInd( this.$refs.appear, true ) );
	},
	closePop( instant ) {
		if ( ! this.pop.open || this.pop.closing ) {
			return;
		}
		this.pop.closing = true;
		const done = () => {
			this.pop.open = false;
			this.pop.closing = false;
			unlockBgScroll();
		};
		if ( instant ) {
			done();
		} else {
			sheetOut( this.$refs.pop, done, this.$refs.scrim2 );
		}
	},
	toggleNotes() {
		if ( this.notesOpen ) {
			this.closeNotes();
			return;
		}
		this.closePop( true );
		this.closeFab( true );
		lockBgScroll();
		// On phones the panel is a bottom sheet: it must leave the sticky
		// header (its own stacking context) or the scrim would cover it.
		( this.phone ? document.body : this.$refs.right ).appendChild( this.$refs.notes );
		this.notesOpen = true;
		this.$nextTick( () => emit( 'nw:notes-state', { open: true } ) );
	},
	closeNotes( instant ) {
		if ( ! this.notesOpen || this.notesClosing ) {
			return;
		}
		this.notesClosing = true;
		const done = () => {
			this.notesOpen = false;
			this.notesClosing = false;
			unlockBgScroll();
			emit( 'nw:notes-state', { open: false } );
		};
		if ( instant ) {
			done();
		} else {
			sheetOut( this.$refs.notes, done, this.$refs.scrim2 );
		}
	},
	toggleFab() {
		if ( this.fab.open ) {
			this.closeFab();
			return;
		}
		this.closePop( true );
		this.closeNotes( true );
		lockBgScroll();
		this.fab.open = true;
		this.$nextTick( () => {
			const first = this.$refs.fabSheet.querySelector( 'button' );
			if ( first ) {
				first.focus();
			}
		} );
	},
	closeFab( instant ) {
		if ( ! this.fab.open || this.fab.closing ) {
			return;
		}
		this.fab.closing = true;
		const done = () => {
			this.fab.open = false;
			this.fab.closing = false;
			unlockBgScroll();
		};
		if ( instant ) {
			done();
		} else {
			sheetOut( this.$refs.fabSheet, done, this.$refs.scrim2 );
		}
	},
	create( kind ) {
		this.closeFab();
		if ( kind === 'chat' ) {
			this.openChat();
			return;
		}
		emit( 'nw:create', { kind } );
	},
	openChat() {
		emit( 'nw:chat', { open: true } );
	},
	toggleChat() {
		emit( 'nw:chat', { open: ! this.chatOpen } );
	},
	setTheme( key ) {
		this.theme = key;
		store( 'nw.theme', key );
		if ( key === 'system' ) {
			delete document.documentElement.dataset.theme;
		} else {
			document.documentElement.dataset.theme = key;
		}
		this.$nextTick( () => placeInd( this.$refs.appear ) );
	},
	onPointerDown( e ) {
		if ( this.pop.open && ! this.$refs.pop.contains( e.target ) && ! e.target.closest( '#nw-crew-btn' ) ) {
			this.closePop();
		}
		if ( this.fab.open && ! this.$refs.fabSheet.contains( e.target ) && ! e.target.closest( '#nw-fab' ) ) {
			this.closeFab();
		}
		if ( this.notesOpen && ! this.$refs.notes.contains( e.target ) && ! e.target.closest( '#nw-bell-btn' ) ) {
			this.closeNotes();
		}
		if ( ! this.$refs.qs.contains( e.target ) && ! e.target.closest( '#nw-qs-btn' ) ) {
			this.qsClose();
		}
	},
	onKey( e ) {
		if ( e.key === 'Escape' ) {
			if ( this.pop.open ) {
				this.closePop();
				document.getElementById( 'nw-crew-btn' ).focus();
			}
			if ( this.fab.open ) {
				this.closeFab();
				document.getElementById( 'nw-fab' ).focus();
			}
			if ( this.notesOpen ) {
				this.closeNotes();
				document.getElementById( 'nw-bell-btn' ).focus();
			}
			return;
		}
		// Shortcuts: / search, N new task, T start or pause (never while typing or in a dialog).
		const target = e.target;
		if ( e.metaKey || e.ctrlKey || e.altKey || document.querySelector( 'dialog[open]' ) || /^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) || target.isContentEditable ) {
			return;
		}
		if ( e.key === '/' ) {
			e.preventDefault();
			this.qsShow();
		} else if ( e.key === 'n' || e.key === 'N' ) {
			e.preventDefault();
			emit( 'nw:create', { kind: 'task', status: 'now' } );
		} else if ( e.key === 't' || e.key === 'T' ) {
			e.preventDefault();
			this.toggleTracking();
		}
	},

	/* ---------- quick search ---------- */
	qsHighlight( text, q ) {
		text = String( text || '' );
		const i = q ? text.toLowerCase().indexOf( q ) : -1;
		return i < 0 ? esc( text ) : esc( text.slice( 0, i ) ) + '<mark>' + esc( text.slice( i, i + q.length ) ) + '</mark>' + esc( text.slice( i + q.length ) );
	},
	qsRun() {
		const nw = this.$store.nw;
		const raw = this.qs.q.trim();
		const q = raw.toLowerCase();
		if ( ! q ) {
			this.qs.showRes = false;
			this.qsItems = [];
			return;
		}
		const projectName = ( id ) => ( nw.projects[ id ] || {} ).name || '';
		const person = ( id ) => ( this.member( id ) || {} ).name || '';
		const statusLabel = { active: __( 'active', 'novawork' ), paused: __( 'paused', 'novawork' ), done: __( 'done', 'novawork' ) };
		// Messages: the ones on this device, plus older ones the server found.
		const msgs = new Map();
		Object.values( nw.messages )
			.filter( ( m ) => plainText( m ).toLowerCase().includes( q ) )
			.forEach( ( m ) => msgs.set( m.id, m ) );
		if ( this.qsOlder.q === q ) {
			this.qsOlder.rows.forEach( ( m ) => msgs.has( m.id ) || msgs.set( m.id, m ) );
		} else {
			this.qsFetchOlder( raw );
		}
		const groups = [
			[ __( 'Tasks', 'novawork' ), Object.values( nw.tasks ).filter( ( t ) => t.title.toLowerCase().includes( q ) ).slice( 0, 6 ).map( ( t ) => ( { icon: 'check', html: this.qsHighlight( t.title, q ), sub: projectName( t.projectId ), go: () => this.openItem( 'task', t.id ) } ) ) ],
			[ __( 'Projects', 'novawork' ), Object.values( nw.projects ).filter( ( p ) => p.name.toLowerCase().includes( q ) || ( p.goal || '' ).toLowerCase().includes( q ) ).slice( 0, 4 ).map( ( p ) => ( { icon: 'map-trifold', html: this.qsHighlight( p.name, q ), sub: statusLabel[ p.status ] || '', go: () => this.openItem( 'project', p.id ) } ) ) ],
			[ __( 'Decisions', 'novawork' ), Object.values( nw.decisions ).filter( ( d ) => d.question.toLowerCase().includes( q ) ).slice( 0, 4 ).map( ( d ) => ( { icon: 'scales', html: this.qsHighlight( d.question, q ), sub: d.status === 'decided' ? __( 'Decided', 'novawork' ) : __( 'Open', 'novawork' ), go: () => this.openItem( 'decision', d.id ) } ) ) ],
			// A message opens the chat on it (owner: go to the thing itself).
			[ __( 'Messages', 'novawork' ), [ ...msgs.values() ].sort( ( a, b ) => b.id - a.id ).slice( 0, 4 ).map( ( m ) => ( { icon: 'chat-circle-dots', html: this.qsHighlight( plainText( m ), q ), sub: person( m.by ) + ' · ' + ago( m.at, nw.now, nw.locale ), go: () => emit( 'nw:chat', { open: true, jump: m.id } ) } ) ) ],
		];
		this.qsItems = [];
		let html = '';
		groups.forEach( ( [ label, items ] ) => {
			if ( ! items.length ) {
				return;
			}
			html += `<div class="nw-nsec">${ esc( label ) }</div>`;
			items.forEach( ( it ) => {
				html += `<button type="button" class="nw-qs-item" data-qi="${ this.qsItems.length }">${ ic( it.icon ) }<span class="nw-tt">${ it.html }</span><span class="nw-sub">${ esc( it.sub ) }</span></button>`;
				this.qsItems.push( it );
			} );
		} );
		this.qs.html = html || `<div class="nw-empty" style="border:0;margin:4px">${ ic( 'magnifying-glass' ) }${ esc( sprintf( /* translators: %s: what was searched for. */ __( 'Nothing matches “%s”.', 'novawork' ), raw ) ) }</div>`;
		this.qs.showRes = true;
		// A redraw for the same words (older messages arrived) keeps your place.
		this.qs.act = this.qsLastQ === q ? Math.min( this.qs.act, Math.max( 0, this.qsItems.length - 1 ) ) : 0;
		this.qsLastQ = q;
		this.$nextTick( () => this.qsMark() );
	},
	/** Ask the server for older matching messages (debounced); redraw when they come. */
	qsFetchOlder( raw ) {
		clearTimeout( this.qsTimer );
		const q = raw.toLowerCase();
		if ( q.length < 2 ) {
			return;
		}
		this.qsTimer = setTimeout( async () => {
			try {
				const rows = await get( 'messages', { q: raw, limit: 4 } );
				this.qsOlder = { q, rows };
				if ( this.qs.q.trim().toLowerCase() === q && this.qs.showRes ) {
					this.qsRun();
				}
			} catch ( e ) {
				// Local results stand.
			}
		}, 250 );
	},
	qsMark() {
		const items = this.$refs.qsRes.querySelectorAll( '.nw-qs-item' );
		items.forEach( ( b, i ) => b.classList.toggle( 'nw-active', i === this.qs.act ) );
		const active = this.$refs.qsRes.querySelector( '.nw-qs-item.nw-active' );
		if ( active ) {
			active.scrollIntoView( { block: 'nearest' } );
		}
	},
	qsLock() {
		if ( ! this.qs.locked ) {
			this.qs.locked = true;
			lockBgScroll();
		}
	},
	qsUnlock() {
		if ( this.qs.locked ) {
			this.qs.locked = false;
			unlockBgScroll();
		}
	},
	qsFocus() {
		this.qsLock();
		this.qsRun();
	},
	qsClose() {
		if ( ! this.qs.showRes && ! this.qs.open && ! this.qs.locked ) {
			return;
		}
		this.qs.showRes = false;
		this.qsUnlock();
		if ( this.qs.open && ( this.phone || this.compact ) && window.matchMedia( '(prefers-reduced-motion: no-preference)' ).matches ) {
			this.qs.open = false;
			this.qs.closing = true;
			setTimeout( () => {
				this.qs.closing = false;
			}, 190 );
		} else {
			this.qs.open = false;
		}
	},
	qsPick( i ) {
		const item = this.qsItems[ i ];
		if ( ! item ) {
			return;
		}
		this.qs.q = '';
		this.qsClose();
		this.$refs.qsIn.blur();
		item.go();
	},
	qsKey( e ) {
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			this.qs.act = Math.min( this.qs.act + 1, this.qsItems.length - 1 );
			this.qsMark();
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			this.qs.act = Math.max( this.qs.act - 1, 0 );
			this.qsMark();
		} else if ( e.key === 'Enter' ) {
			e.preventDefault();
			this.qsPick( this.qs.act );
		} else if ( e.key === 'Escape' ) {
			e.stopPropagation();
			this.qs.q = '';
			this.qsClose();
			this.$refs.qsIn.blur();
		}
	},
	qsClick( e ) {
		const b = e.target.closest( '[data-qi]' );
		if ( b ) {
			this.qsPick( Number( b.dataset.qi ) );
		}
	},
	qsOpenPhone() {
		this.qsLock();
		this.qsShow();
	},
	/**
	 * Open search and focus the field in the same tick. On phones the field is
	 * hidden until .nw-open applies; Alpine would add it a moment later, and a
	 * key typed in between would reach the page (T starts the timer). So the
	 * class goes on directly, as in the prototype, and the binding agrees.
	 */
	qsShow() {
		this.qs.closing = false;
		this.qs.open = true;
		this.$refs.qs.classList.add( 'nw-open' );
		this.$refs.qsIn.focus();
	},
	qsCancel() {
		this.qs.q = '';
		this.qsClose();
		this.$refs.qsIn.blur();
	},
	/** A search result: go to the item's page and its card (owner), not its editor. */
	openItem( kind, id ) {
		emit( 'nw:goto', { kind, id } );
	},
} );
