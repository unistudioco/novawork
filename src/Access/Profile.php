<?php
/**
 * The "NovaWork member" checkbox on a user's profile.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Access;

use NovaWork\Settings;
use WP_User;

/**
 * Owner decision: a user who already has a role (an Editor, say) becomes a
 * member through a checkbox on their profile (wp-admin → Users → Edit). It
 * grants the access capability to that one user and leaves their role alone,
 * because WordPress shows one role per user and switching would strip theirs.
 * Only administrators of NovaWork see and change it. Users whose role already
 * grants access (administrators, NovaWork Members) see it ticked and locked.
 */
final class Profile {

	private const NONCE = 'novawork_member';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'personal_options_update', array( self::class, 'save' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save' ) );
	}

	/**
	 * The role that grants access, or '' when none does.
	 *
	 * @param WP_User $user User.
	 */
	private static function granting_role( WP_User $user ): string {
		foreach ( $user->roles as $role ) {
			$object = get_role( $role );
			if ( $object && ! empty( $object->capabilities[ Capabilities::ACCESS ] ) ) {
				$names = wp_roles()->get_names();
				return translate_user_role( $names[ $role ] ?? $role );
			}
		}
		return '';
	}

	/**
	 * Print the setting on the profile screen.
	 *
	 * @param WP_User $user The user being edited.
	 */
	public static function render( WP_User $user ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$role    = self::granting_role( $user );
		$checked = '' !== $role || ! empty( $user->caps[ Capabilities::ACCESS ] );
		?>
		<h2><?php echo esc_html( Settings::workspace_name() ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'NovaWork member', 'novawork' ); ?></th>
				<td>
					<?php wp_nonce_field( self::NONCE, '_novawork_member_nonce' ); ?>
					<label for="novawork-member">
						<input type="checkbox" name="novawork_member" id="novawork-member" value="1" <?php checked( $checked ); ?> <?php disabled( '' !== $role ); ?>>
						<?php
						/* translators: %s: the workspace's name. */
						echo esc_html( sprintf( __( 'Can use %s', 'novawork' ), Settings::workspace_name() ) );
						?>
					</label>
					<p class="description">
						<?php
						if ( '' !== $role ) {
							/* translators: %s: a role name, such as Administrator. */
							echo esc_html( sprintf( __( 'Included with the %s role.', 'novawork' ), $role ) );
						} else {
							esc_html_e( 'Gives this user access to the workspace and keeps their role as it is.', 'novawork' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save it. An unticked box sends nothing, which means "not a member".
	 *
	 * @param int $user_id The user being saved.
	 */
	public static function save( int $user_id ): void {
		if ( ! isset( $_POST['_novawork_member_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_novawork_member_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || '' !== self::granting_role( $user ) ) {
			return;
		}
		if ( ! empty( $_POST['novawork_member'] ) ) {
			$user->add_cap( Capabilities::ACCESS );
		} else {
			$user->remove_cap( Capabilities::ACCESS );
		}
	}
}
