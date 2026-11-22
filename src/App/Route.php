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
 *
 * Owner mark: the workspace can instead be the site's home page (setting
 * `location` = "home"), for a site that is only the workspace (such as
 * work.example.com). Then the theme's front page is replaced; wp-admin and
 * the REST API are untouched. Earlier addresses keep forwarding.
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
	 * Earlier app addresses, which forward to the current one.
	 */
	public const OLD_SLUGS_OPTION = 'novawork_old_slugs';

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
		$current = self::slug();
		add_rewrite_rule( '^' . preg_quote( $current, '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=' . ( 'home' === Settings::location() ? 'go' : '1' ), 'top' );
		foreach ( self::old_slugs() as $old ) {
			if ( $old !== $current ) {
				add_rewrite_rule( '^' . preg_quote( $old, '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=go', 'top' );
			}
		}
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
		if ( 'home' === Settings::location() ) {
			return trailingslashit( home_url( '/' ) );
		}
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( user_trailingslashit( self::slug() ) );
		}
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Serve the app when the query var is set.
	 */
	public static function maybe_render(): void {
		$var = (string) get_query_var( self::QUERY_VAR );
		if ( 'go' === $var ) {
			// An earlier address (or the path while the workspace is the home page).
			wp_safe_redirect( self::url(), 301 );
			exit;
		}
		if ( '' === $var && ! self::is_home_request() ) {
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

	/**
	 * Whether this request is the site's home page while the workspace lives
	 * there. Only the bare home address: posts, feeds and query links (?p=)
	 * still reach WordPress.
	 */
	private static function is_home_request(): bool {
		if ( 'home' !== Settings::location() || ! ( is_front_page() || is_home() ) || is_paged() ) {
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		return $path === $home && '' === (string) wp_parse_url( $uri, PHP_URL_QUERY );
	}

	/**
	 * Earlier addresses that forward to the current one.
	 *
	 * @return list<string>
	 */
	public static function old_slugs(): array {
		$list = get_option( self::OLD_SLUGS_OPTION, array() );
		return array_values( array_filter( array_map( 'sanitize_title', is_array( $list ) ? $list : array() ) ) );
	}

	/**
	 * Keep an address that is being replaced (the newest five).
	 *
	 * @param string $slug The address before the change.
	 */
	public static function remember_slug( string $slug ): void {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return;
		}
		$list = array_values( array_diff( self::old_slugs(), array( $slug ) ) );
		array_unshift( $list, $slug );
		update_option( self::OLD_SLUGS_OPTION, array_slice( $list, 0, 5 ), false );
	}
}
