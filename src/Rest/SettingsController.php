<?php
/**
 * Settings API for the wp-admin settings screen.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Rest;

use NovaWork\App\Route;
use NovaWork\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET|POST /settings, administrators only. A save checks every sent value
 * against Settings::SCHEMA and stores nothing if any of them is wrong, so the
 * screen can show each problem next to its field.
 */
final class SettingsController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->admin_route( '/settings', WP_REST_Server::READABLE, array( $this, 'show' ) );
		$this->admin_route(
			'/settings',
			WP_REST_Server::CREATABLE,
			array( $this, 'save' ),
			array(
				'values' => array(
					'type'     => 'object',
					'required' => true,
				),
			)
		);
		$this->admin_route( '/settings/fonts-offer', WP_REST_Server::DELETABLE, array( $this, 'dismiss_fonts_offer' ) );
	}

	/**
	 * "No thanks" on the settings screen's offer of NovaWork's typefaces.
	 */
	public function dismiss_fonts_offer(): WP_REST_Response {
		update_option( Settings::FONTS_OFFER_OPTION, 'done', false );
		return self::respond( self::state() );
	}

	/**
	 * Every setting and what the screen needs to explain them.
	 */
	public function show(): WP_REST_Response {
		return self::respond( self::state() );
	}

	/**
	 * Save the sent settings (all or some).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// NovaWork's own settings only: an add-on stores its own (1.3.0).
		list( $clean, $errors ) = Settings::clean_values( (array) $request->get_param( 'values' ), array_keys( Settings::SCHEMA ) );
		if ( $errors ) {
			return new WP_Error(
				'novawork_invalid_settings',
				__( 'Some settings need a look.', 'novawork' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		$before = Settings::all();
		$stored = get_option( Settings::OPTION, array() );
		update_option( Settings::OPTION, array_merge( is_array( $stored ) ? $stored : array(), $clean ) );

		// Choosing either way about Fontshare answers the offer for good.
		if ( isset( $clean['load_fontshare'] ) && $clean['load_fontshare'] !== $before['load_fontshare'] ) {
			update_option( Settings::FONTS_OFFER_OPTION, 'done', false );
		}

		// A new address: refresh WordPress's rewrite rules, and keep the old
		// address working (it forwards to the new one).
		if ( ( $clean['app_slug'] ?? $before['app_slug'] ) !== $before['app_slug'] || ( $clean['location'] ?? $before['location'] ) !== $before['location'] ) {
			Route::remember_slug( (string) $before['app_slug'] );
			update_option( Route::FLUSH_OPTION, 1 );
		}

		/**
		 * Fires after an administrator saves NovaWork's settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $after  Every setting after the save.
		 * @param array<string, mixed> $before Every setting before it.
		 */
		do_action( 'novawork_settings_saved', Settings::all(), $before );

		return self::respond( self::state() );
	}

	/**
	 * Values, defaults and facts about the site for the screen.
	 *
	 * @return array<string, mixed>
	 */
	private static function state(): array {
		return array(
			'values'   => Settings::all(),
			'defaults' => Settings::defaults(),
			'site'     => array(
				'title'         => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'home'          => trailingslashit( home_url( '/' ) ),
				'appUrl'        => Route::url(),
				'prettyLinks'   => (bool) get_option( 'permalink_structure' ),
				'serverMaxMb'   => (int) floor( wp_max_upload_size() / MB_IN_BYTES ),
				'workspaceName' => Settings::workspace_name(),
				'samples'       => \NovaWork\Services\Samples::count(),
				'fontsOffer'    => ! Settings::get( 'load_fontshare' ) && 'done' !== get_option( Settings::FONTS_OFFER_OPTION ),
				'pro'           => \NovaWork\Admin\ProLink::get(),
			),
			// Previews of the uploaded branding images.
			'media'    => array(
				'brand_icon'      => \NovaWork\App\Branding::image( 'brand_icon', 'thumbnail' ),
				'brand_logo'      => \NovaWork\App\Branding::image( 'brand_logo', 'medium' ),
				'brand_logo_dark' => \NovaWork\App\Branding::image( 'brand_logo_dark', 'medium' ),
			),
			'presets'  => \NovaWork\App\Branding::PRESETS,
		);
	}
}
