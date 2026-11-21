<?php
/**
 * Membership: the NovaWork Member role and capabilities.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Access;

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
