<?php
/**
 * Plugin Name: NovaWork test add-on (browser tests only)
 * Description: Uses every 1.3.0 extension point, so tests/browser/addons.spec.js can check them. Linked into mu-plugins by that test and removed after it; does nothing unless the option novawork_test_addon is "1". Never ships (tests/ is in .distignore).
 *
 * @package NovaWork
 */

// phpcs:disable -- Test fixture, not shipped.

defined( 'ABSPATH' ) || exit;

if ( '1' !== get_option( 'novawork_test_addon' ) ) {
	return;
}

add_filter(
	'novawork_agents',
	static fn( $agents ) => array_merge( (array) $agents, array( array( 'key' => 'helper', 'name' => 'Helper', 'color' => 'teal' ) ) )
);

add_filter( 'novawork_boot', static fn( $addons, $user_id ) => array_merge( (array) $addons, array( 'testaddon' => array( 'hello' => 'boot', 'user' => $user_id ) ) ), 10, 2 );

add_filter( 'novawork_sync', static fn( $addons, $user_id ) => array_merge( (array) $addons, array( 'testaddon' => array( 'flag' => (bool) get_user_meta( $user_id, 'novawork_test_flag', true ) ) ) ), 10, 2 );

add_filter( 'novawork_settings_schema', static fn( $rules ) => array_merge( (array) $rules, array( 'testaddon_level' => array( 'type' => 'int', 'min' => 1, 'max' => 3 ) ) ) );

add_action(
	'novawork_app_enqueue',
	static function () {
		// A classic script: it runs before the app's module and waits for nw:ready.
		wp_register_script( 'novawork-test-addon', false, array( 'novawork-app' ), '1', true );
		wp_add_inline_script(
			'novawork-test-addon',
			<<<'JS'
window.addEventListener( 'nw:ready', ( e ) => {
	const app = e.detail;
	window.nwTestAddon = { keys: Object.keys( app ).sort(), boot: window.novawork.addons.testaddon, same: app === window.novawork.app };
	app.addHeaderButton( { id: 'test', icon: 'bell-ringing', label: 'Test add-on', onClick: () => app.toast( 'Header button works' ), badge: () => !! ( app.store.addons.testaddon && app.store.addons.testaddon.flag ) } );
	app.addMenuItem( { id: 'test', icon: 'sparkle', label: 'Test add-on row', onClick: () => app.toast( 'Menu row works' ) } );
	app.addNoteKind( 'test_ping', { icon: 'bell-ringing', color: 'teal', text: ( n, h ) => h.b( h.who() ) + ' ' + h.esc( 'pinged you about' ) + ' ' + h.b( n.data.title || '' ) } );
	// A toggle on the agent's messages and comments only.
	const pressed = new Set();
	app.addMessageAction( { id: 'test', icon: 'sparkle', label: 'Test action', when: ( m ) => m.agent === 'helper', pressed: ( m, kind ) => pressed.has( kind + ':' + m.id ), onClick: ( m, kind ) => { const k = kind + ':' + m.id; if ( pressed.has( k ) ) { pressed.delete( k ); } else { pressed.add( k ); } } } );
	app.addPanel( { id: 'test', title: 'Test panel', render: ( body ) => { body.innerHTML = '<p id="nw-test-panel-text">Panel works</p>'; } } );
	window.nwTestStatus = ( where, text ) => app.setThreadStatus( where, text );
	// A docked panel, a launcher that opens it, and a shortcut (1.3.0).
	app.addPanel( {
		id: 'testdock',
		title: 'Test dock',
		style: 'docked',
		render: ( body ) => {
			window.nwTestDockRenders = ( window.nwTestDockRenders || 0 ) + 1;
			const me = app.store.members.find( ( m ) => m.id === app.store.me );
			body.innerHTML = '<div id="nw-test-dock-text">' + app.renderText( { text: 'Hello @' + me.name + ', see /Docked link', mentions: [ { id: me.id, name: me.name } ], refs: [] } ) + '</div><textarea id="nw-test-dock-in" aria-label="Test field"></textarea>';
		},
		onOpen: () => { window.nwTestDockOpens = ( window.nwTestDockOpens || 0 ) + 1; },
	} );
	window.nwTestLauncher = app.addLauncher( { id: 'test', icon: 'sparkle', label: 'Test launcher', key: 'H', onClick: () => ( app.isPanelOpen( 'testdock' ) ? app.closePanel( 'testdock' ) : app.openPanel( 'testdock' ) ) } );
	window.nwTestTaken = app.addShortcut( { key: 'n', label: 'Taken', run: () => {} } );
	app.addShortcut( { key: 'h', label: 'Test dock', run: () => app.openPanel( 'testdock' ) } );
} );
JS
		);
		wp_enqueue_script( 'novawork-test-addon' );
		wp_register_style( 'novawork-test-addon', false, array(), '1' );
		wp_add_inline_style( 'novawork-test-addon', '#nw-x-test{outline-offset:0}' );
		wp_enqueue_style( 'novawork-test-addon' );
	}
);

// A page under the NovaWork menu built with the settings screen's look and
// the add-on page kit (1.3.0). A classic inline script runs before the kit's
// module, so it waits for nw:admin-ready.
add_action(
	'admin_menu',
	static function () {
		$hook = add_submenu_page( 'novawork-settings', 'Test add-on page', 'Test add-on', 'novawork_manage', 'novawork-test-addon', 'novawork_test_addon_page' );
		add_action(
			'admin_enqueue_scripts',
			static function ( $suffix ) use ( $hook ) {
				if ( $suffix !== $hook ) {
					return;
				}
				NovaWork\Admin\SettingsPage::enqueue_styles();
				wp_register_script( 'novawork-test-addon-page', false, array( 'novawork-admin-kit' ), '1', true );
				wp_add_inline_script(
					'novawork-test-addon-page',
					<<<'JS'
window.addEventListener( 'nw:admin-ready', ( e ) => {
	const kit = e.detail;
	window.nwTestAddonPage = { keys: Object.keys( kit ).sort(), same: kit === window.novawork.admin };
	kit.Alpine.data( 'nwTestPage', () => ( {
		pick: 'b',
		init() {
			this.$nextTick( () => kit.enhance( this.$root ) );
		},
		icon: ( name ) => kit.icf( name, 18 ),
		hello() {
			kit.toast( 'Kit toast works' );
		},
	} ) );
} );
JS
				);
				wp_enqueue_script( 'novawork-test-addon-page' );
			}
		);
	},
	30
);

function novawork_test_addon_page() {
	?>
	<div class="wrap nw-admin" id="nw-test-page" x-data="nwTestPage">
		<section class="nw-scard" aria-labelledby="nw-t-card">
			<h3 id="nw-t-card"><span x-html="icon('sparkle')"></span> Test add-on page</h3>
			<div class="nw-srow"><div class="nw-srow-l"><label class="nw-slabel" for="nw-t-pick">Pick</label></div><div class="nw-srow-c">
				<select id="nw-t-pick" data-nw-sel data-block="1" x-model="pick"><option value="a">First</option><option value="b">Second</option></select>
			</div></div>
			<p><button type="button" class="nw-btn nw-primary" id="nw-t-hello" @click="hello()">Say hello</button> <span id="nw-t-picked" x-text="pick"></span> <span id="nw-t-help" tabindex="0" data-tip="Help from the kit">?</span></p>
		</section>
	</div>
	<?php
}
