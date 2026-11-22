<?php
/**
 * The app's scripts, styles and fonts.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

use NovaWork\Settings;

/**
 * Registers the Vite build with WordPress's script and style APIs, so the
 * app document prints them with wp_print_scripts() / wp_print_styles(), and
 * JS strings get their translations through wp_set_script_translations().
 *
 * During development, define NOVAWORK_DEV_SERVER (e.g. 'http://localhost:5175')
 * in wp-config.php and run `npm run dev` to load from Vite with hot reload.
 */
final class Assets {

	public const SCRIPT = 'novawork-app';
	public const STYLE  = 'novawork-app';
	public const FONTS  = 'novawork-fonts';

	/**
	 * The settings screen's bundle (wp-admin → NovaWork → Settings).
	 */
	public const ADMIN_SCRIPT = 'novawork-admin';
	public const ADMIN_STYLE  = 'novawork-admin';

	/**
	 * Satoshi and Supreme from Fontshare's own CDN. The ITF Free Font Licence
	 * forbids redistributing the files, so they are never bundled. Disclosed
	 * in readme.txt under "External services"; off in Settings means nothing
	 * remote loads and the system font stack is used.
	 */
	public const FONTS_URL = 'https://api.fontshare.com/v2/css?f%5B%5D=satoshi@400,500,700&f%5B%5D=supreme@400,500,700,800&display=swap';

	/**
	 * Register everything the app document prints.
	 */
	public static function register(): void {
		$deps = array( 'wp-i18n' );
		$dev  = self::dev_server();

		if ( '' !== $dev ) {
			wp_register_script( 'novawork-vite', $dev . '/@vite/client', array(), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Dev server only.
			wp_register_script( self::SCRIPT, $dev . '/js/app.js', array_merge( $deps, array( 'novawork-vite' ) ), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Dev server only.
			wp_register_style( self::STYLE, false, array(), NOVAWORK_VERSION );
		} else {
			$entry = self::manifest()['js/app.js'] ?? array();
			$base  = NOVAWORK_URL . 'assets/dist/';
			wp_register_script( self::SCRIPT, $base . ( $entry['file'] ?? 'missing.js' ), $deps, null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The file name carries a content hash.
			$css = (array) ( $entry['css'] ?? array() );
			wp_register_style( self::STYLE, $css ? $base . $css[0] : false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The file name carries a content hash.
		}

		wp_register_style( self::FONTS, self::FONTS_URL, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Fontshare versions its own files.
		wp_set_script_translations( self::SCRIPT, 'novawork', NOVAWORK_PATH . 'languages' );
		add_filter( 'script_loader_tag', array( self::class, 'as_module' ), 10, 2 );
	}

	/**
	 * Register the settings screen's bundle (from the dev server when
	 * NOVAWORK_DEV_SERVER is set).
	 */
	public static function register_admin(): void {
		$dev = self::dev_server();
		if ( '' !== $dev ) {
			wp_register_script( 'novawork-vite', $dev . '/@vite/client', array(), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Dev server only.
			wp_register_script( self::ADMIN_SCRIPT, $dev . '/js/settings.js', array( 'wp-i18n', 'novawork-vite' ), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Dev server only.
			wp_register_style( self::ADMIN_STYLE, false, array(), NOVAWORK_VERSION );
		} else {
			$entry = self::manifest()['js/settings.js'] ?? array();
			$base  = NOVAWORK_URL . 'assets/dist/';
			wp_register_script( self::ADMIN_SCRIPT, $base . ( $entry['file'] ?? 'missing.js' ), array( 'wp-i18n' ), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The file name carries a content hash.
			$css = (array) ( $entry['css'] ?? array() );
			wp_register_style( self::ADMIN_STYLE, $css ? $base . $css[0] : false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The file name carries a content hash.
		}
		wp_register_style( self::FONTS, self::FONTS_URL, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Fontshare versions its own files.
		wp_set_script_translations( self::ADMIN_SCRIPT, 'novawork', NOVAWORK_PATH . 'languages' );
		add_filter( 'script_loader_tag', array( self::class, 'as_module' ), 10, 2 );
	}

	/**
	 * Styles for the settings screen (fonts only when the setting is on).
	 *
	 * @return list<string>
	 */
	public static function admin_styles(): array {
		return Settings::get( 'load_fontshare' ) ? array( self::FONTS, self::ADMIN_STYLE ) : array( self::ADMIN_STYLE );
	}

	/**
	 * Styles to print in the head.
	 *
	 * @return list<string>
	 */
	public static function styles(): array {
		$handles = array( self::STYLE );
		if ( Settings::get( 'load_fontshare' ) ) {
			array_unshift( $handles, self::FONTS );
		}
		return $handles;
	}

	/**
	 * Vite builds ES modules.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 */
	public static function as_module( string $tag, string $handle ): string {
		if ( ! in_array( $handle, array( self::SCRIPT, self::ADMIN_SCRIPT, 'novawork-vite' ), true ) ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}

	/**
	 * The dev server URL, or '' when serving the build.
	 */
	private static function dev_server(): string {
		return defined( 'NOVAWORK_DEV_SERVER' ) ? untrailingslashit( (string) constant( 'NOVAWORK_DEV_SERVER' ) ) : '';
	}

	/**
	 * The Vite manifest.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function manifest(): array {
		$path = NOVAWORK_PATH . 'assets/dist/manifest.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$json = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file inside the plugin.
		return is_array( $json ) ? $json : array();
	}
}
