<?php
/**
 * The workspace's own branding: icon, logo and accent colour.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

use NovaWork\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Owner decisions (2026-11-22):
 *
 * - An icon (square, beside the name and in the browser tab) and an optional
 *   full logo (replaces icon + name), with an optional dark-mode logo.
 *   Without an icon the NovaWork flag stays.
 * - One accent colour. Every accent token the design uses is derived from it
 *   for light and dark, keeping text in the accent readable (WCAG 4.5:1).
 *   Text on the accent (buttons, badges) follows the `accent_text` setting:
 *   auto prefers white whenever it reaches 3:1 (orange, red, teal…), dark
 *   otherwise; white or dark force it. The default blue with auto prints
 *   nothing, so the approved prototype's values stay exact.
 */
final class Branding {

	public const DEFAULT_ACCENT = '#2065f5';

	/**
	 * Preset accents for the settings screen.
	 */
	public const PRESETS = array( '#2065f5', '#4f46e5', '#7c3aed', '#c026d3', '#db2777', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0d9488', '#0891b2', '#475569' );

	/* Surfaces the derived colours must read against (tokens.css). */
	private const LIGHT_SURFACE = '#ffffff';
	private const DARK_SURFACE  = '#242321';
	private const INK           = '#1d1919';

	/**
	 * A colour as #rrggbb, from #rgb, #rrggbb or rgb(r, g, b); null when it
	 * isn't one.
	 *
	 * @param string $value Colour as typed.
	 */
	public static function normalize( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( preg_match( '/^#?([0-9a-f]{3})$/', $value, $m ) ) {
			$h = $m[1];
			return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		if ( preg_match( '/^#?([0-9a-f]{6})$/', $value, $m ) ) {
			return '#' . $m[1];
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*(?:[,\/]\s*[\d.]+%?\s*)?\)$/', $value, $m ) ) {
			$rgb = array( (int) $m[1], (int) $m[2], (int) $m[3] );
			if ( max( $rgb ) > 255 ) {
				return null;
			}
			return self::hex( $rgb );
		}
		return null;
	}

	/**
	 * The accent setting, always a valid colour.
	 */
	public static function accent(): string {
		return self::normalize( (string) Settings::get( 'accent' ) ) ?? self::DEFAULT_ACCENT;
	}

	/**
	 * CSS that re-points the accent tokens, or '' for the default blue.
	 */
	public static function accent_css(): string {
		$c = self::accent();
		if ( self::DEFAULT_ACCENT === $c && 'dark' !== Settings::get( 'accent_text' ) ) {
			return ''; // White on the default blue already.
		}
		$light = self::tokens( $c, false );
		$dark  = self::tokens( $c, true );
		$decl  = static function ( array $tokens ): string {
			$out = '';
			foreach ( $tokens as $name => $value ) {
				$out .= '--nw-' . $name . ':' . $value . ';';
			}
			return $out;
		};
		// The same three selectors as tokens.css, printed after it.
		return ':root{' . $decl( $light ) . '}'
			. '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $decl( $dark ) . '}}'
			. ':root[data-theme="dark"]{' . $decl( $dark ) . '}';
	}

	/**
	 * Every accent token for one appearance.
	 *
	 * @param string $c    Accent (#rrggbb).
	 * @param bool   $dark Dark appearance.
	 * @return array<string, string>
	 */
	public static function tokens( string $c, bool $dark ): array {
		$surface = $dark ? self::DARK_SURFACE : self::LIGHT_SURFACE;
		$fg      = self::on_accent( $c );
		// Text in the accent colour: pushed darker (light) or lighter (dark)
		// until it reads on the surface.
		$ink = self::readable( $c, $surface, $dark ? '#ffffff' : '#000000' );
		if ( $dark ) {
			$soft    = $c;
			$on_soft = '#ffffff' === $fg ? self::mix( $c, '#ffffff', 0.92 ) : self::INK;
			$line    = self::mix( $c, '#ffffff', 0.2 );
		} else {
			$soft    = self::mix( $c, '#ffffff', 0.885 );
			$on_soft = self::readable( $c, $soft, '#000000' );
			$line    = self::mix( $c, '#ffffff', 0.65 );
		}
		return array(
			'accent'         => $c,
			'accent-fg'      => $fg,
			'accent-ink'     => $ink,
			'accent-soft'    => $soft,
			'accent-on-soft' => $on_soft,
			'accent-line'    => $line,
		);
	}

	/**
	 * Text on the accent: the setting, or (auto) white when it reaches 3:1,
	 * the large-text and icon level, otherwise the app's near-black.
	 *
	 * @param string $c Accent.
	 */
	public static function on_accent( string $c ): string {
		$rule = (string) Settings::get( 'accent_text' );
		if ( 'white' === $rule ) {
			return '#ffffff';
		}
		if ( 'dark' === $rule ) {
			return self::INK;
		}
		return self::contrast( '#ffffff', $c ) >= 3 ? '#ffffff' : self::INK;
	}

	/**
	 * A colour moved toward $toward until it has 4.5:1 contrast on $on.
	 *
	 * @param string $c      Colour.
	 * @param string $on     Background.
	 * @param string $toward Black or white.
	 */
	private static function readable( string $c, string $on, string $toward ): string {
		for ( $t = 0.0; $t <= 1.0; $t += 0.05 ) {
			$try = self::mix( $c, $toward, $t );
			if ( self::contrast( $try, $on ) >= 4.5 ) {
				return $try;
			}
		}
		return $toward;
	}

	/**
	 * Mix two colours: $t of the way from $a to $b.
	 *
	 * @param string $a From.
	 * @param string $b To.
	 * @param float  $t 0..1.
	 */
	public static function mix( string $a, string $b, float $t ): string {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		return self::hex(
			array(
				(int) round( $x[0] + ( $y[0] - $x[0] ) * $t ),
				(int) round( $x[1] + ( $y[1] - $x[1] ) * $t ),
				(int) round( $x[2] + ( $y[2] - $x[2] ) * $t ),
			)
		);
	}

	/**
	 * WCAG contrast ratio between two colours.
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Relative luminance.
	 *
	 * @param string $c Colour.
	 */
	private static function luminance( string $c ): float {
		$out = 0.0;
		foreach ( self::rgb( $c ) as $i => $v ) {
			$s    = $v / 255;
			$lin  = $s <= 0.03928 ? $s / 12.92 : ( ( $s + 0.055 ) / 1.055 ) ** 2.4;
			$out += array( 0.2126, 0.7152, 0.0722 )[ $i ] * $lin;
		}
		return $out;
	}

	/**
	 * Hex to [r, g, b].
	 *
	 * @param string $c #rrggbb.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function rgb( string $c ): array {
		$h = ltrim( $c, '#' );
		return array( (int) hexdec( substr( $h, 0, 2 ) ), (int) hexdec( substr( $h, 2, 2 ) ), (int) hexdec( substr( $h, 4, 2 ) ) );
	}

	/**
	 * [r, g, b] to #rrggbb.
	 *
	 * @param array<int, int> $rgb Channels.
	 */
	private static function hex( array $rgb ): string {
		return sprintf( '#%02x%02x%02x', max( 0, min( 255, $rgb[0] ) ), max( 0, min( 255, $rgb[1] ) ), max( 0, min( 255, $rgb[2] ) ) );
	}

	/**
	 * An uploaded image's address, or '' when unset or not an image.
	 *
	 * @param string $key  Setting: brand_icon, brand_logo or brand_logo_dark.
	 * @param string $size Image size.
	 */
	public static function image( string $key, string $size = 'full' ): string {
		$id = (int) Settings::get( $key );
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return '';
		}
		return (string) wp_get_attachment_image_url( $id, $size );
	}

	/**
	 * The header's brand: the full logo, or the icon (or the NovaWork flag)
	 * beside the workspace's name.
	 *
	 * @param int $size Icon size in px.
	 */
	public static function mark_html( int $size = 24 ): string {
		$name = Settings::workspace_name();
		$logo = self::image( 'brand_logo' );
		if ( '' !== $logo ) {
			$dark = self::image( 'brand_logo_dark' );
			$html = '<img class="nw-brand-logo' . ( '' !== $dark ? ' nw-only-light' : '' ) . '" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '">';
			if ( '' !== $dark ) {
				$html .= '<img class="nw-brand-logo nw-only-dark" src="' . esc_url( $dark ) . '" alt="' . esc_attr( $name ) . '">';
			}
			return $html;
		}
		$icon = self::image( 'brand_icon', 'thumbnail' );
		$mark = '' !== $icon
			? '<span class="nw-mark nw-mark-img"><img src="' . esc_url( $icon ) . '" alt="" width="' . $size . '" height="' . $size . '"></span>'
			: '<span class="nw-mark">' . self::flag( $size ) . '</span>';
		return $mark . esc_html( $name );
	}

	/**
	 * The NovaWork flag (Phosphor flag-banner-fold, fill), used until an icon
	 * is uploaded.
	 *
	 * @param int $size Size in px.
	 */
	public static function flag( int $size ): string {
		return '<svg class="nw-ic" style="--nw-is:' . $size . 'px" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M131.79,69.65l-43.63,96A4,4,0,0,1,84.52,168H28.23a8.2,8.2,0,0,1-6.58-3.13,8,8,0,0,1,.43-10.25L57.19,116,22.08,77.38a8,8,0,0,1-.43-10.26A8.22,8.22,0,0,1,28.23,64h99.92A4,4,0,0,1,131.79,69.65ZM237.56,42.24A8.3,8.3,0,0,0,231.77,40H168a8,8,0,0,0-7.28,4.69l-42.57,93.65a4,4,0,0,0,3.64,5.66h57.79l-34.86,76.69a8,8,0,1,0,14.56,6.62l80-176A8,8,0,0,0,237.56,42.24Z"/></svg>';
	}

	/**
	 * Print the browser tab icon in a NovaWork document's head. The accent
	 * CSS goes in through wp_add_inline_style() (Assets::head()).
	 */
	public static function icon_link(): void {
		$icon = self::image( 'brand_icon', 'thumbnail' );
		if ( '' === $icon ) {
			$icon = (string) get_site_icon_url( 64 );
		}
		if ( '' !== $icon ) {
			echo '<link rel="icon" href="' . esc_url( $icon ) . '">' . "\n";
		}
	}
}
