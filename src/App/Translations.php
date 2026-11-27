<?php
/**
 * Translations for the app's JavaScript.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress finds a script's translations by its file path:
 * `novawork-{locale}-{md5( path )}.json`, one file per source file the
 * strings were found in (translate.wordpress.org language packs and
 * `wp i18n make-json` both write them that way).
 *
 * The app ships as bundles whose names carry a content hash and change with
 * every build, and each bundle is made of many source files, so core's lookup
 * finds nothing. The build writes assets/dist/i18n.json, listing the source
 * files inside each bundle; this class loads and merges their translations.
 *
 * Assets::register_bundle() adds each bundle here, NovaWork's and add-ons'
 * alike (1.3.0): its text domain and its plugin's folder.
 */
final class Translations {

	/**
	 * Script handle => its bundle name in i18n.json, text domain and plugin folder.
	 *
	 * @var array<string, array{bundle: string, domain: string, path: string}>
	 */
	private static array $bundles = array();

	/**
	 * Merged translations per handle, false when there are none.
	 *
	 * @var array<string, string|false>
	 */
	private static array $cache = array();

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_filter( 'pre_load_script_translations', array( self::class, 'load' ), 10, 4 );
	}

	/**
	 * Load a bundle's translations from its sources (Assets::register_bundle()).
	 *
	 * @param string $handle Script handle.
	 * @param string $bundle Bundle name in the plugin's assets/dist/i18n.json.
	 * @param string $domain Text domain.
	 * @param string $path   The plugin's folder, with a trailing slash.
	 */
	public static function add( string $handle, string $bundle, string $domain, string $path ): void {
		self::$bundles[ $handle ] = array(
			'bundle' => $bundle,
			'domain' => $domain,
			'path'   => $path,
		);
		unset( self::$cache[ $handle ] );
	}

	/**
	 * Hand WordPress the merged translations for a registered bundle.
	 *
	 * @param string|false|null $translations Translations so far (null: not loaded yet).
	 * @param string|false      $file         File WordPress is about to read.
	 * @param string            $handle       Script handle.
	 * @param string            $domain       Text domain.
	 * @return string|false|null
	 */
	public static function load( $translations, $file, $handle, $domain ) {
		unset( $file );
		$bundle = self::$bundles[ $handle ] ?? null;
		if ( null !== $translations || null === $bundle || $bundle['domain'] !== $domain ) {
			return $translations;
		}
		if ( ! isset( self::$cache[ $handle ] ) ) {
			self::$cache[ $handle ] = self::merge( self::sources( $bundle['bundle'], $bundle['path'] ), determine_locale(), $domain, $bundle['path'] );
		}
		// Null lets WordPress carry on with its own lookup when there is nothing.
		return false === self::$cache[ $handle ] ? null : self::$cache[ $handle ];
	}

	/**
	 * Source files inside a bundle, relative to the plugin folder.
	 *
	 * @param string $bundle Bundle name.
	 * @param string $path   The plugin's folder (NovaWork's by default).
	 * @return list<string>
	 */
	public static function sources( string $bundle, string $path = NOVAWORK_PATH ): array {
		$file = trailingslashit( $path ) . 'assets/dist/i18n.json';
		$map  = is_readable( $file ) ? wp_json_file_decode( $file, array( 'associative' => true ) ) : null;
		if ( ! is_array( $map ) || ! isset( $map[ $bundle ] ) || ! is_array( $map[ $bundle ] ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $map[ $bundle ] ) ) );
	}

	/**
	 * Merge every translation file for the given sources into one Jed JSON.
	 *
	 * Files bundled in the plugin's languages folder load first; language
	 * packs (wp-content/languages/plugins) win over them, since they are
	 * newer.
	 *
	 * @param array<int, string> $sources Source paths.
	 * @param string             $locale  Locale.
	 * @param string             $domain  Text domain (the files' prefix).
	 * @param string             $path    The plugin's folder (NovaWork's by default).
	 * @return string|false
	 */
	public static function merge( array $sources, string $locale, string $domain = 'novawork', string $path = NOVAWORK_PATH ): string|false {
		$dirs   = array( trailingslashit( $path ) . 'languages', WP_LANG_DIR . '/plugins' );
		$base   = null;
		$merged = array();
		foreach ( $dirs as $dir ) {
			foreach ( $sources as $source ) {
				$file = $dir . '/' . $domain . '-' . $locale . '-' . md5( $source ) . '.json';
				if ( ! is_readable( $file ) ) {
					continue;
				}
				$json     = wp_json_file_decode( $file, array( 'associative' => true ) );
				$messages = is_array( $json ) ? ( $json['locale_data']['messages'] ?? null ) : null;
				if ( ! is_array( $messages ) ) {
					continue;
				}
				$base ??= $json;
				unset( $messages[''] );
				$merged = array_merge( $merged, $messages );
			}
		}
		if ( null === $base ) {
			return false;
		}
		$base['locale_data']['messages'] = array( '' => $base['locale_data']['messages'][''] ?? array( 'domain' => 'messages' ) ) + $merged;
		return (string) wp_json_encode( $base );
	}
}
