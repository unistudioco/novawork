<?php
/**
 * The installable app: manifest, icons and app worker.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

use NovaWork\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Owner request (1.1.0): members can install the workspace as an app on
 * their phone or computer, with its own icon and window. No app store and no
 * outside service: a web app manifest, icons and a small worker, all served
 * by this site.
 *
 * - The manifest (?novawork=manifest) names the workspace, opens it full
 *   window (`standalone`) and lists its icons. Browsers fetch it without
 *   cookies, so it holds only what the sign-in page already shows.
 * - The icons (?novawork=icon-…) are the workspace icon from Branding, or,
 *   without one, the header's mark: the accent as a rounded square with the
 *   NovaWork flag (assets/app-icons layers coloured with GD; the default blue
 *   files when the server has no GD).
 * - The app worker (?novawork=app-worker, assets/app-worker.js) only shows a
 *   short "You're offline" page when the workspace is opened without a
 *   connection. It caches nothing and lets every other request through.
 *
 * Setting `installable` (on). Turned off, the manifest is gone and the
 * worker removes itself.
 */
final class WebApp {

	/* The page colours (tokens.css --nw-bg), for the window and splash screen. */
	private const BG_LIGHT = '#f7f5f2';
	private const BG_DARK  = '#1a1918';

	/**
	 * Icon variants: purpose and size in px.
	 */
	private const ICONS = array(
		'any-192'   => 192,
		'any-512'   => 512,
		'mask-512'  => 512,
		'apple-180' => 180,
	);

	/**
	 * Whether members can install the workspace.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'installable' );
	}

	/**
	 * Address of one of the app's files.
	 *
	 * @param string $what manifest, app-worker or icon-{variant}.
	 */
	public static function url( string $what ): string {
		$args = array( Route::QUERY_VAR => $what );
		if ( 'app-worker' !== $what ) {
			// Changes with the name, accent and icon, so browsers fetch the new one.
			$args['ver'] = self::version();
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * What the app needs to register its worker ('' when turned off).
	 *
	 * @return array{worker: string, scope: string}
	 */
	public static function boot(): array {
		return array(
			'worker' => self::enabled() ? esc_url_raw( self::url( 'app-worker' ) ) : '',
			'scope'  => esc_url_raw( self::scope() ),
		);
	}

	/**
	 * The part of the site the installed app covers: the workspace and its
	 * sign-in page.
	 */
	public static function scope(): string {
		$url   = Route::url();
		$parts = wp_parse_url( $url );
		$base  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path  = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( isset( $parts['query'] ) ) {
			// Plain links (?novawork=1): the folder the site lives in.
			$path = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		}
		return $base . $path;
	}

	/**
	 * Print the manifest link and the home-screen tags in a NovaWork document.
	 */
	public static function head(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$name = Settings::workspace_name();
		echo '<link rel="manifest" href="' . esc_url( self::url( 'manifest' ) ) . '">' . "\n";
		// The window's title bar matches the page; the data-* let the
		// appearance switch (and the saved choice below) override the system's.
		$schemes = array(
			'light' => self::BG_LIGHT,
			'dark'  => self::BG_DARK,
		);
		foreach ( $schemes as $scheme => $colour ) {
			printf(
				'<meta name="theme-color" content="%1$s" media="(prefers-color-scheme: %2$s)" data-media="(prefers-color-scheme: %2$s)" data-light="%3$s" data-dark="%4$s">' . "\n",
				esc_attr( $colour ),
				esc_attr( $scheme ),
				esc_attr( self::BG_LIGHT ),
				esc_attr( self::BG_DARK )
			);
		}
		// The appearance saved on this device wins over the system's.
		wp_register_script( 'novawork-webapp', false, array(), NOVAWORK_VERSION, false );
		wp_add_inline_script( 'novawork-webapp', 'try{var t=document.documentElement.dataset.theme;if(t){document.querySelectorAll("meta[name=theme-color]").forEach(function(m){m.removeAttribute("media");m.content=m.dataset[t]})}}catch(e){}' );
		wp_print_scripts( array( 'novawork-webapp' ) );
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $name ) . '">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( self::url( 'icon-apple-180' ) ) . '">' . "\n";
	}

	/**
	 * Answer ?novawork=manifest, app-worker and icon-… requests.
	 *
	 * @param string $what The query var's value.
	 */
	public static function serve( string $what ): void {
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'app-worker' === $what ) {
			header( 'Content-Type: text/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			echo self::worker(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A script: our file plus JSON from wp_json_encode().
			return;
		}
		if ( ! self::enabled() ) {
			status_header( 404 );
			return;
		}
		// Immutable while the address carries the current version.
		$current = isset( $_GET['ver'] ) && self::version() === sanitize_key( wp_unslash( $_GET['ver'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A cache key, not a form.
		header( 'Cache-Control: public, max-age=' . ( $current ? '31536000, immutable' : '300' ) );
		if ( 'manifest' === $what ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
			return;
		}
		$variant = substr( $what, 5 );
		if ( 0 !== strpos( $what, 'icon-' ) || ! isset( self::ICONS[ $variant ] ) ) {
			status_header( 404 );
			return;
		}
		$png = self::icon( $variant );
		if ( null === $png ) {
			// No GD here: the default icon, or the uploaded one as it is.
			wp_safe_redirect( self::fallback_icon( $variant ), 302 );
			return;
		}
		header( 'Content-Type: image/png' );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PNG bytes.
	}

	/**
	 * The web app manifest.
	 *
	 * @return array<string, mixed>
	 */
	public static function manifest(): array {
		$name  = Settings::workspace_name();
		$icons = array(
			array(
				'src'     => self::url( 'icon-any-192' ),
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => self::url( 'icon-any-512' ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
		);
		if ( null === self::brand_id() ) {
			// Android crops icons to its own shape; only the flag has a safe-zone version.
			$icons[] = array(
				'src'     => self::url( 'icon-mask-512' ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			);
		}
		return array(
			'id'               => Route::url(),
			'name'             => $name,
			'short_name'       => $name,
			'description'      => __( 'Your team’s projects, tasks, decisions and chat.', 'novawork' ),
			'lang'             => str_replace( '_', '-', get_locale() ),
			'dir'              => is_rtl() ? 'rtl' : 'ltr',
			'start_url'        => Route::url(),
			'scope'            => self::scope(),
			'display'          => 'standalone',
			'background_color' => self::BG_LIGHT,
			'theme_color'      => self::BG_LIGHT,
			'icons'            => $icons,
		);
	}

	/**
	 * The app worker: assets/app-worker.js with the pages it covers and the
	 * offline page. Turned off, a worker that removes itself.
	 */
	public static function worker(): string {
		if ( ! self::enabled() ) {
			return "self.addEventListener('install',()=>self.skipWaiting());\nself.addEventListener('activate',(e)=>e.waitUntil(self.registration.unregister()));\n";
		}
		$conf = array(
			'pages'   => array( Route::url(), Route::login_url() ),
			'offline' => self::offline_page(),
		);
		$code = (string) file_get_contents( NOVAWORK_PATH . 'assets/app-worker.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file inside the plugin.
		return 'const NOVAWORK = ' . wp_json_encode( $conf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ";\n" . $code;
	}

	/**
	 * The page an installed app shows when it opens without a connection.
	 * The service worker answers with it (it is never a WordPress page), so
	 * it carries its look in style attributes (light-dark() follows the
	 * system) and checks again every 5 seconds; Try again checks now.
	 */
	private static function offline_page(): string {
		$name   = Settings::workspace_name();
		$accent = Branding::accent();
		$fg     = Branding::on_accent( $accent );
		$title  = __( 'You’re offline', 'novawork' );
		/* translators: %s: the workspace's name. */
		$text  = sprintf( __( '%s needs a connection. It opens again as soon as you’re back online.', 'novawork' ), $name );
		$retry = __( 'Try again', 'novawork' );
		$font  = 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
		$page  = 'margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box;color-scheme:light dark;'
			. 'background:light-dark(' . self::BG_LIGHT . ',' . self::BG_DARK . ');color:light-dark(#1d1919,#f2efec);font:15px/1.5 ' . $font;
		$mark  = 'width:44px;height:44px;margin:0 auto 16px;display:grid;place-items:center;border-radius:11px;background:' . $accent . ';color:' . $fg;
		$btn   = 'margin-top:12px;font:inherit;font-weight:600;border:0;border-radius:10px;padding:9px 18px;background:' . $accent . ';color:' . $fg . ';cursor:pointer';
		return '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '"' . ( is_rtl() ? ' dir="rtl"' : '' ) . '><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
			. '<meta http-equiv="refresh" content="5">'
			. '<title>' . esc_html( $name ) . '</title></head>'
			. '<body style="' . esc_attr( $page ) . '"><main style="max-width:340px;text-align:center"><div style="' . esc_attr( $mark ) . '">' . str_replace( 'class="nw-ic" style="--nw-is:26px" ', 'width="26" height="26" ', Branding::flag( 26 ) ) . '</div>'
			. '<h1 style="font-size:19px;margin:0 0 6px">' . esc_html( $title ) . '</h1><p style="margin:0 0 20px;color:light-dark(#6f6a66,#a49e99)">' . esc_html( $text ) . '</p>'
			. '<button type="button" style="' . esc_attr( $btn ) . '" onclick="location.reload()">' . esc_html( $retry ) . '</button></main></body></html>';
	}

	/**
	 * Changes whenever the icons or the manifest would.
	 */
	private static function version(): string {
		return substr( md5( NOVAWORK_VERSION . '|' . Settings::workspace_name() . '|' . Branding::accent() . '|' . (string) Settings::get( 'accent_text' ) . '|' . (string) self::brand_id() . '|' . Route::url() ), 0, 10 );
	}

	/**
	 * The uploaded workspace icon's attachment id, or null.
	 */
	private static function brand_id(): ?int {
		$id = (int) Settings::get( 'brand_icon' );
		return $id > 0 && wp_attachment_is_image( $id ) ? $id : null;
	}

	/**
	 * The default icon file, or the uploaded icon as uploaded.
	 *
	 * @param string $variant Icon variant.
	 */
	private static function fallback_icon( string $variant ): string {
		$id = self::brand_id();
		if ( null !== $id && 'mask-512' !== $variant ) {
			$size = self::ICONS[ $variant ];
			$url  = wp_get_attachment_image_url( $id, array( $size, $size ) );
			if ( $url ) {
				return $url;
			}
		}
		$file = array(
			'any-192'   => 'icon-192.png',
			'any-512'   => 'icon-512.png',
			'mask-512'  => 'icon-maskable-512.png',
			'apple-180' => 'icon-apple-180.png',
		)[ $variant ];
		return NOVAWORK_URL . 'assets/app-icons/' . $file;
	}

	/**
	 * One icon as PNG bytes, made with GD; null without GD or on failure.
	 *
	 * @param string $variant any-192, any-512, mask-512 or apple-180.
	 */
	public static function icon( string $variant ): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return null;
		}
		$size   = self::ICONS[ $variant ];
		$kind   = strtok( $variant, '-' );
		$canvas = imagecreatetruecolor( $size, $size );
		if ( false === $canvas ) {
			return null;
		}
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );
		imagefilledrectangle( $canvas, 0, 0, $size, $size, (int) imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
		imagealphablending( $canvas, true );

		$brand = self::brand_image();
		if ( null !== $brand ) {
			if ( 'apple' === $kind ) {
				// iOS shows transparency as black.
				self::fill( $canvas, '#ffffff' );
			}
			// The middle square of the uploaded image, edge to edge.
			$w    = imagesx( $brand );
			$h    = imagesy( $brand );
			$side = min( $w, $h );
			imagecopyresampled( $canvas, $brand, 0, 0, (int) ( ( $w - $side ) / 2 ), (int) ( ( $h - $side ) / 2 ), $size, $size, $side, $side );
		} else {
			$accent = Branding::accent();
			if ( 'any' === $kind ) {
				if ( ! self::layer( $canvas, 'shape.png', $accent, $size ) ) {
					return null;
				}
			} else {
				// Android and iOS shape these themselves: edge to edge.
				self::fill( $canvas, $accent );
			}
			// The maskable flag sits inside Android's safe circle.
			if ( ! self::layer( $canvas, 'flag.png', Branding::on_accent( $accent ), 'mask' === $kind ? (int) round( $size * 0.8 ) : $size ) ) {
				return null;
			}
		}
		ob_start();
		imagepng( $canvas, null, 9 );
		return (string) ob_get_clean();
	}

	/**
	 * The uploaded icon, opened with GD, or null.
	 */
	private static function brand_image(): ?\GdImage {
		$id = self::brand_id();
		if ( null === $id ) {
			return null;
		}
		$file = (string) get_attached_file( $id );
		if ( '' === $file || ! is_readable( $file ) || filesize( $file ) > 8 * MB_IN_BYTES ) {
			return null;
		}
		$image = imagecreatefromstring( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local upload.
		return false === $image ? null : $image;
	}

	/**
	 * Fill the canvas with a colour.
	 *
	 * @param \GdImage $canvas Canvas.
	 * @param string   $hex    #rrggbb.
	 */
	private static function fill( \GdImage $canvas, string $hex ): void {
		$rgb = sscanf( $hex, '#%02x%02x%02x' );
		imagefilledrectangle( $canvas, 0, 0, imagesx( $canvas ), imagesy( $canvas ), (int) imagecolorallocate( $canvas, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2] ) );
	}

	/**
	 * Draw one of the black layers in assets/app-icons in a colour, centred.
	 *
	 * @param \GdImage $canvas Canvas.
	 * @param string   $file   shape.png or flag.png.
	 * @param string   $hex    Colour.
	 * @param int      $box    Drawn size in px.
	 */
	private static function layer( \GdImage $canvas, string $file, string $hex, int $box ): bool {
		$layer = imagecreatefrompng( NOVAWORK_PATH . 'assets/app-icons/' . $file );
		if ( false === $layer ) {
			return false;
		}
		$rgb = sscanf( $hex, '#%02x%02x%02x' );
		// Black plus the colour is the colour; the edges keep their transparency.
		imagefilter( $layer, IMG_FILTER_COLORIZE, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2] );
		$at = (int) ( ( imagesx( $canvas ) - $box ) / 2 );
		imagecopyresampled( $canvas, $layer, $at, $at, 0, 0, $box, $box, imagesx( $layer ), imagesy( $layer ) );
		return true;
	}
}
