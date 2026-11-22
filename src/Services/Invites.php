<?php
/**
 * Inviting people to the workspace.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Access\Capabilities;
use NovaWork\App\Route;
use NovaWork\Data\Db;
use NovaWork\Data\InviteRepository;
use NovaWork\Settings;
use WP_Error;
use WP_User;

/**
 * Owner decision (2026-11-22): there is no open sign-up. An administrator
 * creates an invite (for anyone with the link, or for one email address,
 * optionally emailed); the person joins on the workspace's own page.
 *
 * - A new person gets an account with the NovaWork Member role.
 * - Someone who already has an account signs in and gains access, keeping
 *   their role (as with the "NovaWork member" profile checkbox).
 * - Every invite works once and expires after `invite_days`.
 */
final class Invites {

	/**
	 * Create an invite. The link carries a token that is never stored, so it
	 * can only be shown now.
	 *
	 * @param string $email Who it is for ('' for anyone with the link).
	 * @param bool   $send  Email it to them.
	 * @param int    $actor The administrator.
	 * @return array{row: array<string, mixed>, link: string, sent: bool}|WP_Error
	 */
	public static function create( string $email, bool $send, int $actor ): array|WP_Error {
		$email = sanitize_email( $email );
		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'novawork_bad_email', __( 'That email address doesn’t look right.', 'novawork' ), array( 'status' => 400 ) );
		}
		if ( '' !== $email ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing && Capabilities::is_member( (int) $existing->ID ) ) {
				return new WP_Error( 'novawork_already_member', __( 'That person is already a member.', 'novawork' ), array( 'status' => 400 ) );
			}
		}
		$token = wp_generate_password( 32, false, false );
		$repo  = new InviteRepository();
		$id    = $repo->insert(
			array(
				'token_hash' => InviteRepository::hash( $token ),
				'email'      => $email,
				'created_by' => $actor,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + Settings::int( 'invite_days' ) * DAY_IN_SECONDS ),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'novawork_save_failed', __( 'The invite wasn’t created. Try again.', 'novawork' ), array( 'status' => 500 ) );
		}
		$link = self::link( $token );
		$sent = false;
		if ( $send && '' !== $email ) {
			$sent = self::send( $email, $link, $actor );
		}
		return array(
			'row'  => (array) $repo->find( $id ),
			'link' => $link,
			'sent' => $sent,
		);
	}

	/**
	 * The join page for a token.
	 *
	 * @param string $token Token.
	 */
	public static function link( string $token ): string {
		return add_query_arg(
			array(
				'action' => 'join',
				'invite' => $token,
			),
			Route::login_url()
		);
	}

	/**
	 * Email an invite.
	 *
	 * @param string $email Address.
	 * @param string $link  Join link.
	 * @param int    $actor Who invites.
	 */
	private static function send( string $email, string $link, int $actor ): bool {
		$who  = get_userdata( $actor );
		$name = Settings::workspace_name();
		/* translators: 1: a person's name, 2: the workspace's name. */
		$subject = sprintf( __( '%1$s invited you to %2$s', 'novawork' ), $who ? $who->display_name : __( 'Someone', 'novawork' ), $name );
		$days    = Settings::int( 'invite_days' );
		$message = sprintf(
			/* translators: 1: the workspace's name, 2: join link, 3: number of days. */
			_n(
				"You're invited to join %1\$s, where the team keeps its tasks, decisions and chat.\n\nJoin here:\n%2\$s\n\nThe link works once and for %3\$d day.",
				"You're invited to join %1\$s, where the team keeps its tasks, decisions and chat.\n\nJoin here:\n%2\$s\n\nThe link works once and for %3\$d days.",
				$days,
				'novawork'
			),
			$name,
			$link,
			$days
		);
		return (bool) wp_mail( $email, $subject, $message );
	}

	/**
	 * Use an invite for a user: they become a member, and the invite is spent.
	 *
	 * @param array<string, mixed> $invite Open invite row.
	 * @param WP_User              $user   The person joining.
	 */
	public static function accept( array $invite, WP_User $user ): void {
		if ( ! Capabilities::is_member( (int) $user->ID ) ) {
			$user->add_cap( Capabilities::ACCESS );
		}
		( new InviteRepository() )->update(
			(int) $invite['id'],
			array(
				'used_by' => (int) $user->ID,
				'used_at' => Db::now(),
			)
		);
		Activity::record( 'member_joined', (int) $user->ID, 'member', (int) $user->ID, array( 'title' => $user->display_name ) );

		/**
		 * Fires after someone joins the workspace with an invite.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_User              $user   The new member.
		 * @param array<string, mixed> $invite The invite row.
		 */
		do_action( 'novawork_member_joined', $user, $invite );
	}

	/**
	 * Create the account for a new person and use the invite.
	 *
	 * @param array<string, mixed> $invite   Open invite row.
	 * @param string               $name     Their name.
	 * @param string               $email    Their email.
	 * @param string               $password Their password.
	 * @return WP_User|WP_Error
	 */
	public static function join_new( array $invite, string $name, string $email, string $password ): WP_User|WP_Error {
		$name  = trim( sanitize_text_field( $name ) );
		$email = sanitize_email( '' !== (string) $invite['email'] ? (string) $invite['email'] : $email );
		if ( '' === $name ) {
			return new WP_Error( 'novawork_empty', __( 'Enter your name.', 'novawork' ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'novawork_bad_email', __( 'Enter your email address.', 'novawork' ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'novawork_has_account', __( 'There’s already an account for this email. Sign in below to join.', 'novawork' ) );
		}
		if ( '' === trim( $password ) ) {
			return new WP_Error( 'novawork_empty', __( 'Choose a password.', 'novawork' ) );
		}
		$errors = new WP_Error();
		/** This action is documented in wp-login.php (password policy plugins hook here). */
		do_action( 'validate_password_reset', $errors, new WP_User() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, fired on purpose.
		if ( $errors->has_errors() ) {
			return new WP_Error( 'novawork_weak', wp_strip_all_tags( $errors->get_error_message() ) );
		}
		$id = wp_insert_user(
			array(
				'user_login'   => self::username( $email ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => Capabilities::ROLE,
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$user = get_userdata( $id );
		if ( ! $user ) {
			return new WP_Error( 'novawork_save_failed', __( 'The account wasn’t created. Try again.', 'novawork' ) );
		}
		self::accept( $invite, $user );
		return $user;
	}

	/**
	 * A free username from an email address.
	 *
	 * @param string $email Email.
	 */
	private static function username( string $email ): string {
		$base = sanitize_user( strtok( $email, '@' ) ?: 'member', true ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- strtok() returns false for an empty string.
		$base = '' === $base ? 'member' : $base;
		$name = $base;
		$i    = 2;
		while ( username_exists( $name ) ) {
			$name = $base . $i;
			++$i;
		}
		return $name;
	}
}
