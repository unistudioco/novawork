<?php
/**
 * Renders the app document.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

use NovaWork\Access\Capabilities;
use WP_REST_Request;

/**
 * Prints NovaWork's own HTML document. Nothing from the theme or other
 * plugins' front-end assets loads here: only NovaWork's styles, its script
 * and WordPress's i18n script for translations.
 */
final class Page {

	/**
	 * Print the app, or a short note for a signed-in user who isn't a member.
	 */
	public static function render(): void {
		Assets::register();
		if ( ! current_user_can( Capabilities::ACCESS ) ) {
			status_header( 403 );
			self::view( 'no-access', array() );
			return;
		}
		wp_add_inline_script( Assets::SCRIPT, 'window.novawork = ' . wp_json_encode( self::boot() ) . ';', 'before' );
		self::view( 'app', array() );
	}

	/**
	 * What the app needs to start: where the API is, who is signed in, and
	 * the first sync so the first paint already has the team's data.
	 *
	 * @return array<string, mixed>
	 */
	private static function boot(): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/novawork/v1/sync' ) );
		return array(
			'restUrl'   => esc_url_raw( rest_url( 'novawork/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'me'        => get_current_user_id(),
			'appUrl'    => esc_url_raw( Route::url() ),
			'logoutUrl' => esc_url_raw( wp_logout_url( Route::url() ) ),
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'version'   => NOVAWORK_VERSION,
			'sync'      => $response->is_error() ? null : $response->get_data(),
		);
	}

	/**
	 * Include a template from views/.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $vars Variables for the template.
	 */
	private static function view( string $name, array $vars ): void {
		unset( $vars );
		require NOVAWORK_PATH . 'views/' . $name . '.php';
	}
}
