/**
 * NovaWork → Settings in wp-admin (owner decision: NovaWork's own UI inside
 * the normal wp-admin shell, like WordPress 7's Connectors screen). Alpine
 * holds the values; Save sends them to POST novawork/v1/settings, which
 * checks each one and answers with every problem by field.
 */
import '../css/admin.css';
import Alpine from 'alpinejs';
import { ic, icf } from './lib/icons.js';
import { __, _n, sprintf } from './lib/i18n.js';
import { get, post, del } from './lib/api.js';
import { toast } from './lib/ui.js';
import { enhance } from './views/select.js';

const boot = window.novawork || {};
const state = boot.settings || { values: {}, defaults: {}, site: {} };
const copy = ( v ) => JSON.parse( JSON.stringify( v ) );

Alpine.data( 'nwSettings', () => ( {
	values: copy( state.values ),
	saved: copy( state.values ),
	site: state.site || {},
	errors: {},
	saving: false,
	invites: [],
	media: copy( state.media || {} ),
	presets: state.presets || [],
	accentText: state.values.accent || '',
	invite: { email: '', send: true, busy: false, link: '', note: '', copied: false, error: '' },

	async loadInvites() {
		try {
			this.invites = await get( 'invites' );
		} catch ( e ) {}
	},
	chooseLabel() {
		return __( 'Choose image', 'novawork' );
	},
	changeLabel() {
		return __( 'Change', 'novawork' );
	},
	/** WordPress's media library, images only. */
	chooseImage( key ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: key === 'brand_icon' ? __( 'Choose an icon', 'novawork' ) : __( 'Choose a logo', 'novawork' ),
			library: { type: 'image' },
			multiple: false,
			button: { text: __( 'Use this image', 'novawork' ) },
		} );
		frame.on( 'select', () => {
			const a = frame.state().get( 'selection' ).first().toJSON();
			const sizes = a.sizes || {};
			this.values[ key ] = a.id;
			this.media[ key ] = ( key === 'brand_icon' ? sizes.thumbnail : sizes.medium || sizes.full || {} )?.url || a.url;
		} );
		frame.open();
	},
	removeImage( key ) {
		this.values[ key ] = 0;
		this.media[ key ] = '';
	},
	/** #rgb, #rrggbb or rgb(r, g, b) → #rrggbb, or '' when it isn't a colour. */
	toHex( text ) {
		const v = String( text ).trim().toLowerCase();
		let m = v.match( /^#?([0-9a-f]{3})$/ );
		if ( m ) {
			return '#' + m[ 1 ].split( '' ).map( ( c ) => c + c ).join( '' );
		}
		m = v.match( /^#?([0-9a-f]{6})$/ );
		if ( m ) {
			return '#' + m[ 1 ];
		}
		m = v.match( /^rgba?\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})/ );
		if ( m && m.slice( 1, 4 ).every( ( n ) => +n <= 255 ) ) {
			return '#' + m.slice( 1, 4 ).map( ( n ) => ( +n ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
		}
		return '';
	},
	setAccent( c ) {
		this.values.accent = c;
		this.accentText = c;
		delete this.errors.accent;
	},
	readAccent() {
		const hex = this.toHex( this.accentText );
		if ( hex ) {
			this.setAccent( hex );
		} else {
			this.errors = Object.assign( {}, this.errors, { accent: __( 'Enter a colour as #hex or rgb(r, g, b).', 'novawork' ) } );
		}
	},
	copyLabel() {
		return this.invite.copied ? __( 'Copied', 'novawork' ) : __( 'Copy', 'novawork' );
	},
	anyoneLabel() {
		return __( 'Anyone with the link', 'novawork' );
	},
	inviteState( inv ) {
		const days = Math.max( 1, Math.ceil( ( inv.expiresAt - Date.now() ) / 864e5 ) );
		if ( inv.status === 'used' ) {
			return sprintf( /* translators: %s: a person's name. */ __( 'Joined: %s', 'novawork' ), inv.usedByName );
		}
		if ( inv.status === 'expired' ) {
			return __( 'Expired', 'novawork' );
		}
		return sprintf( /* translators: %d: number of days. */ _n( 'Open, %d day left', 'Open, %d days left', days, 'novawork' ), days );
	},
	async createInvite() {
		if ( this.invite.busy ) {
			return;
		}
		this.invite.busy = true;
		this.invite.error = '';
		try {
			const email = this.invite.email.trim();
			const res = await post( 'invites', { email, send: !! email && this.invite.send } );
			this.invite.link = res.link;
			this.invite.copied = false;
			this.invite.note = res.sent
				? sprintf( /* translators: %s: email address. */ __( 'Sent to %s. You can also share this link; it works once.', 'novawork' ), email )
				: __( 'Copy this link and send it to them. It works once, and you won’t see it again.', 'novawork' );
			this.invite.email = '';
			await this.loadInvites();
		} catch ( e ) {
			this.invite.error = ( e && e.message ) || __( 'The invite wasn’t created. Try again.', 'novawork' );
		} finally {
			this.invite.busy = false;
		}
	},
	async copyInvite() {
		try {
			await navigator.clipboard.writeText( this.invite.link );
			this.invite.copied = true;
		} catch ( e ) {
			// No clipboard API on plain-http sites: select the link and copy it the old way.
			const field = this.$root.querySelector( '.nw-invlink input' );
			field.focus();
			field.select();
			try {
				this.invite.copied = document.execCommand( 'copy' );
			} catch ( err ) {}
		}
	},
	async revokeInvite( inv ) {
		try {
			await del( 'invites/' + inv.id );
			this.invites = this.invites.filter( ( x ) => x.id !== inv.id );
			toast( __( 'Invite revoked.', 'novawork' ), 'check' );
		} catch ( e ) {
			toast( __( 'Could not revoke it. Try again.', 'novawork' ), 'x' );
		}
	},

	init() {
		this.loadInvites();
		this.$nextTick( () => {
			enhance( this.$root );
			this.steppers();
		} );
		// Leaving with unsaved changes asks first.
		window.addEventListener( 'beforeunload', ( e ) => {
			if ( this.dirty() ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
	},

	icon( name, size, fill ) {
		return fill ? icf( name, size ) : ic( name, size );
	},
	name() {
		return ( this.values.workspace_name || '' ).trim() || this.site.title || 'NovaWork';
	},
	/**
	 * File types are a list; the field shows them comma-separated. What you
	 * type stays as typed (commas, ".fig"); the list is read from it.
	 */
	typesText: ( state.values.upload_types || [] ).join( ', ' ),
	readTypes() {
		this.values.upload_types = String( this.typesText )
			.split( /[\s,]+/ )
			.map( ( t ) => t.replace( /^\./, '' ).toLowerCase() )
			.filter( Boolean );
	},
	showTypes() {
		this.typesText = ( this.values.upload_types || [] ).join( ', ' );
	},
	/** Our own up/down chevrons on every number field (no browser spinner). */
	steppers() {
		this.$root.querySelectorAll( 'input[type="number"].nw-input' ).forEach( ( input ) => {
			if ( input.parentElement.classList.contains( 'nw-num' ) ) {
				return;
			}
			const wrap = document.createElement( 'span' );
			wrap.className = 'nw-num';
			input.before( wrap );
			wrap.appendChild( input );
			const steps = document.createElement( 'span' );
			steps.className = 'nw-num-steps';
			const button = ( dir, icon, label ) => {
				const b = document.createElement( 'button' );
				b.type = 'button';
				b.tabIndex = -1; // The field itself takes the arrow keys.
				b.setAttribute( 'aria-label', label );
				b.innerHTML = ic( icon, 12 );
				const step = () => {
					const before = input.value;
					if ( dir > 0 ) {
						input.stepUp();
					} else {
						input.stepDown();
					}
					if ( input.value !== before ) {
						input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					}
				};
				// Press once for one step; hold to keep going, faster the longer
				// you hold (owner request), until you let go.
				let timer = null;
				const stop = () => {
					clearTimeout( timer );
					timer = null;
				};
				b.addEventListener( 'pointerdown', ( e ) => {
					if ( e.button !== 0 ) {
						return;
					}
					e.preventDefault();
					b.setPointerCapture( e.pointerId );
					input.focus();
					step();
					let delay = 120;
					const repeat = () => {
						step();
						delay = Math.max( 30, delay * 0.85 );
						timer = setTimeout( repeat, delay );
					};
					timer = setTimeout( repeat, 400 );
				} );
				[ 'pointerup', 'pointercancel', 'lostpointercapture' ].forEach( ( type ) => b.addEventListener( type, stop ) );
				return b;
			};
			steps.append( button( 1, 'caret-up', __( 'More', 'novawork' ) ), button( -1, 'caret-down', __( 'Less', 'novawork' ) ) );
			wrap.appendChild( steps );
		} );
	},
	/** The site's address without "https://", before the workspace's address. */
	homeLabel() {
		return String( this.site.home || '' ).replace( /^https?:\/\//, '' );
	},
	serverLimit() {
		return sprintf( /* translators: %d: megabytes. */ __( 'Your server accepts files up to %d MB, so that is the real limit until your host raises it.', 'novawork' ), this.site.serverMaxMb );
	},
	dirty() {
		return JSON.stringify( this.values ) !== JSON.stringify( this.saved );
	},
	errorCount() {
		return Object.keys( this.errors ).length;
	},
	barText() {
		const n = this.errorCount();
		return n
			? sprintf( /* translators: %d: number of settings with a problem. */ _n( '%d setting needs a look.', '%d settings need a look.', n, 'novawork' ), n )
			: __( 'You have unsaved changes.', 'novawork' );
	},
	saveLabel() {
		return this.saving ? __( 'Saving…', 'novawork' ) : __( 'Save changes', 'novawork' );
	},
	discard() {
		this.values = copy( this.saved );
		this.media = copy( state.media || {} );
		this.accentText = this.values.accent;
		this.showTypes();
		this.errors = {};
		this.$nextTick( () => enhance( this.$root ) );
	},
	async save() {
		if ( this.saving ) {
			return;
		}
		this.saving = true;
		this.errors = {};
		// Every setting is sent, so a switch turned off is saved as off.
		const changedAddress = this.values.app_slug !== this.saved.app_slug || this.values.location !== this.saved.location;
		try {
			const res = await post( 'settings', { values: this.values } );
			this.values = copy( res.values );
			this.saved = copy( res.values );
			this.site = res.site || this.site;
			this.media = res.media || this.media;
			this.accentText = this.values.accent;
			this.showTypes();
			this.$nextTick( () => enhance( this.$root ) );
			toast( changedAddress ? sprintf( /* translators: %s: the workspace's address. */ __( 'Saved. The workspace is now at %s', 'novawork' ), this.site.appUrl ) : __( 'Settings saved.', 'novawork' ), 'check' );
		} catch ( e ) {
			this.errors = ( e && e.data && e.data.data && e.data.data.errors ) || {};
			const first = Object.keys( this.errors )[ 0 ];
			if ( first ) {
				const row = this.$root.querySelector( `[x-show="errors.${ first }"]` );
				if ( row ) {
					row.closest( '.nw-srow' ).scrollIntoView( { block: 'center', behavior: 'smooth' } );
				}
			} else {
				toast( ( e && e.message ) || __( 'Could not save. Check your connection and try again.', 'novawork' ), 'x' );
			}
		} finally {
			this.saving = false;
		}
	},
} ) );

Alpine.start();
