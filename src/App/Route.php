<?php
/**
 * The app's own URL.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

use NovaWork\Settings;

/**
 * NovaWork lives at its own logged-in URL (site.com/novawork/ by default) and
 * prints its own HTML document, the way wp-login.php does, so the active
 * theme's CSS never reaches it. Signed-out visitors go to the login screen
 * and come back afterwards.
 *
 * With plain permalinks the rewrite rule never matches, so ?novawork=1 always
 * works as well.
 */
final class Route {

	/**
	 * Query var that marks an app request.
	 */
	public const QUERY_VAR = 'novawork';

	/**
	 * Option set when the rewrite rules need rebuilding (activation, slug change).
	 */
	public const FLUSH_OPTION = 'novawork_flush_rewrite';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_render' ), 0 );
	}

	/**
	 * The slug from settings, cleaned.
	 */
	public static function slug(): string {
		$slug = sanitize_title( (string) Settings::get( 'app_slug' ) );
		return '' === $slug ? 'novawork' : $slug;
	}

	/**
	 * Map /{slug}/ to the app, and rebuild the rules once when asked to.
	 */
	public static function add_rewrite_rule(): void {
		add_rewrite_rule( '^' . preg_quote( self::slug(), '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Let WordPress read the app query var.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * The app's address.
	 */
	public static function url(): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( self::slug() ) );
		}
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Serve the app when the query var is set.
	 */
	public static function maybe_render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		Page::render();
		exit;
	}
}
