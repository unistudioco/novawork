<?php
/**
 * The members' sign-in page at {workspace}/login.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\App;

use NovaWork\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Owner mark: members sign in on a page in NovaWork's own look, with the
 * workspace's name, instead of wp-login.php.
 *
 * - It signs in with wp_signon(), so anything hooked to WordPress's own
 *   authentication (login limits, two-factor, captchas through `login_form`
 *   and `authenticate`) still applies. Rate limiting is left to them.
 * - The form carries a nonce. Wrong details get one message that never says
 *   which part was wrong.
 * - "Forgot password?" and "Set a new password" are NovaWork pages too
 *   (owner, 2026-11-22: ?action=lost and ?action=rp), built on WordPress's
 *   own reset (retrieve_password(), check_password_reset_key(),
 *   reset_password()). A member's reset email links to them. Neither page
 *   says whether an account exists.
 * - After signing in you land where you were heading (the app view in the
 *   address's #hash comes along); signing out comes back here.
 */
final class Login {

	private const NONCE = 'novawork_login';

	/**
	 * Show the page, or sign in from it.
	 */
	public static function handle(): void {
		nocache_headers();
		send_frame_options_header();
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which form to show; nothing is changed.
		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		// A sent form is read only once its nonce checks out (form()).
		$form = $post ? self::form() : null;
		if ( 'join' === $action ) {
			header( 'Referrer-Policy: no-referrer' ); // The invite is in the address.
			self::render( 'join', $post ? ( $form ? self::join( $form ) : self::expired() ) : self::check_invite(), $form );
			return;
		}
		if ( is_user_logged_in() ) {
			wp_safe_redirect( Route::url() );
			exit;
		}
		if ( 'lost' === $action ) {
			self::render( 'lost', $post ? ( $form ? self::lost( $form ) : self::expired() ) : null, $form );
			return;
		}
		if ( 'rp' === $action ) {
			header( 'Referrer-Policy: no-referrer' ); // The key is in the address.
			self::render( 'reset', $post ? ( $form ? self::reset( $form ) : self::expired() ) : self::check_key(), $form );
			return;
		}
		self::render( 'login', $post ? ( $form ? self::sign_in( $form ) : self::expired() ) : null, $form );
	}

	/**
	 * The sent form's fields, or null when its nonce is missing or stale (the
	 * form was open too long, or the request didn't come from it). No field
	 * is read before the nonce is verified.
	 *
	 * @return array<string, string>|null
	 */
	private static function form(): ?array {
		if ( ! isset( $_POST['_novawork_login'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_novawork_login'] ) ), self::NONCE ) ) {
			return null;
		}
		return array(
			'log'        => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '',
			// Passwords are used exactly as typed, as wp-login.php does.
			'pwd'        => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed.
			'pass1'      => isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed.
			'rememberme' => empty( $_POST['rememberme'] ) ? '' : '1',
			'user_login' => isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '',
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'hash'       => isset( $_POST['hash'] ) ? sanitize_key( wp_unslash( $_POST['hash'] ) ) : '',
			'key'        => isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '',
			'login'      => isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '',
		);
	}

	/**
	 * The answer to a form sent without a good nonce.
	 */
	private static function expired(): WP_Error {
		return new WP_Error( 'novawork_expired', __( 'This page was open for a while. Please try again.', 'novawork' ) );
	}

	/**
	 * A value from the address (an invite token, a reset key and login).
	 * These links are their own proof: the token or key is checked against
	 * the database before anything happens.
	 *
	 * @param string $name Query argument.
	 */
	private static function from_link( string $name ): string {
		return isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A link from an email; the token itself is verified.
	}

	/**
	 * The invite in the address, if it can still be used.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function invite(): ?array {
		$token = self::from_link( 'invite' );
		return '' === $token ? null : ( new \NovaWork\Data\InviteRepository() )->find_open( $token );
	}

	/**
	 * Before the join form: is the invite good, and is the visitor already a member?
	 */
	private static function check_invite(): ?WP_Error {
		if ( ! self::invite() ) {
			return new WP_Error( 'novawork_bad_invite', __( 'This invite has expired or was already used. Ask whoever invited you for a new one.', 'novawork' ) );
		}
		if ( is_user_logged_in() && \NovaWork\Access\Capabilities::is_member( get_current_user_id() ) ) {
			wp_safe_redirect( Route::url() );
			exit;
		}
		return null;
	}

	/**
	 * Join: a signed-in person accepts; a new person creates their account.
	 * Redirects to the workspace on success.
	 *
	 * @param array<string, string> $form The sent form (nonce verified).
	 */
	private static function join( array $form ): WP_Error {
		$invite = self::invite();
		if ( ! $invite ) {
			return new WP_Error( 'novawork_bad_invite', __( 'This invite has expired or was already used. Ask whoever invited you for a new one.', 'novawork' ) );
		}
		if ( is_user_logged_in() ) {
			\NovaWork\Services\Invites::accept( $invite, wp_get_current_user() );
			wp_safe_redirect( Route::url() );
			exit;
		}
		$user = \NovaWork\Services\Invites::join_new( $invite, $form['name'], $form['email'], $form['pass1'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		/** This action is documented in wp-includes/user.php. */
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, fired on purpose.
		wp_safe_redirect( Route::url() );
		exit;
	}

	/**
	 * "Forgot password?": send WordPress's reset email. The answer is the same
	 * whether or not the account exists.
	 *
	 * @param array<string, string> $form The sent form (nonce verified).
	 */
	private static function lost( array $form ): WP_Error {
		$login = $form['user_login'];
		if ( '' === $login ) {
			return new WP_Error( 'novawork_empty', __( 'Enter your email or username.', 'novawork' ) );
		}
		$sent = retrieve_password( $login );
		if ( is_wp_error( $sent ) && ! array_intersect( array( 'invalid_email', 'invalidcombo', 'invalid_username', 'empty_username' ), $sent->get_error_codes() ) ) {
			// The site can't send mail, or another plugin refused.
			return new WP_Error( 'novawork_refused', wp_strip_all_tags( $sent->get_error_message() ) );
		}
		return new WP_Error( 'novawork_sent', __( 'If that matches an account, a link to set a new password is on its way. Check your email.', 'novawork' ) );
	}

	/**
	 * The reset link: is it still good?
	 */
	private static function check_key(): ?WP_Error {
		$user = self::key_user( self::from_link( 'key' ), sanitize_user( self::from_link( 'login' ) ) );
		return is_wp_error( $user ) ? $user : null;
	}

	/**
	 * The user a reset link belongs to, or why it can't be used.
	 *
	 * @param string $key   Reset key.
	 * @param string $login Username.
	 * @return \WP_User|WP_Error
	 */
	private static function key_user( string $key, string $login ): \WP_User|WP_Error {
		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			return new WP_Error( 'novawork_bad_key', __( 'This link has expired or was already used. Ask for a new one.', 'novawork' ) );
		}
		return $user;
	}

	/**
	 * "Set a new password": save it with WordPress's reset_password().
	 *
	 * @param array<string, string> $form The sent form (nonce verified).
	 */
	private static function reset( array $form ): WP_Error {
		$user = self::key_user( $form['key'], $form['login'] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$password = $form['pass1'];
		$errors   = new WP_Error();
		if ( '' === trim( $password ) ) {
			$errors->add( 'novawork_empty', __( 'Enter a new password.', 'novawork' ) );
		}
		/** This action is documented in wp-login.php (password policy plugins hook here). */
		do_action( 'validate_password_reset', $errors, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, fired on purpose.
		if ( $errors->has_errors() ) {
			return new WP_Error( 'novawork_weak', wp_strip_all_tags( $errors->get_error_message() ) );
		}
		reset_password( $user, $password );
		wp_safe_redirect( add_query_arg( 'reset', '1', Route::login_url() ) );
		exit;
	}

	/**
	 * A member's reset email links to the NovaWork page instead of
	 * wp-login.php (anyone else keeps WordPress's link).
	 *
	 * @param array<string, mixed> $email      Email parts (to, subject, message, headers).
	 * @param string               $key        Reset key.
	 * @param string               $user_login Username.
	 * @param \WP_User             $user       The user.
	 * @return array<string, mixed>
	 */
	public static function reset_email( array $email, string $key, string $user_login, \WP_User $user ): array {
		if ( ! user_can( $user, \NovaWork\Access\Capabilities::ACCESS ) ) {
			return $email;
		}
		$ours             = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => $key,
				'login'  => rawurlencode( $user_login ),
			),
			Route::login_url()
		);
		$email['message'] = (string) preg_replace( '#\S*wp-login\.php\?action=rp\S*#', $ours, (string) $email['message'] );
		return $email;
	}

	/**
	 * Sign in with the sent details; redirects and exits on success.
	 *
	 * @param array<string, string> $form The sent form (nonce verified).
	 */
	private static function sign_in( array $form ): WP_Error {
		$login    = $form['log'];
		$password = $form['pwd'];
		if ( '' === $login || '' === $password ) {
			return new WP_Error( 'novawork_empty', __( 'Enter your email or username and your password.', 'novawork' ) );
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => '' !== $form['rememberme'],
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			// Don't say whether the account exists or the password was wrong.
			$quiet = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'empty_username', 'empty_password', 'authentication_failed' );
			if ( array_intersect( $quiet, $user->get_error_codes() ) ) {
				return new WP_Error( 'novawork_wrong', __( 'That email or username and password don’t match. Try again, or reset your password.', 'novawork' ) );
			}
			// Another plugin's answer (too many attempts, a locked account…).
			return new WP_Error( 'novawork_refused', wp_strip_all_tags( $user->get_error_message() ) );
		}
		// Signed in from an invite ("Already have an account?"): join now.
		$invite = self::invite();
		if ( $invite ) {
			\NovaWork\Services\Invites::accept( $invite, $user );
		}
		$hash = $form['hash'];
		wp_safe_redirect( Route::url() . ( '' !== $hash ? '#' . $hash : '' ) );
		exit;
	}

	/**
	 * Print the page.
	 *
	 * @param string                     $mode  "login", "lost" or "reset".
	 * @param WP_Error|null              $error What went wrong (or, for "lost", the answer).
	 * @param array<string, string>|null $form  The sent form (nonce verified), to fill the fields again.
	 */
	private static function render( string $mode, ?WP_Error $error, ?array $form = null ): void {
		Assets::register();
		$done = $error && 'novawork_sent' === $error->get_error_code();
		$view = array(
			'mode'      => $mode,
			'name'      => Settings::workspace_name(),
			'error'     => $error && ! $done ? $error->get_error_message() : '',
			'sent'      => $done ? $error->get_error_message() : '',
			'badKey'    => $error && 'novawork_bad_key' === $error->get_error_code(),
			'badInvite' => $error && 'novawork_bad_invite' === $error->get_error_code(),
			'key'       => $form ? $form['key'] : self::from_link( 'key' ),
			'user'      => $form ? $form['login'] : sanitize_user( self::from_link( 'login' ) ),
			'wasReset'  => '' !== self::from_link( 'reset' ),
			'loginUrl'  => Route::login_url(),
			'invite'    => self::invite(),
			'token'     => self::from_link( 'invite' ),
			'me'        => is_user_logged_in() ? wp_get_current_user()->display_name : '',
			'joinName'  => $form ? $form['name'] : '',
			'joinEmail' => $form ? $form['email'] : '',
			'login'     => $form ? $form['log'] : '',
			'remember'  => $form && '' !== $form['rememberme'],
			'signedOut' => '' !== self::from_link( 'signed-out' ),
			'nonce'     => self::NONCE,
			'lost'      => add_query_arg( 'action', 'lost', Route::login_url() ),
		);
		if ( $error && 'novawork_refused' === $error->get_error_code() ) {
			status_header( 403 );
		}
		require NOVAWORK_PATH . 'views/login.php';
	}
}
