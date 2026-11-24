<?php
/**
 * The NovaWork menu in wp-admin.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Admin;

use NovaWork\Access\Capabilities;
use NovaWork\App\Route;

defined( 'ABSPATH' ) || exit;

/**
 * A top-level "NovaWork" menu, below WordPress's own items. For an
 * administrator it opens Settings, with "Visit Workspace" under it (owner,
 * 2026-11-24); a member without settings rights goes straight to the app.
 */
final class Menu {

	public const SLUG = 'novawork';

	/**
	 * The brand mark (Phosphor flag-banner-fold, fill) as a black SVG. WordPress
	 * only recognises a base64 data URI as a menu SVG, and recolours it to the
	 * admin colour scheme. Source: assets/src/icons/fill/flag-banner-fold.svg.
	 */
	private const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNTYgMjU2Ij48cGF0aCBmaWxsPSJibGFjayIgZD0iTTEzMS43OSw2OS42NWwtNDMuNjMsOTZBNCw0LDAsMCwxLDg0LjUyLDE2OEgyOC4yM2E4LjIsOC4yLDAsMCwxLTYuNTgtMy4xMyw4LDgsMCwwLDEsLjQzLTEwLjI1TDU3LjE5LDExNiwyMi4wOCw3Ny4zOGE4LDgsMCwwLDEtLjQzLTEwLjI2QTguMjIsOC4yMiwwLDAsMSwyOC4yMyw2NGg5OS45MkE0LDQsMCwwLDEsMTMxLjc5LDY5LjY1Wk0yMzcuNTYsNDIuMjRBOC4zLDguMywwLDAsMCwyMzEuNzcsNDBIMTY4YTgsOCwwLDAsMC03LjI4LDQuNjlsLTQyLjU3LDkzLjY1YTQsNCwwLDAsMCwzLjY0LDUuNjZoNTcuNzlsLTM0Ljg2LDc2LjY5YTgsOCwwLDEsMCwxNC41Niw2LjYybDgwLTE3NkE4LDgsMCwwLDAsMjM3LjU2LDQyLjI0WiIvPjwvc3ZnPg==';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add' ) );
		add_action( 'admin_menu', array( self::class, 'add_open' ), 30 );
	}

	/**
	 * The top-level item: Settings for administrators, the app for members.
	 */
	public static function add(): void {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			add_menu_page( __( 'NovaWork settings', 'novawork' ), __( 'NovaWork', 'novawork' ), Capabilities::MANAGE, SettingsPage::SLUG, array( SettingsPage::class, 'render' ), self::ICON );
			return;
		}
		$hook = add_menu_page( __( 'NovaWork', 'novawork' ), __( 'NovaWork', 'novawork' ), Capabilities::ACCESS, self::SLUG, '__return_null', self::ICON );
		add_action( 'load-' . $hook, array( self::class, 'open_app' ) );
	}

	/**
	 * "Visit Workspace", after Settings in an administrator's menu.
	 */
	public static function add_open(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$hook = add_submenu_page( SettingsPage::SLUG, __( 'Visit Workspace', 'novawork' ), __( 'Visit Workspace', 'novawork' ), Capabilities::ACCESS, self::SLUG, '__return_null' );
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( self::class, 'open_app' ) );
		}
	}

	/**
	 * The menu item goes straight to the app.
	 */
	public static function open_app(): void {
		wp_safe_redirect( Route::url() );
		exit;
	}
}
