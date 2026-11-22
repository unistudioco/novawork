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
import { post } from './lib/api.js';
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

	init() {
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
