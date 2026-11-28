<?php
/**
 * Plugin Name: NovaWork test offline (browser and HTTP tests)
 * Description: Linked into mu-plugins by bin/offline.sh for the browser run (setup.js, teardown.js) and bin/test-http.sh, and removed after them (bin/offline.sh off, also run by bin/addons.sh on after an interrupted run). While option novawork_test_offline is "1", the site makes no request outside itself: each attempt is refused and noted in novawork_test_outside, and the run fails with the list. WordPress's own update checks are answered from here, so they don't try. Never ships (tests/ is in .distignore).
 *
 * @package NovaWork
 */

// phpcs:disable -- Test fixture, not shipped.

defined( 'ABSPATH' ) || exit;

if ( '1' === get_option( 'novawork_test_offline' ) ) {
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) {
			$host  = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			$local = array( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'localhost', '127.0.0.1', '::1' );
			if ( in_array( $host, $local, true ) ) {
				return $pre;
			}
			$seen   = (array) get_option( 'novawork_test_outside', array() );
			$seen[] = (string) $url;
			update_option( 'novawork_test_outside', array_slice( array_values( array_unique( $seen ) ), -50 ), false );
			return new WP_Error( 'novawork_test_offline', 'Tests make no request outside the site.' );
		},
		1,
		3
	);
	// Update checks: "just checked, nothing new" for exactly what is
	// installed (an empty or stale "checked" list makes WordPress ask again).
	$fresh = static function ( string $kind ) {
		$checked = array();
		if ( 'update_plugins' === $kind ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( get_plugins() as $file => $plugin ) {
				$checked[ $file ] = $plugin['Version'];
			}
		} elseif ( 'update_themes' === $kind ) {
			foreach ( wp_get_themes() as $slug => $theme ) {
				$checked[ $slug ] = $theme->get( 'Version' );
			}
		}
		return (object) array(
			'last_checked'    => time(),
			'updates'         => array(),
			'response'        => array(),
			'no_update'       => array(),
			'translations'    => array(),
			'version_checked' => get_bloginfo( 'version' ),
			'checked'         => $checked,
		);
	};
	foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $transient ) {
		add_filter( 'pre_site_transient_' . $transient, static fn() => $fresh( $transient ) );
	}
	add_filter( 'automatic_updater_disabled', '__return_true' );
}
