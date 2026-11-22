<?php
/**
 * NovaWork → Settings in wp-admin.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Admin;

use NovaWork\Access\Capabilities;
use NovaWork\App\Assets;
use WP_REST_Request;

/**
 * Owner decision: the screen sits inside the normal wp-admin shell (menu and
 * admin bar stay), like WordPress 7's Settings → Connectors, but its content
 * uses NovaWork's own UI. Every style is scoped under `.nw-admin`, and the
 * bundle loads on this screen only. Values are saved through the REST API
 * (POST novawork/v1/settings), which checks each one.
 */
final class SettingsPage {

	public const SLUG = 'novawork-settings';

	/**
	 * The screen's hook suffix, once the menu exists.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'admin_head', array( self::class, 'light_only' ) );
	}

	/**
	 * The cards stay light like the rest of wp-admin, whatever the device's
	 * appearance (the tokens follow the system unless told otherwise).
	 */
	public static function light_only(): void {
		$screen = get_current_screen();
		if ( $screen && self::$hook === $screen->id ) {
			wp_print_inline_script_tag( 'document.documentElement.dataset.theme="light";' );
		}
	}

	/**
	 * Add the submenu item.
	 */
	public static function add(): void {
		$hook = add_submenu_page( Menu::SLUG, __( 'NovaWork settings', 'novawork' ), __( 'Settings', 'novawork' ), Capabilities::MANAGE, self::SLUG, array( self::class, 'render' ) );
		if ( is_string( $hook ) ) {
			self::$hook = $hook;
		}
	}

	/**
	 * Whether the current screen is this one.
	 *
	 * @param string $hook_suffix Hook suffix from admin_enqueue_scripts.
	 */
	private static function is_screen( string $hook_suffix ): bool {
		return '' !== self::$hook && self::$hook === $hook_suffix;
	}

	/**
	 * A stable body class to scope the screen (never the derived page class).
	 *
	 * @param string $classes Body classes.
	 */
	public static function body_class( string $classes ): string {
		$screen = get_current_screen();
		if ( $screen && self::$hook === $screen->id ) {
			$classes .= ' nw-admin-screen';
		}
		return $classes;
	}

	/**
	 * Scripts and styles for this screen only.
	 *
	 * @param string $hook_suffix Hook suffix.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! self::is_screen( $hook_suffix ) ) {
			return;
		}
		Assets::register_admin();
		wp_enqueue_media(); // Branding images come from the media library.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/novawork/v1/settings' ) );
		$boot     = array(
			'restUrl'  => esc_url_raw( rest_url( 'novawork/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'locale'   => str_replace( '_', '-', determine_locale() ),
			'settings' => $response->is_error() ? null : $response->get_data(),
		);
		wp_add_inline_script( Assets::ADMIN_SCRIPT, 'window.novawork = ' . wp_json_encode( $boot ) . ';', 'before' );
		wp_enqueue_script( Assets::ADMIN_SCRIPT );
		foreach ( Assets::admin_styles() as $handle ) {
			wp_enqueue_style( $handle );
		}
	}

	/**
	 * Print the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Only an administrator can change NovaWork’s settings.', 'novawork' ), 403 );
		}
		require NOVAWORK_PATH . 'views/admin-settings.php';
	}
}
