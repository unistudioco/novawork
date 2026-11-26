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
} );
JS
		);
		wp_enqueue_script( 'novawork-test-addon' );
		wp_register_style( 'novawork-test-addon', false, array(), '1' );
		wp_add_inline_style( 'novawork-test-addon', '#nw-x-test{outline-offset:0}' );
		wp_enqueue_style( 'novawork-test-addon' );
	}
);
