<?php
/**
 * Members: who is in the workspace, their colour, avatar and presence.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork;

use NovaWork\Access\Capabilities;
use NovaWork\Data\Db;

/**
 * Members are WordPress users with the access capability.
 */
final class Members {

	/**
	 * User meta: the member's colour key.
	 */
	public const COLOR_META = 'novawork_color';

	/**
	 * User meta: last time the member's app polled (epoch seconds).
	 */
	public const SEEN_META = 'novawork_last_seen';

	/**
	 * Every colour key a project or member may use (the prototype's palette).
	 */
	public const COLORS = array( 'blue', 'indigo', 'grape', 'violet', 'pink', 'rose', 'salmon', 'red', 'peach', 'amber', 'gold', 'sand', 'lime', 'mint', 'emerald', 'aqua', 'teal', 'sky', 'slate' );

	/**
	 * The eight accent pairs, handed out to members in turn.
	 */
	public const MEMBER_COLORS = array( 'violet', 'teal', 'amber', 'rose', 'sky', 'lime', 'red', 'gold' );

	/**
	 * Seconds since the last poll within which a member counts as online.
	 */
	public const ONLINE_WINDOW = 45;

	/**
	 * Every member's user id, oldest account first.
	 *
	 * @return list<int>
	 */
	public static function ids(): array {
		$ids = get_users(
			array(
				'capability' => Capabilities::ACCESS,
				'fields'     => 'ID',
				'orderby'    => 'ID',
				'order'      => 'ASC',
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Every member as the app shows them.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::ids() as $id ) {
			$member = self::present( $id );
			if ( $member ) {
				$out[] = $member;
			}
		}
		return $out;
	}

	/**
	 * One member as the app shows them.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>|null
	 */
	public static function present( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		$seen = (int) get_user_meta( $user_id, self::SEEN_META, true );
		return array(
			'id'       => $user_id,
			'name'     => $user->display_name,
			'color'    => self::color( $user_id ),
			'avatar'   => self::avatar_url( $user_id ),
			'lastSeen' => $seen ? $seen * 1000 : null,
			'online'   => $seen && ( time() - $seen ) <= self::ONLINE_WINDOW,
		);
	}

	/**
	 * A member's colour, assigned on first use.
	 *
	 * @param int $user_id User id.
	 */
	public static function color( int $user_id ): string {
		$color = (string) get_user_meta( $user_id, self::COLOR_META, true );
		if ( in_array( $color, self::COLORS, true ) ) {
			return $color;
		}
		$taken = array();
		foreach ( self::ids() as $id ) {
			if ( $id !== $user_id ) {
				$taken[] = (string) get_user_meta( $id, self::COLOR_META, true );
			}
		}
		$free  = array_values( array_diff( self::MEMBER_COLORS, $taken ) );
		$color = $free ? $free[0] : self::MEMBER_COLORS[ $user_id % count( self::MEMBER_COLORS ) ];
		update_user_meta( $user_id, self::COLOR_META, $color );
		return $color;
	}

	/**
	 * The member's Gravatar (or avatar plugin) URL, or '' to show initials.
	 *
	 * Asks for a 404 when the member has no Gravatar, so the app can fall back
	 * to their initial. Respects Settings → Discussion → Show Avatars.
	 *
	 * @param int $user_id User id.
	 */
	public static function avatar_url( int $user_id ): string {
		if ( ! get_option( 'show_avatars' ) ) {
			return '';
		}
		$url = get_avatar_url(
			$user_id,
			array(
				'size'    => 96,
				'default' => '404',
			)
		);
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Record that a member's app just polled. Writes at most every 15 seconds.
	 *
	 * @param int $user_id User id.
	 */
	public static function touch( int $user_id ): void {
		$seen = (int) get_user_meta( $user_id, self::SEEN_META, true );
		if ( time() - $seen >= 15 ) {
			update_user_meta( $user_id, self::SEEN_META, time() );
		}
	}

	/**
	 * Agents that can write in NovaWork, registered by add-ons.
	 *
	 * @return list<array<string, string>> Each with key, name and color.
	 */
	public static function agents(): array {
		/**
		 * Filters the agents (non-human authors) an add-on registers, such as
		 * an AI teammate. Each entry needs a unique "key", a "name" and a
		 * "color" from the NovaWork palette.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, mixed> $agents Agents.
		 */
		$agents = (array) apply_filters( 'novawork_agents', array() );
		$out    = array();
		foreach ( $agents as $agent ) {
			if ( ! is_array( $agent ) || empty( $agent['key'] ) ) {
				continue;
			}
			$color = (string) ( $agent['color'] ?? 'slate' );
			$out[] = array(
				'key'   => sanitize_key( (string) $agent['key'] ),
				'name'  => sanitize_text_field( (string) ( $agent['name'] ?? $agent['key'] ) ),
				'color' => in_array( $color, self::COLORS, true ) ? $color : 'slate',
			);
		}
		return $out;
	}

	/**
	 * Clean up after a user is deleted from WordPress.
	 *
	 * Their tasks stay; they are only removed as an assignee. Messages,
	 * comments and time entries stay as history, shown under a removed
	 * member, unless the site owner erases them (personal data eraser).
	 *
	 * @param int $user_id Deleted user id.
	 */
	public static function deleted( int $user_id ): void {
		( new Data\TaskRepository() )->unassign_everywhere( $user_id );
		$running = ( new Data\TimeEntryRepository() )->running_for( $user_id );
		if ( $running ) {
			( new Data\TimeEntryRepository() )->update(
				(int) $running['id'],
				array(
					'ended_at'     => Db::now(),
					'running_user' => null,
				)
			);
		}
	}
}
