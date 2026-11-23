<?php
/**
 * The app's own URL.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

use NovaWork\Settings;

defined( 'ABSPATH' ) || exit;

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
	 * Bump when the rules below change, so existing sites rebuild them once.
	 */
	private const RULES_VERSION = 3;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'retrieve_password_notification_email', array( Login::class, 'reset_email' ), 10, 4 );
		// Owner decision: a site that shows only the workspace.
		add_action( 'template_redirect', array( self::class, 'only_workspace' ), 1 );
		add_filter( 'wp_sitemaps_enabled', array( self::class, 'sitemaps' ) );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), 99 );
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
		// The members' sign-in page: {workspace}/login.
		add_rewrite_rule( '^' . ( 'home' === Settings::location() ? '' : preg_quote( $current, '/' ) . '/' ) . 'login/?$', 'index.php?' . self::QUERY_VAR . '=login', 'top' );
		// The sign-in page under an earlier address (or the path, in home mode) forwards.
		$elsewhere = 'home' === Settings::location() ? array_merge( array( $current ), self::old_slugs() ) : array_diff( self::old_slugs(), array( $current ) );
		foreach ( array_unique( $elsewhere ) as $old ) {
			add_rewrite_rule( '^' . preg_quote( $old, '/' ) . '/login/?$', 'index.php?' . self::QUERY_VAR . '=go-login', 'top' );
		}
		foreach ( self::old_slugs() as $old ) {
			if ( $old !== $current ) {
				add_rewrite_rule( '^' . preg_quote( $old, '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=go', 'top' );
			}
		}
		if ( (int) get_option( 'novawork_rewrite_version' ) < self::RULES_VERSION ) {
			update_option( 'novawork_rewrite_version', self::RULES_VERSION );
			update_option( self::FLUSH_OPTION, 1 );
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
		if ( 'manifest' === $var || 'app-worker' === $var || 0 === strpos( $var, 'icon-' ) ) {
			// The installable app's files (1.1.0), for anyone: browsers fetch them without cookies.
			WebApp::serve( $var );
			exit;
		}
		if ( 'go-login' === $var ) {
			// Keep what the link carries (an invite, a reset key, a note).
			$keep = array();
			foreach ( array( 'action', 'invite', 'key', 'login', 'signed-out', 'reset' ) as $arg ) {
				if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Passed on unchanged.
					$keep[ $arg ] = rawurlencode( sanitize_text_field( wp_unslash( $_GET[ $arg ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Passed on unchanged.
				}
			}
			wp_safe_redirect( add_query_arg( $keep, self::login_url() ), 301 );
			exit;
		}
		if ( 'go' === $var ) {
			// An earlier address (or the path while the workspace is the home page).
			wp_safe_redirect( self::url(), 301 );
			exit;
		}
		if ( 'login' === $var ) {
			Login::handle();
			exit;
		}
		if ( '' === $var && ! self::is_home_request() ) {
			return;
		}
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			// Owner mark: the workspace's own sign-in page, not wp-login.php.
			wp_safe_redirect( self::login_url() );
			exit;
		}
		// The workspace speaks the member's language (Profile → Language), as
		// wp-admin does; WordPress uses the site's for front-end pages.
		switch_to_user_locale( get_current_user_id() );
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
	 * The members' sign-in page.
	 *
	 * @param bool $signed_out Add the "You've signed out" note.
	 */
	public static function login_url( bool $signed_out = false ): string {
		if ( get_option( 'permalink_structure' ) ) {
			$url = trailingslashit( self::url() ) . user_trailingslashit( 'login' );
		} else {
			$url = add_query_arg( self::QUERY_VAR, 'login', home_url( '/' ) );
		}
		return $signed_out ? add_query_arg( 'signed-out', '1', $url ) : $url;
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

	/**
	 * "Show only the workspace" (setting `workspace_only`): every other public
	 * page (posts, pages, archives, search, 404s, feeds) goes to the
	 * workspace, which sends signed-out visitors to its sign-in page. wp-admin,
	 * the REST API, admin-ajax, cron and wp-login.php never come through here.
	 */
	public static function only_workspace(): void {
		if ( ! Settings::get( 'workspace_only' ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_robots() || is_favicon() || is_customize_preview() ) {
			return;
		}
		wp_safe_redirect( self::url(), 302 );
		exit;
	}

	/**
	 * No sitemaps while the site shows only the workspace.
	 *
	 * @param bool $enabled Whether WordPress's sitemaps are on.
	 */
	public static function sitemaps( bool $enabled ): bool {
		return Settings::get( 'workspace_only' ) ? false : $enabled;
	}

	/**
	 * Ask search engines to stay out while the site shows only the workspace.
	 *
	 * @param string $output robots.txt as WordPress would send it.
	 */
	public static function robots_txt( string $output ): string {
		return Settings::get( 'workspace_only' ) ? "User-agent: *\nDisallow: /\n" : $output;
	}
}
