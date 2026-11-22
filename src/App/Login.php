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
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which form to show.
		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		if ( 'join' === $action ) {
			header( 'Referrer-Policy: no-referrer' ); // The invite is in the address.
			self::render( 'join', $post ? self::join() : self::check_invite() );
			return;
		}
		if ( is_user_logged_in() ) {
			wp_safe_redirect( Route::url() );
			exit;
		}
		if ( 'lost' === $action ) {
			self::render( 'lost', $post ? self::lost() : null );
			return;
		}
		if ( 'rp' === $action ) {
			header( 'Referrer-Policy: no-referrer' ); // The key is in the address.
			self::render( 'reset', $post ? self::reset() : self::check_key() );
			return;
		}
		self::render( 'login', $post ? self::sign_in() : null );
	}

	/**
	 * The invite in the address, if it can still be used.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function invite(): ?array {
		$token = isset( $_REQUEST['invite'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['invite'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The invite token is the proof.
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
	 */
	private static function join(): WP_Error {
		$invite = self::invite();
		if ( ! $invite ) {
			return new WP_Error( 'novawork_bad_invite', __( 'This invite has expired or was already used. Ask whoever invited you for a new one.', 'novawork' ) );
		}
		if ( ! self::fresh() ) {
			return new WP_Error( 'novawork_expired', __( 'This page was open for a while. Please try again.', 'novawork' ) );
		}
		if ( is_user_logged_in() ) {
			\NovaWork\Services\Invites::accept( $invite, wp_get_current_user() );
			wp_safe_redirect( Route::url() );
			exit;
		}
		$user = \NovaWork\Services\Invites::join_new(
			$invite,
			isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by fresh() above.
			isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by fresh() above.
			isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '' // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by fresh() above; a password is used as typed.
		);
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
	 * Whether the sent form carries a fresh nonce.
	 */
	private static function fresh(): bool {
		return isset( $_POST['_novawork_login'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_novawork_login'] ) ), self::NONCE );
	}

	/**
	 * "Forgot password?": send WordPress's reset email. The answer is the same
	 * whether or not the account exists.
	 */
	private static function lost(): WP_Error {
		if ( ! self::fresh() ) {
			return new WP_Error( 'novawork_expired', __( 'This page was open for a while. Please try again.', 'novawork' ) );
		}
		$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by fresh() above.
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
		$user = self::key_user();
		return is_wp_error( $user ) ? $user : null;
	}

	/**
	 * The user a reset link belongs to, or why it can't be used.
	 *
	 * @return \WP_User|WP_Error
	 */
	private static function key_user(): \WP_User|WP_Error {
		$key   = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The reset key is the proof.
		$login = isset( $_REQUEST['login'] ) ? sanitize_user( wp_unslash( $_REQUEST['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The reset key is the proof.
		$user  = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			return new WP_Error( 'novawork_bad_key', __( 'This link has expired or was already used. Ask for a new one.', 'novawork' ) );
		}
		return $user;
	}

	/**
	 * "Set a new password": save it with WordPress's reset_password().
	 */
	private static function reset(): WP_Error {
		$user = self::key_user();
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! self::fresh() ) {
			return new WP_Error( 'novawork_expired', __( 'This page was open for a while. Please try again.', 'novawork' ) );
		}
		$password = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed; the nonce is checked by fresh() above.
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
	 */
	private static function sign_in(): WP_Error {
		if ( ! self::fresh() ) {
			return new WP_Error( 'novawork_expired', __( 'This page was open for a while. Please try again.', 'novawork' ) );
		}
		$login    = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by fresh() above.
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed; the nonce is checked by fresh() above.
		if ( '' === $login || '' === $password ) {
			return new WP_Error( 'novawork_empty', __( 'Enter your email or username and your password.', 'novawork' ) );
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => ! empty( $_POST['rememberme'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by fresh() above.
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
		$hash = isset( $_POST['hash'] ) ? sanitize_key( wp_unslash( $_POST['hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by fresh() above.
		wp_safe_redirect( Route::url() . ( '' !== $hash ? '#' . $hash : '' ) );
		exit;
	}

	/**
	 * Print the page.
	 *
	 * @param string        $mode  "login", "lost" or "reset".
	 * @param WP_Error|null $error What went wrong (or, for "lost", the answer).
	 */
	private static function render( string $mode, ?WP_Error $error ): void {
		Assets::register();
		$done = $error && 'novawork_sent' === $error->get_error_code();
		$view = array(
			'mode'      => $mode,
			'name'      => Settings::workspace_name(),
			'error'     => $error && ! $done ? $error->get_error_message() : '',
			'sent'      => $done ? $error->get_error_message() : '',
			'badKey'    => $error && 'novawork_bad_key' === $error->get_error_code(),
			'badInvite' => $error && 'novawork_bad_invite' === $error->get_error_code(),
			'key'       => isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Carried into the form.
			'user'      => isset( $_REQUEST['login'] ) ? sanitize_user( wp_unslash( $_REQUEST['login'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Carried into the form.
			'wasReset'  => isset( $_GET['reset'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A message only.
			'loginUrl'  => Route::login_url(),
			'invite'    => self::invite(),
			'token'     => isset( $_REQUEST['invite'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['invite'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Carried into the form.
			'me'        => is_user_logged_in() ? wp_get_current_user()->display_name : '',
			'joinName'  => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only echoed back into the field.
			'joinEmail' => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only echoed back into the field.
			'login'     => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only echoed back into the field.
			'remember'  => ! empty( $_POST['rememberme'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only echoed back into the field.
			'signedOut' => isset( $_GET['signed-out'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A message only.
			'nonce'     => self::NONCE,
			'lost'      => add_query_arg( 'action', 'lost', Route::login_url() ),
		);
		if ( $error && 'novawork_refused' === $error->get_error_code() ) {
			status_header( 403 );
		}
		require NOVAWORK_PATH . 'views/login.php';
	}
}
