<?php
/**
 * The app's scripts, styles and fonts.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

use NovaWork\Settings;

defined( 'ABSPATH' ) || exit;

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
	 * Inline-only handles (no file): the saved light/dark choice, applied
	 * before anything paints, and the sign-in page's small helper.
	 */
	public const THEME = 'novawork-theme';
	public const LOGIN = 'novawork-login';

	/**
	 * Applies the appearance saved on this device before the page paints, so
	 * dark mode never flashes light.
	 */
	private const THEME_JS = 'try{var t=localStorage.getItem("nw.theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}';

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
	 * Script and style handles add-ons enqueued on novawork_app_enqueue.
	 *
	 * @var array{scripts: list<string>, styles: list<string>}
	 */
	private static array $addons = array(
		'scripts' => array(),
		'styles'  => array(),
	);

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
	 * Let add-ons enqueue their scripts and styles on the app document. Only
	 * what they enqueue here is printed: the document never prints the
	 * theme's or other plugins' front-end assets.
	 */
	public static function enqueue_addons(): void {
		$scripts = wp_scripts()->queue;
		$styles  = wp_styles()->queue;

		/**
		 * Fires while the workspace's page is built, for add-ons to enqueue
		 * their scripts and styles (wp_enqueue_script/style). They print after
		 * NovaWork's own, so they may depend on the "novawork-app" handles. A
		 * script built as an ES module adds
		 * wp_script_add_data( $handle, 'novawork_module', true ). Scripts
		 * reach the app through the "nw:ready" event.
		 *
		 * @since 1.3.0
		 */
		do_action( 'novawork_app_enqueue' );

		self::$addons = array(
			'scripts' => array_values( array_diff( wp_scripts()->queue, $scripts ) ),
			'styles'  => array_values( array_diff( wp_styles()->queue, $styles ) ),
		);
	}

	/**
	 * Styles to print in the head: NovaWork's, then add-ons'.
	 *
	 * @return list<string>
	 */
	public static function styles(): array {
		$handles = array( self::STYLE );
		if ( Settings::get( 'load_fontshare' ) ) {
			array_unshift( $handles, self::FONTS );
		}
		return array_merge( $handles, self::$addons['styles'] );
	}

	/**
	 * Scripts to print at the end of the app document: the app, then add-ons'.
	 *
	 * @return list<string>
	 */
	public static function scripts(): array {
		return array_merge( array( self::SCRIPT ), self::$addons['scripts'] );
	}

	/**
	 * The head of a NovaWork document (app, sign-in, no access): the saved
	 * appearance, the styles with the workspace's accent added inline, and
	 * the tab icon. Everything goes through WordPress's script and style APIs.
	 */
	public static function head(): void {
		wp_register_script( self::THEME, false, array(), NOVAWORK_VERSION, false );
		wp_add_inline_script( self::THEME, self::THEME_JS );
		$css = Branding::accent_css();
		if ( '' !== $css ) {
			wp_add_inline_style( self::STYLE, $css );
		}
		wp_print_scripts( array( self::THEME ) );
		wp_print_styles( self::styles() );
		Branding::icon_link();
	}

	/**
	 * Print an inline-only script at the end of a NovaWork document.
	 *
	 * @param string $handle Handle (self::LOGIN).
	 * @param string $js     The script.
	 */
	public static function footer_script( string $handle, string $js ): void {
		wp_register_script( $handle, false, array(), NOVAWORK_VERSION, true );
		wp_add_inline_script( $handle, $js );
		wp_print_scripts( array( $handle ) );
	}

	/**
	 * Vite builds ES modules.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 */
	public static function as_module( string $tag, string $handle ): string {
		$ours = in_array( $handle, array( self::SCRIPT, self::ADMIN_SCRIPT, 'novawork-vite' ), true );
		if ( ! $ours && ! wp_scripts()->get_data( $handle, 'novawork_module' ) ) {
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
