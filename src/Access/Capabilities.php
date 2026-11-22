<?php
/**
 * Membership: the NovaWork Member role and capabilities.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Access;

defined( 'ABSPATH' ) || exit;

/**
 * A member is any WordPress user with the access capability: users given the
 * "NovaWork Member" role, plus administrators. Members are added and removed
 * in wp-admin → Users, never from inside the app.
 */
final class Capabilities {

	/**
	 * Use the workspace.
	 */
	public const ACCESS = 'novawork_access';

	/**
	 * Change NovaWork's settings.
	 */
	public const MANAGE = 'novawork_manage';

	/**
	 * The member role.
	 */
	public const ROLE = 'novawork_member';

	/**
	 * Create the role and grant administrators both capabilities.
	 */
	public static function install(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role(
				self::ROLE,
				'NovaWork Member',
				array(
					'read'       => true,
					self::ACCESS => true,
				)
			);
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::ACCESS );
			$admin->add_cap( self::MANAGE );
		}
	}

	/**
	 * Remove the role and the capabilities (used by uninstall).
	 */
	public static function remove(): void {
		remove_role( self::ROLE );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( self::ACCESS );
			$admin->remove_cap( self::MANAGE );
		}
	}

	/**
	 * Whether a member may record (or reopen) a decision: administrators
	 * always; otherwise the person named in "Who decides", or anyone when no
	 * one is named. The decide_rule setting "anyone" lets every member decide.
	 *
	 * @param array<string, mixed> $decision Decision row.
	 * @param int                  $user_id  Member.
	 */
	public static function can_decide( array $decision, int $user_id ): bool {
		if ( user_can( $user_id, self::MANAGE ) || 'anyone' === \NovaWork\Settings::decide_rule() ) {
			return true;
		}
		$owner = (int) $decision['owner_id'];
		return 0 === $owner || $owner === $user_id;
	}

	/**
	 * Whether a member may edit a chat message or comment: only their own,
	 * under the message_edits setting. An agent's words are never edited.
	 *
	 * @param array<string, mixed> $row     Message or comment row.
	 * @param int                  $user_id Member.
	 */
	public static function can_edit_post( array $row, int $user_id ): bool {
		if ( $user_id <= 0 || '' !== (string) $row['agent'] || (int) $row['user_id'] !== $user_id ) {
			return false;
		}
		return match ( \NovaWork\Settings::message_edits() ) {
			'never'  => false,
			'window' => (int) \NovaWork\Data\Db::to_ms( (string) $row['created_at'] ) >= ( time() - \NovaWork\Settings::int( 'edit_minutes' ) * MINUTE_IN_SECONDS ) * 1000,
			default  => true,
		};
	}

	/**
	 * Whether a member may delete a chat message or comment: their own, as
	 * for editing; administrators anyone's.
	 *
	 * @param array<string, mixed> $row     Message or comment row.
	 * @param int                  $user_id Member.
	 */
	public static function can_delete_post( array $row, int $user_id ): bool {
		return user_can( $user_id, self::MANAGE ) || self::can_edit_post( $row, $user_id );
	}

	/**
	 * Whether a user is a member.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_member( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, self::ACCESS );
	}

	/**
	 * Show the role's name translated in wp-admin → Users.
	 *
	 * WordPress stores role names untranslated and only translates core's.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $context     Gettext context.
	 * @param string $domain      Text domain.
	 */
	public static function translate_role_name( string $translation, string $text, string $context, string $domain ): string {
		if ( 'User role' === $context && 'default' === $domain && 'NovaWork Member' === $text ) {
			return __( 'NovaWork Member', 'novawork' );
		}
		return $translation;
	}
}
