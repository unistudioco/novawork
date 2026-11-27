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
 *
 * register_bundle() does this for any plugin built the same way, NovaWork's
 * own bundles included (1.3.0).
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
	 * Tools for an add-on's page under the NovaWork menu (assets/src/js/admin-kit.js).
	 *
	 * @since 1.3.0
	 */
	public const ADMIN_KIT = 'novawork-admin-kit';

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
	 * Vite manifests already read, by path.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $manifests = array();

	/**
	 * Register everything the app document prints.
	 */
	public static function register(): void {
		self::register_bundle( self::SCRIPT, self::own( 'js/app.js' ) );
		wp_register_style( self::FONTS, self::FONTS_URL, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Fontshare versions its own files.
	}

	/**
	 * Register the settings screen's bundle and the add-on page kit (from the
	 * dev server when NOVAWORK_DEV_SERVER is set). Runs on every admin screen.
	 */
	public static function register_admin(): void {
		self::register_bundle( self::ADMIN_SCRIPT, self::own( 'js/settings.js' ) );
		self::register_bundle( self::ADMIN_KIT, self::own( 'js/admin-kit.js' ) );
		wp_register_style( self::FONTS, self::FONTS_URL, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Fontshare versions its own files.
	}

	/**
	 * Register a bundle built by Vite the way NovaWork's are: its script (an
	 * ES module) and its CSS under one handle, with the script's translations.
	 *
	 * The plugin keeps its Vite root in assets/src and builds to assets/dist
	 * with a manifest (build.manifest = 'manifest.json') and the i18n.json
	 * that NovaWork's vite-i18n-map.js plugin writes; translations live in its
	 * languages folder. Register on wp_enqueue_scripts, admin_enqueue_scripts
	 * or novawork_app_enqueue, then enqueue the handle.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $handle Handle for the script and its style.
	 * @param array<string, mixed> $args   {
	 *     The bundle.
	 *
	 *     @type string   $path   The plugin's folder, from plugin_dir_path().
	 *     @type string   $url    The plugin's URL, from plugin_dir_url().
	 *     @type string   $entry  The entry, relative to assets/src: 'js/app.js'.
	 *     @type string   $domain The text domain of the script's strings.
	 *     @type string[] $deps   Other scripts it needs (wp-i18n is always added).
	 *     @type string   $dev    A Vite dev server URL to load from instead of
	 *                            the build, '' (the default) for the build.
	 * }
	 */
	public static function register_bundle( string $handle, array $args ): void {
		$path   = trailingslashit( (string) ( $args['path'] ?? '' ) );
		$url    = trailingslashit( (string) ( $args['url'] ?? '' ) );
		$entry  = ltrim( (string) ( $args['entry'] ?? '' ), '/' );
		$domain = (string) ( $args['domain'] ?? '' );
		$dev    = untrailingslashit( (string) ( $args['dev'] ?? '' ) );
		$deps   = array_values( array_unique( array_merge( array( 'wp-i18n' ), array_map( 'strval', (array) ( $args['deps'] ?? array() ) ) ) ) );

		if ( '' !== $dev ) {
			$client = $handle . '-vite';
			wp_register_script( $client, $dev . '/@vite/client', array(), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Dev server only.
			wp_script_add_data( $client, 'novawork_module', true );
			wp_register_script( $handle, $dev . '/' . $entry, array_merge( $deps, array( $client ) ), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Dev server only.
			wp_register_style( $handle, false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- No file; inline styles can attach.
			$name = pathinfo( $entry, PATHINFO_FILENAME );
		} else {
			$built = self::manifest( $path )[ $entry ] ?? array();
			$base  = $url . 'assets/dist/';
			wp_register_script( $handle, $base . ( $built['file'] ?? 'missing.js' ), $deps, null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The file name carries a content hash.
			$css   = array_values( array_map( 'strval', (array) ( $built['css'] ?? array() ) ) );
			$extra = array();
			foreach ( array_slice( $css, 1 ) as $i => $file ) {
				$extra[] = $handle . '-' . ( $i + 1 );
				wp_register_style( end( $extra ), $base . $file, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The file name carries a content hash.
			}
			wp_register_style( $handle, $css ? $base . $css[0] : false, $extra, null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The file name carries a content hash.
			$name = (string) ( $built['name'] ?? pathinfo( $entry, PATHINFO_FILENAME ) );
		}
		wp_script_add_data( $handle, 'novawork_module', true );
		if ( '' !== $domain ) {
			Translations::add( $handle, $name, $domain, $path );
			wp_set_script_translations( $handle, $domain, $path . 'languages' );
		}
		if ( ! has_filter( 'script_loader_tag', array( self::class, 'as_module' ) ) ) {
			add_filter( 'script_loader_tag', array( self::class, 'as_module' ), 10, 2 );
		}
	}

	/**
	 * NovaWork's own bundle arguments.
	 *
	 * @param string $entry Entry, relative to assets/src.
	 * @return array<string, mixed>
	 */
	private static function own( string $entry ): array {
		return array(
			'path'   => NOVAWORK_PATH,
			'url'    => NOVAWORK_URL,
			'entry'  => $entry,
			'domain' => 'novawork',
			'dev'    => self::dev_server(),
		);
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
		if ( ! wp_scripts()->get_data( $handle, 'novawork_module' ) || str_contains( $tag, 'type="module"' ) ) {
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
	 * A plugin's Vite manifest (assets/dist/manifest.json), read once.
	 *
	 * @param string $path The plugin's folder, with a trailing slash.
	 * @return array<string, array<string, mixed>>
	 */
	private static function manifest( string $path ): array {
		if ( ! isset( self::$manifests[ $path ] ) ) {
			$file                     = $path . 'assets/dist/manifest.json';
			$json                     = is_readable( $file ) ? wp_json_file_decode( $file, array( 'associative' => true ) ) : null;
			self::$manifests[ $path ] = is_array( $json ) ? $json : array();
		}
		return self::$manifests[ $path ];
	}
}
