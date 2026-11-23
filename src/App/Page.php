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

defined( 'ABSPATH' ) || exit;

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
		if ( current_user_can( Capabilities::MANAGE ) ) {
			\NovaWork\Services\Samples::maybe_create( get_current_user_id() );
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
			'restUrl'     => esc_url_raw( rest_url( 'novawork/v1/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'me'          => get_current_user_id(),
			'appUrl'      => esc_url_raw( Route::url() ),
			// wp_logout_url() is escaped for HTML; the app needs the plain address.
			'logoutUrl'   => esc_url_raw( wp_specialchars_decode( wp_logout_url( Route::login_url( true ) ) ) ),
			'locale'      => str_replace( '_', '-', determine_locale() ),
			'version'     => NOVAWORK_VERSION,
			// Where an administrator invites people (the "Invite your team" row).
			'inviteUrl'   => current_user_can( Capabilities::MANAGE ) ? esc_url_raw( admin_url( 'admin.php?page=novawork-settings#nw-members' ) ) : '',
			// Administrators reach the settings screen from the workspace (owner, 2026-11-23).
			'settingsUrl' => current_user_can( Capabilities::MANAGE ) ? esc_url_raw( admin_url( 'admin.php?page=novawork-settings' ) ) : '',
			// The welcome tour starts by itself once per member (owner, 2026-11-23).
			'tour'        => array( 'auto' => \NovaWork\Rest\TourController::should_start( get_current_user_id() ) ),
			// Desktop alerts: the worker that shows them (Android shows alerts only
			// through one) and the icon they carry.
			'alerts'      => array(
				'worker' => esc_url_raw( NOVAWORK_URL . 'assets/alerts-worker.js' ),
				'icon'   => esc_url_raw( self::alert_icon() ),
				'name'   => \NovaWork\Settings::workspace_name(),
			),
			'sync'        => $response->is_error() ? null : $response->get_data(),
		);
	}

	/**
	 * The picture on a desktop alert: the workspace icon, else the site icon.
	 */
	private static function alert_icon(): string {
		$icon = Branding::image( 'brand_icon', 'thumbnail' );
		return '' !== $icon ? $icon : (string) get_site_icon_url( 192 );
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
